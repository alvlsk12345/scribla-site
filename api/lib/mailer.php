<?php
declare(strict_types=1);

/* Почта наружу. Без библиотек — по той же причине, что и всё остальное
 * здесь: на общем хостинге нет composer, а нужный нам SMTP умещается
 * в полторы сотни строк вместе с вложениями.
 *
 * Настройки лежат в scribla-data/mail.json — рядом с admin.key, ВЫШЕ
 * public_html. Пароль от ящика в репозиторий не попадает никогда,
 * и по прямой ссылке его не скачать.
 *
 * Нет файла настроек — писем нет, но форма работает и заявка ложится
 * в журнал. Человек своё сделал; наши сложности с почтой — не его беда.
 *
 * Отправитель всегда наш ящик, а не тот, кто написал. Так требует
 * Timeweb («подмена отправителя запрещена»), и так же требуют SPF
 * и DMARC у получателя: письмо «от gmail.com», отправленное с чужого
 * сервера, попадает в спам заслуженно. Адрес человека — в Reply-To,
 * ответ уходит ему.
 */

final class Mail
{
    private const TIMEOUT = 12;

    /** Настройки или null, если почта не подключена. */
    public static function config(): ?array
    {
        static $cache = false;
        if ($cache !== false) { return $cache; }

        $file = data_dir() . '/mail.json';
        if (!is_file($file)) { return $cache = null; }

        $cfg = json_decode((string) @file_get_contents($file), true);
        if (!is_array($cfg)) {
            error_log('scribla mail: mail.json не разобрался');
            return $cache = null;
        }

        /* secure: ssl (порт 465), tls (587, STARTTLS) или plain (25, 2525).
         * Открытый канал Timeweb допускает, но это крайний случай: пароль
         * от ящика идёт по проводу как есть. Годится, только если хостинг
         * закрыл оба защищённых порта. */
        $cfg += [
            'host'      => 'smtp.timeweb.ru',
            'port'      => 465,
            'secure'    => 'ssl',
            'from_name' => 'Scribla',
            'to'        => '',
        ];
        foreach (['user', 'pass', 'to'] as $need) {
            if (($cfg[$need] ?? '') === '') {
                error_log('scribla mail: в mail.json нет поля ' . $need);
                return $cache = null;
            }
        }
        $cfg['port'] = (int) $cfg['port'];
        return $cache = $cfg;
    }

    public static function ready(): bool
    {
        return self::config() !== null;
    }

    /**
     * Письмо владельцу сайта.
     *
     * @param list<array{name:string,type:string,data:string}> $files
     * @return true|string  true или текст ошибки для журнала
     */
    public static function send(string $subject, string $text, string $replyTo = '', array $files = []): bool|string
    {
        $cfg = self::config();
        if ($cfg === null) { return 'почта не настроена'; }

        $body = self::compose($cfg, $subject, $text, $replyTo, $files);
        return self::talk($cfg, $body);
    }

    /* ------------------------------------------------------------ письмо */

