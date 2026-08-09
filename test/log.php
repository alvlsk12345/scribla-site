<?php
declare(strict_types=1);

/* Стенд ручки приёма журналов.
 *
 * Ручка небольшая, но у неё четыре отказа, каждый из которых молчалив
 * по-своему. Неверная подпись, отсутствующий ключ, чужой формат метки,
 * перевод строки внутри сообщения — ни один из них не виден ни в браузере,
 * ни в приложении: телефон просто оставит пачку у себя и придёт завтра.
 * Заметить это можно было бы через неделю по пустому каталогу.
 *
 * Поэтому гоняем настоящий HTTP через тот же router.php, что и остальные
 * ручки: встроенный сервер PHP, временный каталог данных вместо боевого.
 */

$tmp = sys_get_temp_dir() . '/scribla-log-' . getmypid();
@mkdir($tmp, 0700, true);

$port = random_int(22000, 22999);
$key  = 'проверочный-ключ-' . bin2hex(random_bytes(4));

/* Окружение ДОПОЛНЯЕМ, а не заменяем: с одной своей переменной сервер
 * остаётся без PATH и не поднимается вовсе — тихо, а стенд при этом
 * просто ждёт таймаута на каждом запросе. Вывод сервера уводим в файл,
 * а не в канал: непрочитанный канал переполняется и вешает процесс. */
$srv = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', __DIR__ . '/..', __DIR__ . '/router.php'],
    [1 => ['file', $tmp . '/server.log', 'w'], 2 => ['file', $tmp . '/server.log', 'a']],
    $pipes,
    null,
    ['SCRIBLA_DATA' => $tmp] + getenv()
);
usleep(600000);

$fail = 0;
$ok = static function (string $what, bool $good) use (&$fail): void {
    if (!$good) { $fail++; }
    echo ($good ? '  ok  ' : '  НЕТ ') . $what . "\n";
};

/** Запрос к ручке. Подпись считается по тем же байтам, что уходят. */
$post = static function (array $payload, ?string $signWith, string $port): array {
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json'];
    if ($signWith !== null) {
        $headers[] = 'X-Scribla-Sign: ' . hash_hmac('sha256', $raw, $signWith);
    }
    $ch = curl_init('http://127.0.0.1:' . $port . '/api/log');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $raw,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode($body, true) ?: []];
};

$install = strtoupper(bin2hex(random_bytes(8)));
$pack = static fn(array $entries): array => [
    'install' => $GLOBALS['install'] ?? '',
    'app' => '0.9 (1)', 'os' => '19.0', 'device' => 'iPhone17,1', 'locale' => 'ru',
    'entries' => $entries,
];
$GLOBALS['install'] = $install;

echo "журнал\n";

/* Без ключа на сервере ручки не существует вовсе — тем же способом,
 * что у админки. Так приём выключается, не трогая код. */
[$code] = $post($pack([['message' => 'проба', 'source' => 'приложение']]), $key, (string) $port);
$ok('без файла с ключом ручки нет (404), получили ' . $code, $code === 404);

file_put_contents($tmp . '/log.key', $key);

/* Ключ лежит в приложении, а приложение раздаётся всем — подпись отсекает
 * ботов, а не злоумышленника. Но ботов отсекать обязана. */
[$code] = $post($pack([['message' => 'проба']]), null, (string) $port);
$ok('без подписи не принимает (401), получили ' . $code, $code === 401);

[$code] = $post($pack([['message' => 'проба']]), 'чужой-ключ', (string) $port);
$ok('с чужой подписью не принимает (401), получили ' . $code, $code === 401);

/* Метка установки попадает в файл, и мусор в ней мешает потом искать. */
[$code] = $post(['install' => 'не метка вовсе', 'entries' => [['message' => 'проба']]], $key, (string) $port);
$ok('чужой формат метки не принимает (400), получили ' . $code, $code === 400);

[$code] = $post($pack([]), $key, (string) $port);
$ok('пустую пачку не принимает (400), получили ' . $code, $code === 400);

/* Настоящая пачка. */
[$code, $answer] = $post($pack([
    ['at' => '2026-08-09T04:00:00Z', 'source' => 'приложение', 'message' => 'Микрофон заряжен, 48000 Гц'],
    ['at' => '2026-08-09T04:00:01Z', 'source' => 'клавиатура', 'message' => 'Открылась, отметка записана'],
    ['at' => '2026-08-09T04:00:02Z', 'source' => 'приложение', 'message' => ''],
]), $key, (string) $port);

$ok('правильная пачка принята (200), получили ' . $code, $code === 200);
$ok('пустое сообщение не записано: ' . ($answer['stored'] ?? '—'), ($answer['stored'] ?? 0) === 2);

$file = $tmp . '/logs/' . gmdate('Y-m-d') . '.jsonl';
$lines = is_file($file) ? array_filter(explode("\n", (string) file_get_contents($file))) : [];
$rows = array_map(static fn(string $l): array => json_decode($l, true) ?: [], $lines);

$ok('файл за сегодня заведён', count($rows) === 2);
$ok('метка установки в каждой строке',
    $rows !== [] && array_column($rows, 'install') === [$install, $install]);
$ok('версия сборки доехала', ($rows[0]['app'] ?? '') === '0.9 (1)');
$ok('адрес отправителя не пишется — его тут быть не должно',
    $rows !== [] && !array_key_exists('ip', $rows[0]));

/* Перевод строки внутри сообщения разорвал бы строку JSONL надвое,
 * и файл перестал бы разбираться с этой записи и до конца дня. */
[$code] = $post($pack([['message' => "первая строка\nвторая строка\tи табуляция"]]), $key, (string) $port);
$ok('перенос строки принят (200), получили ' . $code, $code === 200);

$lines = array_filter(explode("\n", (string) file_get_contents($file)));
$last = json_decode((string) end($lines), true) ?: [];
$ok('и склеен в одну строку: ' . ($last['message'] ?? '—'),
    ($last['message'] ?? '') === 'первая строка вторая строка и табуляция');
$ok('файл по-прежнему разбирается построчно', count($lines) === 3);

/* Длинное сообщение режется: журнал — это записи о работе, а не текст. */
[$code] = $post($pack([['message' => str_repeat('я', 3000)]]), $key, (string) $port);
$lines = array_filter(explode("\n", (string) file_get_contents($file)));
$last = json_decode((string) end($lines), true) ?: [];
$ok('слишком длинное обрезано до 1000 символов: ' . mb_strlen($last['message'] ?? '', 'UTF-8'),
    mb_strlen($last['message'] ?? '', 'UTF-8') === 1000);

/* Сервер сам не кончится: он на то и сервер. Без `proc_terminate`
 * стенд повисает на закрытии — все проверки уже пройдены, а прогон
 * не возвращается, и снаружи это выглядит поломкой стенда. */
proc_terminate($srv);
proc_close($srv);
exec('rm -rf ' . escapeshellarg($tmp));

if ($fail) { echo "\nжурнал: не сошлось — $fail\n"; exit(1); }
echo "журнал: все проверки сошлись.\n";
