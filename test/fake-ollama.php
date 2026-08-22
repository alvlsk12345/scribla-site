<?php
declare(strict_types=1);

/* Поддельный Ollama для стенда режима AI.
 *
 * Настоящий стоит денег, отвечает по-разному на один и тот же вопрос
 * и требует сети — на нём нельзя проверить ни отказ поиска, ни снятую
 * с раздачи модель, ни то, КАКАЯ инструкция ушла в модель. А проверять
 * надо именно инструкцию: вся история 14 августа 2026 в том и состояла,
 * что приложение говорило модели неправду о себе, а выглядело это как
 * поломка поиска.
 *
 * Поведение задаётся файлом `mode` в каталоге стенда, запросы пишутся
 * туда же файлами `chat-N.json` и `search-N.json` — стенд их читает
 * и проверяет, что именно мы отправили.
 */

$dir = getenv('SCRIBLA_FAKE') ?: sys_get_temp_dir();
$mode = is_file($dir . '/mode') ? trim((string) file_get_contents($dir . '/mode')) : 'ok';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = (string) file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) { $in = []; }

/** Пишет запрос в каталог стенда под своим номером. */
$record = static function (string $what, array $body) use ($dir): void {
    $n = count(glob($dir . '/' . $what . '-*.json') ?: []) + 1;
    file_put_contents($dir . '/' . $what . '-' . $n . '.json',
                      json_encode($body, JSON_UNESCAPED_UNICODE));
};

$reply = static function (int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
};

if (str_ends_with($path, '/api/web_search')) {
    $record('search', $in);

    if ($mode === 'search-fails') { $reply(500, ['error' => 'нет']); return true; }
    if ($mode === 'search-empty') { $reply(200, ['results' => []]); return true; }
    if ($mode === 'search-slow') { sleep(20); }

    /* Выдача помечена запросом: так стенд видит, какая страница пришла
     * по вопросу как есть, а какая — по вопросу с датой, и не путает их
     * между собой при проверке склейки. */
    $q = (string) ($in['query'] ?? '');
    $dated = preg_match('/\d{4}/', $q) === 1;
    $tag = $dated ? 'дата' : 'вопрос';
    $results = [];
    for ($i = 1; $i <= (int) ($in['max_results'] ?? 3); $i++) {
        $results[] = [
            'title' => "страница {$tag} {$i}",
            'url' => "https://пример.тест/{$tag}/{$i}",
            'content' => "содержимое страницы {$tag} {$i}. Курс на этот день — 8{$i},15 рубля.",
        ];
    }
    /* Первая страница у обеих выдач одна и та же — на ней проверяется,
     * что склейка не пускает повтор дважды. */
    $results[0] = [
        'title' => 'общая страница',
        'url' => 'https://пример.тест/общая',
        'content' => 'общее содержимое для обоих запросов',
    ];
    $reply(200, ['results' => $results]);
    return true;
}

if (str_ends_with($path, '/v1/chat/completions')) {
    $record('chat', $in);
    $model = (string) ($in['model'] ?? '');

    if ($mode === 'retired' && $model === 'gemma4:cloud') { $reply(404, ['error' => 'model not found']); return true; }
    if ($mode === 'gone' && $model !== 'gpt-oss:120b-cloud') { $reply(410, ['error' => 'gone']); return true; }
    if ($mode === 'chat-fails') { $reply(500, ['error' => 'нет']); return true; }

    /* Перегрузка раздачи. Настоящая Ollama отвечает на неё 503 и просит
     * прийти ещё раз — вот этими словами. Два режима, потому что беда
     * бывает двух видов: мгновенная (помогает повтор той же моделью)
     * и затяжная (помогает только другая модель — 20 августа 2026
     * две просьбы пришли с промежутком в 28 секунд). */
    if ($mode === 'overloaded' && $model === 'gemma4:cloud') {
        $reply(503, ['error' => "model 'gemma4:31b' is temporarily overloaded, please retry"]);
        return true;
    }
    if ($mode === 'overloaded-once') {
        $flag = $dir . '/overloaded-once.json';
        if (!is_file($flag)) {
            file_put_contents($flag, '1');
            $reply(503, ['error' => "model 'gemma4:31b' is temporarily overloaded, please retry"]);
            return true;
        }
    }
    if ($mode === 'chat-empty') {
        $reply(200, ['choices' => [['message' => ['content' => '']]]]);
        return true;
    }
    if ($mode === 'cut') {
        /* Ответ, упёршийся в потолок: последнее предложение оборвано
         * посреди слова, и `finish_reason` говорит об этом честно. */
        $reply(200, ['choices' => [[
            'message' => ['content' => 'Первое предложение целое. Второе тоже целое. А третье оборвалось на полусло'],
            'finish_reason' => 'length',
        ]]]);
        return true;
    }
    if ($mode === 'thinking') {
        $reply(200, ['choices' => [['message' => ['content' => "<think>долго думаю</think>ответ после размышления"]]]]);
        return true;
    }

    $reply(200, ['choices' => [['message' => ['content' => 'ответ модели ' . $model]]]]);
    return true;
}

http_response_code(404);
echo 'нет такой ручки';
return true;
