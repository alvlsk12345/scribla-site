<?php
declare(strict_types=1);

/* Стенд режима AI.
 *
 * Проверяет он две разные вещи, и вторая важнее первой.
 *
 * Первая — новая ручка «ask»: поиск, склейка выдач, дата в запросе,
 * перебор снятых с раздачи моделей и — главное — КАКАЯ инструкция ушла
 * в модель. Вся история 14 августа 2026 состояла в том, что приложение
 * говорило модели «интернета у тебя нет» всякий раз, когда поиск
 * не дошёл, а модель покорно повторяла это человеку. Проверять такое
 * по ответу модели нельзя — он каждый раз разный; проверять надо
 * по тексту, который мы отправили. Поддельный Ollama записывает
 * запросы, и стенд читает их.
 *
 * Вторая — что старые «chat» и «search» отвечают ровно как прежде.
 * Ими работают все сборки, уже разошедшиеся по телефонам, и та, что
 * лежит на проверке в App Store. Ручка, которая ломает вчерашние
 * сборки, ломает их молча и в чужих руках: человек видит «AI не ответил»
 * и никакого следа, а мы — зелёный стенд на новом пути.
 */

$tmp = sys_get_temp_dir() . '/scribla-ask-' . getmypid();
$fakeDir = $tmp . '/fake';
@mkdir($tmp, 0700, true);
@mkdir($fakeDir, 0700, true);

$appKey = 'ключ-приложения-' . bin2hex(random_bytes(4));
file_put_contents($tmp . '/ai.key', $appKey);
file_put_contents($tmp . '/ollama.key', 'ключ-ollama');

/* Свой диапазон, не пересекающийся с прочими стендами: у сводки
 * 23000–23999, у журнала 22000-е, у почты 21000-е. Совпадение портов
 * даёт не понятный отказ, а шестнадцать несошедшихся проверок в стенде,
 * который к делу не относится вовсе. */
$sitePort = random_int(24000, 24499);
$fakePort = random_int(24500, 24999);

/* Окружение ДОПОЛНЯЕМ, а не заменяем: с одной своей переменной сервер
 * остаётся без PATH и не поднимается вовсе — тихо, а стенд при этом
 * просто ждёт таймаута на каждом запросе. */
$fake = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $fakePort, '-t', __DIR__ . '/..', __DIR__ . '/fake-ollama.php'],
    [1 => ['file', $tmp . '/fake.log', 'w'], 2 => ['file', $tmp . '/fake.log', 'a']],
    $fakePipes, null,
    ['SCRIBLA_FAKE' => $fakeDir, 'PHP_CLI_SERVER_WORKERS' => '4'] + getenv()
);

$site = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $sitePort, '-t', __DIR__ . '/..', __DIR__ . '/router.php'],
    [1 => ['file', $tmp . '/site.log', 'w'], 2 => ['file', $tmp . '/site.log', 'a']],
    $sitePipes, null,
    [
        'SCRIBLA_DATA' => $tmp,
        'SCRIBLA_UPSTREAM' => 'http://127.0.0.1:' . $fakePort,
        'PHP_CLI_SERVER_WORKERS' => '4',
    ] + getenv()
);
usleep(700000);

$fail = 0;
$ok = static function (string $what, bool $good) use (&$fail): void {
    if (!$good) { $fail++; }
    echo ($good ? '  ok  ' : '  НЕТ ') . $what . "\n";
};

/** Запрос к нашей ручке с подписью того же вида, что шлёт телефон. */
$post = static function (array $body, ?string $key = null) use ($sitePort, $appKey): array {
    $raw = json_encode($body, JSON_UNESCAPED_UNICODE);
    $ch = curl_init('http://127.0.0.1:' . $sitePort . '/api/ai');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $raw,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Scribla-Sign: ' . hash_hmac('sha256', $raw, $key ?? $appKey),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
    ]);
    $out = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode($out, true), $out];
};

