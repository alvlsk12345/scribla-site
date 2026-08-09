<?php
declare(strict_types=1);

/* Стенд почты: письмо доходит целиком и разбирается обратно.
 *
 * Ошибки в MIME молчаливы — письмо уходит, а у получателя вместо темы
 * «=?UTF-8?B?...» и вложение в ноль байт. Узнать об этом можно только
 * от человека, который уже увидел кривое письмо. Поэтому проверяем
 * машиной: собираем, отправляем на поддельный сервер и разбираем.
 */

$tmp = sys_get_temp_dir() . '/scribla-mail-' . getmypid();
@mkdir($tmp, 0700, true);
putenv('SCRIBLA_DATA=' . $tmp);

require __DIR__ . '/../api/lib/http.php';
require __DIR__ . '/../api/lib/mailer.php';

$port = random_int(21000, 21999);
$got  = $tmp . '/got.json';

file_put_contents($tmp . '/mail.json', json_encode([
    'host' => '127.0.0.1', 'port' => $port, 'secure' => 'plain',
    'user' => 'robot@scribla.io', 'pass' => 'тайна',
    'from_name' => 'Scribla', 'to' => 'hi@example.com, second@example.com',
], JSON_UNESCAPED_UNICODE));

$srv = proc_open(
    [PHP_BINARY, __DIR__ . '/fake-smtp.php', (string) $port, $got],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
);
/* Ждём слова от сервера, а не «примерно полсекунды».
 *
 * Фиксированной паузы хватало ровно до тех пор, пока машина была
 * свободна. Под нагрузкой (рядом шла сборка Xcode) PHP не успевал
 * подняться за 400 мс, стенд падал с «Connection refused» и выглядело
 * это как поломка почты — то есть искать её начинали в MIME.
 * Ошибка стенда, притворяющаяся ошибкой продукта, обходится дороже всех.
 */
stream_set_timeout($pipes[1], 10);
$hello = fgets($pipes[1]);
if (trim((string) $hello) !== 'готов') {
    fwrite(STDERR, "поддельный сервер не поднялся\n");
    exit(1);
}

$fail = 0;
$ok = static function (string $what, bool $good) use (&$fail): void {
    if (!$good) { $fail++; }
    echo ($good ? '  ok  ' : '  НЕТ ') . $what . "\n";
};

$тема = 'Scribla — отзыв: приложение падает на длинной диктовке';
$текст = "Строка с кириллицей и переносами.\n"
    . ".точка в начале строки — её обязано пережить\n"
    . str_repeat('длинная строка без переносов ', 20);
$картинка = random_bytes(4096);

$png = $tmp . '/shot.png';
file_put_contents($png, $картинка);

$r = Mail::send($тема, $текст, 'человек@example.com', [
    ['name' => 'screenshot-1.png', 'type' => 'image/png', 'data' => $картинка],
]);

proc_close($srv);

echo "почта\n";
$ok('отправка не вернула ошибку: ' . var_export($r, true), $r === true);

$dump = is_file($got) ? json_decode((string) file_get_contents($got), true) : null;
if (!is_array($dump)) {
    echo "  НЕТ поддельный сервер ничего не записал\n";
    exit(1);
}

$cmds = implode(' | ', $dump['команды']);
$ok('вошли под ящиком', str_contains($cmds, 'AUTH PLAIN'));
$ok('отправитель — наш ящик', str_contains($cmds, 'MAIL FROM:<robot@scribla.io>'));
$ok('получатель первый',  str_contains($cmds, 'RCPT TO:<hi@example.com>'));
$ok('получатель второй',  str_contains($cmds, 'RCPT TO:<second@example.com>'));

$letter = (string) $dump['письмо'];
[$head, $rest] = explode("\r\n\r\n", $letter, 2);

$ok('заголовок ответа — адрес человека', str_contains($head, 'Reply-To: человек@example.com'));
$ok('тема закодирована по RFC 2047', str_contains($head, '=?UTF-8?B?'));

/* Тема целиком: разворачиваем сложенные строки. Перенос убирается,
   а пробел остаётся — на нём и держится граница между кусками. */
preg_match('/^Subject: ([^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/m', $head, $m);
$ok('тема совпала с исходной',
    mb_decode_mimeheader(str_replace(["\r\n ", "\r\n\t"], ' ', $m[1] ?? '')) === $тема);

preg_match('/boundary="([^"]+)"/', $head, $b);
$ok('письмо составное', isset($b[1]));

$parts = explode('--' . ($b[1] ?? 'нет'), $rest);
$ok('частей ровно две плюс хвост', count($parts) === 4);

$body = base64_decode(trim(explode("\r\n\r\n", $parts[1], 2)[1] ?? ''));
$ok('текст дошёл дословно', $body === $текст);
$ok('точка в начале строки уцелела', str_contains($body, "\n.точка"));

$att = explode("\r\n\r\n", $parts[2] ?? '', 2);
$ok('вложение названо', str_contains($att[0] ?? '', 'filename="screenshot-1.png"'));
$ok('вложение дошло байт в байт', base64_decode(trim($att[1] ?? '')) === $картинка);

// Прибираем за собой.
array_map('unlink', (array) glob($tmp . '/*'));
@rmdir($tmp);

echo $fail ? "\nпровалов: $fail\n" : "\nпочта — всё сходится\n";
exit($fail ? 1 : 0);
