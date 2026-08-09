<?php
declare(strict_types=1);

/* Поддельный SMTP-сервер для стенда.
 *
 * Принимает одно соединение, ведёт себя как настоящий и складывает
 * полученное письмо в файл. Нужен, чтобы проверять нашу переписку
 * по протоколу и сборку MIME, не имея под рукой боевого ящика
 * и не отправляя людям проверочные письма.
 *
 *     php test/fake-smtp.php <порт> <куда-положить-письмо>
 */

[$port, $out] = [(int) ($argv[1] ?? 0), (string) ($argv[2] ?? '')];
if (!$port || $out === '') { fwrite(STDERR, "нужны порт и файл\n"); exit(2); }

$srv = stream_socket_server("tcp://127.0.0.1:$port", $no, $err);
if (!$srv) { fwrite(STDERR, "не поднялся: $err\n"); exit(2); }

/* Говорим стенду, что порт открыт.
 *
 * Прежде он просто ждал полсекунды и стучался. Под нагрузкой PHP
 * не успевал подняться, стенд падал с «Connection refused», и выглядело
 * это как поломка почты — то есть искать её начинали в MIME. Проверить
 * порт пробным соединением нельзя: приём тут один-единственный,
 * и проба забрала бы его себе.
 */
fwrite(STDOUT, "готов\n");
fflush(STDOUT);

$c = stream_socket_accept($srv, 10);
if (!$c) { fwrite(STDERR, "никто не пришёл\n"); exit(2); }
stream_set_timeout($c, 10);

fwrite($c, "220 fake ESMTP\r\n");

$letter = '';
$inData = false;
$seen = [];

while (($line = fgets($c, 4096)) !== false) {
    if ($inData) {
        if (rtrim($line, "\r\n") === '.') {
            $inData = false;
            fwrite($c, "250 2.0.0 принято\r\n");
            continue;
        }
        // Обратная распаковка точки: настоящий сервер делает так же.
        $letter .= preg_replace('/^\.\./', '.', $line);
        continue;
    }

    $cmd = strtoupper(substr(trim($line), 0, 4));
    $seen[] = trim($line);

    match (true) {
        $cmd === 'EHLO' => fwrite($c, "250-fake\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n"),
        $cmd === 'HELO' => fwrite($c, "250 fake\r\n"),
        $cmd === 'AUTH' => fwrite($c, "235 2.7.0 пустили\r\n"),
        $cmd === 'MAIL', $cmd === 'RCPT' => fwrite($c, "250 2.1.0 ладно\r\n"),
        $cmd === 'DATA' => (function () use ($c, &$inData) {
            $inData = true;
            fwrite($c, "354 давайте\r\n");
        })(),
        $cmd === 'QUIT' => fwrite($c, "221 пока\r\n"),
        default => fwrite($c, "250 ладно\r\n"),
    };

    if ($cmd === 'QUIT') { break; }
}

fclose($c);
fclose($srv);

file_put_contents($out, json_encode(
    ['команды' => $seen, 'письмо' => $letter],
    JSON_UNESCAPED_UNICODE
));