/** Задаёт поведение поддельного Ollama и стирает записи прошлого случая. */
$scene = static function (string $mode) use ($fakeDir): void {
    foreach (glob($fakeDir . '/*.json') ?: [] as $f) { @unlink($f); }
    file_put_contents($fakeDir . '/mode', $mode);
};

/** Последняя инструкция, ушедшая в модель. */
$lastSystem = static function () use ($fakeDir): string {
    $files = glob($fakeDir . '/chat-*.json') ?: [];
    if (!$files) { return ''; }
    sort($files);
    $body = json_decode((string) file_get_contents(end($files)), true);
    return (string) ($body['messages'][0]['content'] ?? '');
};

/** Все запросы, ушедшие в поиск.
 *
 * Порядок здесь НЕ определён: оба запроса уходят разом, и кто из них
 * ляжет первым файлом, решает поддельный сервер. Проверять поэтому
 * надо набор целиком, а не позицию в нём — иначе стенд зеленеет
 * через раз, а это хуже красного. */
$searches = static function () use ($fakeDir): array {
    $out = [];
    foreach (glob($fakeDir . '/search-*.json') ?: [] as $f) {
        $body = json_decode((string) file_get_contents($f), true);
        $out[] = (string) ($body['query'] ?? '');
    }
    return $out;
};

$base = [
    'kind' => 'ask',
    'question' => 'какой сегодня курс доллара',
    'lang' => 'ru',
    'date' => '2026-08-14',
    'markdown' => false,
    'search' => true,
];

echo "— старые пути (ими работает сборка на проверке)\n";

$scene('ok');
[$code, $json] = $post(['kind' => 'chat', 'model' => 'gemma4:cloud',
                        'messages' => [['role' => 'user', 'content' => 'привет']]]);
$ok('chat отвечает 200', $code === 200);
$ok('chat отдаёт ответ модели как есть',
    ($json['choices'][0]['message']['content'] ?? '') === 'ответ модели gemma4:cloud');

[$code] = $post(['kind' => 'chat', 'model' => 'чужая-модель',
                 'messages' => [['role' => 'user', 'content' => 'привет']]]);
$ok('chat отбивает чужую модель', $code === 400);

[$code, $json] = $post(['kind' => 'search', 'query' => 'проба', 'max_results' => 3]);
$ok('search отвечает 200 и отдаёт выдачу', $code === 200 && count($json['results'] ?? []) === 3);

[$code] = $post(['kind' => 'ask', 'question' => 'проба'], 'не-тот-ключ');
$ok('чужая подпись — 401', $code === 401);

[$code] = $post(['kind' => 'неизвестно']);
$ok('неизвестный вид — 400', $code === 400);

echo "— новая ручка: положение поиска и текст инструкции\n";

$scene('ok');
[$code, $json] = $post($base);
$system = $lastSystem();
$ok('нашлось — 200 с текстом', $code === 200 && ($json['text'] ?? '') !== '');
$ok('нашлось — состояние found', ($json['trace']['state'] ?? '') === 'found');
$ok('нашлось — инструкция велит отвечать по страницам',
    str_contains($system, 'отвечай по ним'));
$ok('нашлось — про отсутствие интернета ни слова',
    !str_contains($system, 'Интернета у тебя нет'));
$ok('нашлось — страницы приписаны к инструкции',
    str_contains($system, 'НАЙДЕНО В ИНТЕРНЕТЕ'));
$ok('нашлось — источники вернулись наверх', count($json['sources'] ?? []) === 5);
$ok('нашлось — редакция инструкции названа', ($json['trace']['prompt'] ?? 0) >= 3);

$scene('search-fails');
[$code, $json] = $post($base);
$system = $lastSystem();
$ok('поиск не дошёл — всё равно 200 с ответом', $code === 200 && ($json['text'] ?? '') !== '');
$ok('поиск не дошёл — состояние failed', ($json['trace']['state'] ?? '') === 'failed');
$ok('поиск не дошёл — модели НЕ говорят, что интернета нет',
    !str_contains($system, 'Интернета у тебя нет'));
