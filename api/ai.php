<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';

/* Посредник между приложением и Ollama.
 *
 * Зачем он вообще. До него ключ Ollama ехал внутри приложения, и это
 * не теория: распаковать .ipa и прочитать Info.plist — одна команда,
 * минута работы. Пока сборку видел один человек, риск был бумажный;
 * с публичной ссылкой TestFlight ключ читают все, кому её переслали,
 * а квоту оплачивает владелец. Здесь ключ остаётся на сервере, и в
 * приложении его нет вовсе.
 *
 * Ключ этой ручки — не секрет, и притворяться иначе нельзя: он тоже
 * лежит в бандле и достаётся тем же способом. Разница в том, что он
 * стоит дешевле. Его меняют одной строкой в файле, не трогая счёт
 * в Ollama и не выпуская новую сборку; а вместе с потолками ниже он
 * превращает «слил ключ — потерял деньги» в «пошумел и упёрся».
 *
 * Чего здесь намеренно нет — записи текста. Через эту ручку идёт
 * продиктованное человеком, то самое, про которое в политике сказано,
 * что оно уходит только с его согласия и только для ответа. Писать его
 * в журнал значило бы завести на сервере копию всего, что люди
 * говорили. В журнал идут длина, модель и код ответа — этого хватает,
 * чтобы понять, кто выбрал квоту, и не хватает, чтобы прочитать чужое.
 */

$dir = data_dir();

/* Пустой файл или его отсутствие означают «ручки нет» — так же, как
 * у админки и журнала. Выключается приём одной строкой, без правки кода. */
$appKey = is_file($dir . '/ai.key') ? trim((string) file_get_contents($dir . '/ai.key')) : '';
$upstream = is_file($dir . '/ollama.key') ? trim((string) file_get_contents($dir . '/ollama.key')) : '';
if ($appKey === '' || $upstream === '') { say(404, ['error' => 'Не найдено']); }

require_post();

/* Тело читаем сырым: подпись считается по тем самым байтам, что
 * подписал телефон. Разбор в массив и обратно даёт другую строку —
 * порядок ключей, пробелы, экранирование юникода, — и подпись
 * не сойдётся никогда. Та же причина, что в api/log.php. */
$len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($len > SCRIBLA_MAX_BODY) { say(413, ['error' => 'Слишком длинно']); }

$raw = (string) file_get_contents('php://input', false, null, 0, SCRIBLA_MAX_BODY + 1);
if (strlen($raw) > SCRIBLA_MAX_BODY) { say(413, ['error' => 'Слишком длинно']); }

$sign = (string) ($_SERVER['HTTP_X_SCRIBLA_SIGN'] ?? '');
if (!hash_equals(hash_hmac('sha256', $raw, $appKey), $sign)) {
    say(401, ['error' => 'Нет']);
}

$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) { say(400, ['error' => 'Не разобрали запрос']); }

/* ------------------------------------------------------------- потолки
 *
 * Два разных потолка, потому что защищают они от разного.
 *
 * По адресу — от одной пары рук, которая нашла ключ и решила погонять
 * модель бесплатно. Тридцать запросов за десять минут человек в диктовке
 * не выберет: ответ модели ждут секунды, а не миллисекунды.
 *
 * Общий дневной — от толпы. Если ключ утечёт в чат на тысячу человек,
 * потолок по адресу не спасёт: адресов будет тысяча. Дневной предел
 * упирается в квоту раньше, чем в неё упрётся счёт, и владелец узнаёт
 * об этом из журнала, а не из письма Ollama.
 */
$ip = client_ip();
if (too_often('ai:' . $ip, 30, 600)) {
    say(429, ['error' => 'Слишком часто. Подождите минуту.']);
}

$dayFile = $dir . '/ai-day.json';
$today = gmdate('Y-m-d');
$day = is_file($dayFile) ? json_decode((string) file_get_contents($dayFile), true) : null;
if (!is_array($day) || ($day['date'] ?? '') !== $today) { $day = ['date' => $today, 'n' => 0]; }
if ($day['n'] >= 2000) {
    say(429, ['error' => 'Дневной предел исчерпан. Попробуйте завтра.']);
}
$day['n']++;
@file_put_contents($dayFile, json_encode($day), LOCK_EX);

/* --------------------------------------------------------- что пропускаем
 *
 * Ровно две формы запроса, обе — те, что делает приложение. Всё
 * остальное отбиваем, не пытаясь угадать: открытый ретранслятор
 * к платной модели находят чужие люди за считанные дни, и дальше
 * он работает не на нас.
 */
$kind = (string) ($in['kind'] ?? '');

if ($kind === 'chat') {
    /* Модель называет приложение — значит назвать её может и посторонний.
     * Тяжёлая модель ест квоту в разы быстрее лёгкой, поэтому список
     * закрытый: те же имена, что в BuiltInAIClient (основная и запасные).
     * Новая модель в приложении требует строки здесь — это неудобно
     * ровно один раз и защищает от чужого выбора всегда. */
    $allowed = ['gemma4:cloud', 'mistral-large-3:675b-cloud', 'qwen3.5:cloud'];
    $model = (string) ($in['model'] ?? '');
    if (!in_array($model, $allowed, true)) {
        say(400, ['error' => 'Модель не поддерживается']);
    }

    $messages = $in['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        say(400, ['error' => 'Пустой запрос']);
    }

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => min(4096, max(16, (int) ($in['max_tokens'] ?? 512))),
        'temperature' => min(2.0, max(0.0, (float) ($in['temperature'] ?? 0.2))),
        'stream' => false,
    ];
    $url = 'https://ollama.com/v1/chat/completions';

} elseif ($kind === 'search') {
    $query = trim((string) ($in['query'] ?? ''));
    if ($query === '') { say(400, ['error' => 'Пустой запрос']); }

    $payload = [
        'query' => mb_substr($query, 0, 400),
        'max_results' => min(10, max(1, (int) ($in['max_results'] ?? 3))),
    ];
    $url = 'https://ollama.com/api/web_search';

} else {
    say(400, ['error' => 'Неизвестный вид запроса']);
}

/* ----------------------------------------------------------- пересылка */

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $upstream,
    ],
    CURLOPT_RETURNTRANSFER => true,
    // Больше, чем ждёт телефон: пусть лучше он оборвёт сам, чем мы
    // отдадим ему пустоту, когда модель почти ответила.
    CURLOPT_TIMEOUT => 90,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$answer = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

/* В журнал — счётчики, не содержимое. Длина запроса и ответа говорят,
 * кто выбирает квоту; сам текст остаётся у человека. */
store('ai.jsonl', [
    'at' => gmdate('c'),
    'kind' => $kind,
    'model' => $kind === 'chat' ? $payload['model'] : null,
    'in' => strlen($raw),
    'out' => is_string($answer) ? strlen($answer) : 0,
    'code' => $code,
    'day' => $day['n'],
]);

if ($answer === false) {
    error_log('scribla ai: ' . $curlError);
    say(502, ['error' => 'Сервис модели не ответил']);
}

/* Ответ отдаём как есть: приложение разбирает формат OpenAI, и любая
 * наша переупаковка стала бы вторым местом, где этот формат описан. */
http_response_code($code === 0 ? 502 : $code);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo $answer;
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