    /** Заголовок с кириллицей по RFC 2047. Куски режем по символам,
     *  а не по байтам: разрезанная посередине буква превращает тему
     *  в мусор у получателя. */
    private static function word(string $s): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $s)) { return $s; }

        $out = [];
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len;) {
            $take = '';
            // 42 символа base64 ≈ 56 знаков — с запасом влезает в 75.
            while ($i < $len && strlen(base64_encode($take)) < 42) {
                $take .= mb_substr($s, $i++, 1, 'UTF-8');
            }
            $out[] = '=?UTF-8?B?' . base64_encode($take) . '?=';
        }
        return implode("\r\n ", $out);
    }

    /** В адресных заголовках перевод строки — это чужой заголовок,
     *  вписанный поверх нашего. Режем на входе, а не надеемся. */
    private static function clean(string $s): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $s));
    }

    private static function compose(array $cfg, string $subject, string $text, string $replyTo, array $files): string
    {
        $from = self::clean($cfg['user']);
        $host = substr(strrchr($from, '@') ?: '@scribla.io', 1);
        $mark = '=_' . bin2hex(random_bytes(12));

        $h = [
            'Date: ' . date('r'),
            'From: ' . self::word(self::clean($cfg['from_name'])) . ' <' . $from . '>',
            'To: ' . self::clean($cfg['to']),
            'Subject: ' . self::word(self::clean($subject)),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $host . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ];
        if ($replyTo !== '' && str_contains($replyTo, '@')) {
            $h[] = 'Reply-To: ' . self::clean($replyTo);
        }

        // Тело всегда base64: длинные строки, кириллица и точка в начале
        // строки перестают быть чьей-то заботой.
        $body = chunk_split(base64_encode($text), 76, "\r\n");

        if (!$files) {
            $h[] = 'Content-Type: text/plain; charset=UTF-8';
            $h[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $h) . "\r\n\r\n" . $body;
        }

        $h[] = 'Content-Type: multipart/mixed; boundary="' . $mark . '"';
        $parts = ["--$mark\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n" . $body];

        foreach ($files as $f) {
            // Имя вложения держим латиницей: так не нужен RFC 2231,
            // а половина почтовых клиентов его до сих пор путает.
            $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $f['name']) ?: 'file';
            $parts[] = "--$mark\r\n"
                . 'Content-Type: ' . self::clean($f['type']) . '; name="' . $name . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $name . "\"\r\n\r\n"
                . chunk_split(base64_encode($f['data']), 76, "\r\n");
        }

        return implode("\r\n", $h) . "\r\n\r\n" . implode('', $parts) . "--$mark--\r\n";
    }

    /* ------------------------------------------------------------- канал */

    private static function line($s, string $cmd): void
    {
        fwrite($s, $cmd . "\r\n");
    }

    /** Ответ сервера целиком: многострочный кончается кодом и пробелом. */
    private static function reply($s): string
    {
        $all = '';
        while (($line = fgets($s, 1024)) !== false) {
            $all .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') { break; }
        }
        return $all;
    }

    private static function expect($s, string $want, string $step): ?string
    {
        $got = self::reply($s);
        if (!str_starts_with($got, $want)) {
            return $step . ': ' . trim($got);
        }
        return null;
    }

    private static function talk(array $cfg, string $letter): bool|string
    {
        $secure = strtolower((string) $cfg['secure']);
        $addr = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'];

        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'SNI_enabled'       => true,
        ]]);

        $s = @stream_socket_client($addr, $errNo, $errStr, self::TIMEOUT,
            STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) { return 'не соединиться с ' . $addr . ': ' . $errStr; }
        stream_set_timeout($s, self::TIMEOUT);

        $ehlo = 'EHLO ' . (substr(strrchr((string) $cfg['user'], '@') ?: '@localhost', 1));

        try {
            if ($e = self::expect($s, '220', 'приветствие')) { return $e; }

            self::line($s, $ehlo);
            $caps = self::reply($s);
            if (!str_starts_with($caps, '250')) { return 'EHLO: ' . trim($caps); }

            if ($secure === 'tls') {
                self::line($s, 'STARTTLS');
                if ($e = self::expect($s, '220', 'STARTTLS')) { return $e; }
                if (!@stream_socket_enable_crypto($s, true,
                        STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return 'не поднялось шифрование';
                }
                self::line($s, $ehlo);
                $caps = self::reply($s);
                if (!str_starts_with($caps, '250')) { return 'EHLO после STARTTLS: ' . trim($caps); }
            }

            // AUTH PLAIN одним ходом, где можно; LOGIN — где нет.
            if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') !== false) {
                self::line($s, 'AUTH PLAIN ' . base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']));
                if ($e = self::expect($s, '235', 'вход')) { return $e; }
            } else {
                self::line($s, 'AUTH LOGIN');
                if ($e = self::expect($s, '334', 'вход')) { return $e; }
                self::line($s, base64_encode((string) $cfg['user']));
                if ($e = self::expect($s, '334', 'имя')) { return $e; }
                self::line($s, base64_encode((string) $cfg['pass']));
                if ($e = self::expect($s, '235', 'пароль')) { return $e; }
            }

            self::line($s, 'MAIL FROM:<' . self::clean($cfg['user']) . '>');
            if ($e = self::expect($s, '250', 'отправитель')) { return $e; }

            foreach (array_filter(array_map('trim', explode(',', (string) $cfg['to']))) as $rcpt) {
                self::line($s, 'RCPT TO:<' . self::clean($rcpt) . '>');
                if ($e = self::expect($s, '250', 'получатель')) { return $e; }
            }

            self::line($s, 'DATA');
            if ($e = self::expect($s, '354', 'DATA')) { return $e; }

            // Точка в начале строки внутри письма означала бы конец письма.
            fwrite($s, str_replace("\r\n.", "\r\n..", $letter) . "\r\n.\r\n");
            if ($e = self::expect($s, '250', 'приём')) { return $e; }

            self::line($s, 'QUIT');
            return true;
        } finally {
            @fclose($s);
        }
    }
}

/* Письмо отправляем ПОСЛЕ ответа человеку: SMTP отвечает секунду-другую,
 * и заставлять кнопку «Отправляем…» ждать нашу переписку с почтовым
 * сервером незачем. На FPM ответ уходит сразу, на остальном — как выйдет,
 * но порядок в любом случае правильный. */
function mail_later(string $subject, string $text, string $replyTo = '', array $attach = []): void
{
    if (!Mail::ready()) { return; }

    register_shutdown_function(static function () use ($subject, $text, $replyTo, $attach): void {
        // Картинки читаем здесь, а не держим в памяти всю обработку
        // запроса: три файла по пять мегабайт съели бы лимит целиком.
        $files = [];
        foreach ($attach as $a) {
            $data = @file_get_contents($a['path']);
            if ($data !== false) {
                $files[] = ['name' => $a['name'], 'type' => $a['type'], 'data' => $data];
            }
        }
        $r = Mail::send($subject, $text, $replyTo, $files);
        if ($r !== true) { error_log('scribla mail: ' . $r); }
    });
}