$ok('поиск не дошёл — сказано, что страницы достать не удалось',
    str_contains($system, 'достать не удалось'));
$ok('поиск не дошёл — запрещено говорить о своём доступе',
    str_contains($system, 'Про интернет и свой доступ к нему не говори'));

$scene('search-empty');
[$code, $json] = $post($base);
$ok('поиск вернул пустоту — тоже failed', ($json['trace']['state'] ?? '') === 'failed');

$scene('ok');
[$code, $json] = $post(array_merge($base, ['search' => false]));
$system = $lastSystem();
$ok('поиск выключен — состояние off', ($json['trace']['state'] ?? '') === 'off');
$ok('поиск выключен — «интернета нет» сказано честно',
    str_contains($system, 'Интернета у тебя нет'));
$ok('поиск выключен — в поиск не ходили вовсе', $searches() === []);

echo "— уточнение прошлого ответа\n";

$scene('ok');
[$code, $json] = $post(array_merge($base, [
    'question' => 'а в евро сколько',
    'previous' => [
        'question' => 'какой сегодня курс доллара',
        'answer' => 'Официальный курс ЦБ на 14 августа 2026 года — 81,42 рубля.',
        'sources' => [['title' => 'ЦБ РФ', 'url' => 'https://cbr.ru']],
    ],
]));
$system = $lastSystem();
$ok('уточнение — состояние refine', ($json['trace']['state'] ?? '') === 'refine');
$ok('уточнение — заново не ищем', $searches() === []);
$ok('уточнение — прошлый обмен приложен', str_contains($system, 'ПРЕДЫДУЩИЙ ОБМЕН'));
$ok('уточнение — про отсутствие интернета ни слова',
    !str_contains($system, 'Интернета у тебя нет'));
$ok('уточнение — запрет выдумывать числа',
    str_contains($system, 'НЕ ВЫДУМЫВАЙ новых чисел'));
$ok('уточнение — источники прошлого ответа сохранены',
    ($json['sources'][0]['url'] ?? '') === 'https://cbr.ru');

echo "— текст из поля\n";

$scene('ok');
[$code, $json] = $post(array_merge($base, [
    'question' => 'ответь на это',
    'field' => ['before' => 'результаты матчей РПЛ за 9 августа', 'after' => ''],
]));
$system = $lastSystem();
$ok('текст поля приложен к инструкции', str_contains($system, 'ТЕКСТ В ПОЛЕ'));
$ok('курсор отмечен', str_contains($system, '⟨курсор⟩'));
$queries = $searches();
$ok('ищем по тексту поля, а не по «ответь на это»',
    $queries !== [] && str_contains($queries[0], 'результаты матчей РПЛ'));
$ok('по сказанному не ищем вовсе',
    $queries !== [] && !str_contains($queries[0], 'ответь на это'));

echo "— дата в поисковом запросе\n";

$scene('ok');
$post(array_merge($base, ['question' => 'какие матчи вчера игрались в РПЛ']));
$queries = $searches();
$all = implode(' | ', $queries);
$ok('«вчера» ищется вчерашним числом',
    count($queries) === 2 && str_contains($all, '13 августа 2026')
    && !str_contains($all, '14 августа 2026'));

$scene('ok');
$post(array_merge($base, ['question' => 'во сколько завтрак в отеле']));
$queries = $searches();
$all = implode(' | ', $queries);
$ok('«завтрак» не уезжает на завтра',
    count($queries) === 2 && str_contains($all, '14 августа 2026')
    && !str_contains($all, '15 августа 2026'));

$scene('ok');
$post(array_merge($base, ['question' => 'what is the weather tomorrow', 'lang' => 'en']));
$queries = $searches();
$all = implode(' | ', $queries);
$ok('английское «tomorrow» — завтрашним числом и по-английски',
    count($queries) === 2 && str_contains($all, '15 August 2026'));

echo "— склейка выдач и запасные модели\n";

$scene('ok');
[$code, $json] = $post($base);
$ok('склейка не пускает повтор дважды', count($json['sources'] ?? []) === 5);
$urls = array_column($json['sources'] ?? [], 'url');
$ok('повторов среди источников нет', count($urls) === count(array_unique($urls)));
$ok('в склейке есть страницы обеих выдач',
    (bool) preg_grep('#/вопрос/#', $urls) && (bool) preg_grep('#/дата/#', $urls));

$scene('retired');
[$code, $json] = $post($base);
$ok('снятую с раздачи модель заменяет запасная',
    $code === 200 && ($json['trace']['model'] ?? '') === 'mistral-large-3:675b-cloud');

$scene('gone');
[$code, $json] = $post($base);
$ok('перебор доходит до последней запасной',
    $code === 200 && ($json['trace']['model'] ?? '') === 'gpt-oss:120b-cloud');

$scene('chat-empty');
[$code, $json] = $post($base);
$ok('пустой ответ модели — 502 с объяснением',
    $code === 502 && str_contains((string) ($json['error'] ?? ''), 'пустотой'));

$scene('thinking');
[$code, $json] = $post($base);
$ok('размышления срезаны', ($json['text'] ?? '') === 'ответ после размышления');

echo "— язык, оформление, счёт квоты\n";

$scene('ok');
$post(array_merge($base, ['lang' => 'en']));
$system = $lastSystem();
$ok('английский интерфейс — английская инструкция',
    str_contains($system, 'You are answering a question that was dictated aloud'));
$ok('английская инструкция не смешана с русской',
    !str_contains($system, 'Ты отвечаешь на вопрос'));

$scene('ok');
$post(array_merge($base, ['markdown' => true]));
$ok('markdown просят, когда он включён', str_contains($lastSystem(), 'Оформи ответ в Markdown'));

$scene('ok');
$post(array_merge($base, ['markdown' => false]));
$ok('без markdown — просят обычный текст', str_contains($lastSystem(), 'Пиши обычным текстом'));

$before = json_decode((string) @file_get_contents($tmp . '/ai-day.json'), true)['n'] ?? 0;
$scene('ok');
$post($base);
$after = json_decode((string) @file_get_contents($tmp . '/ai-day.json'), true)['n'] ?? 0;
$ok('один вопрос с поиском стоит трёх походов наружу', $after - $before === 3);

$rows = array_filter(explode("\n", (string) @file_get_contents($tmp . '/ai.jsonl')));
$last = json_decode((string) end($rows), true);
$ok('в журнале длины, а не содержимое',
    is_array($last) && ($last['kind'] ?? '') === 'ask'
    && isset($last['q'], $last['a'], $last['state'])
    && !isset($last['question'], $last['text']));

echo "— правка инструкции без выкладки\n";

file_put_contents($tmp . '/prompt.json', json_encode(
    ['knowledge_off_ru' => 'ЗАМЕНЁННЫЙ КУСОК ИНСТРУКЦИИ'], JSON_UNESCAPED_UNICODE));
$scene('ok');
$post(array_merge($base, ['search' => false]));
$ok('override замещает кусок инструкции',
    str_contains($lastSystem(), 'ЗАМЕНЁННЫЙ КУСОК ИНСТРУКЦИИ'));
@unlink($tmp . '/prompt.json');

// ------------------------------------------------------------- уборка

foreach ([$site, $fake] as $p) {
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
$rm = static function (string $d) use (&$rm): void {
    foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? $rm($f) : @unlink($f); }
    @rmdir($d);
};
$rm($tmp);

echo $fail === 0 ? "стенд режима AI: всё чисто\n" : "стенд режима AI: провалов — {$fail}\n";
exit($fail === 0 ? 0 : 1);
