<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/mailer.php';

/* Проверка почты одной ссылкой.
 *
 *   /api/selftest.php?key=КЛЮЧ            — что видит сервер
 *   /api/selftest.php?key=КЛЮЧ&send=1     — отправить пробное письмо
 *
 * Существует потому, что «не пришло письмо» — это десяток разных причин
 * (нет ящика, не тот пароль, закрыт порт, письмо ушло в спам), и
 * различать их вслепую невыносимо. Тут видно, на каком шаге встало.
 *
 * Пароль не показывается никогда — только длина, чтобы отличить пустое
 * поле от заполненного.
 */

$keyFile = data_dir() . '/admin.key';
$key = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
if ($key === '') { say(404, ['error' => 'Не найдено']); }
if (!hash_equals($key, (string) ($_GET['key'] ?? ''))) { say(403, ['error' => 'Нет']); }

$cfg = Mail::config();

$out = [
    'php'  => PHP_VERSION . ' / ' . PHP_SAPI,
    'ответ_уходит_сразу' => function_exists('fastcgi_finish_request'),
    'загрузка_файлов' => [
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size'       => ini_get('post_max_size'),
        'max_file_uploads'    => ini_get('max_file_uploads'),
        'memory_limit'        => ini_get('memory_limit'),
    ],
    'каталог_данных' => [
        'путь'    => data_dir(),
        'есть'    => is_dir(data_dir()),
        'пишется' => is_dir(data_dir()) && is_writable(data_dir()),
    ],
    'openssl' => extension_loaded('openssl'),
    'почта'   => $cfg === null ? 'не настроена — нет scribla-data/mail.json' : [
        'сервер'         => $cfg['host'] . ':' . $cfg['port'] . ' (' . $cfg['secure'] . ')',
        'ящик'           => $cfg['user'],
        'кому'           => $cfg['to'],
        'пароль_длиной'  => strlen((string) $cfg['pass']),
    ],
];

/* Сам канал проверяем ВСЕГДА, даже до того, как заведён ящик: на общем
 * хостинге исходящий SMTP бывает закрыт, и тогда всё остальное настроено
 * верно, а писем нет. Лучше узнать это до, чем после. */
$probe = [];
$try = [['ssl://smtp.timeweb.ru:465', 'ssl'], ['tcp://smtp.timeweb.ru:587', 'tls']];
if ($cfg !== null) {
    array_unshift($try, [
        ($cfg['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'],
        $cfg['secure'],
    ]);
}
foreach ($try as [$addr, $_]) {
    if (isset($probe[$addr])) { continue; }
    $t = microtime(true);
    $s = @stream_socket_client($addr, $no, $err, 8);
    $probe[$addr] = $s
        ? 'открыт за ' . round((microtime(true) - $t) * 1000) . ' мс: '
            . trim((string) fgets($s, 512))
        : 'закрыт: ' . $err;
    if ($s) { @fclose($s); }
}
$out['канал'] = $probe;

if (($_GET['send'] ?? '') !== '' && $cfg !== null) {
    $r = Mail::send(
        'Scribla — проверка связи',
        "Это пробное письмо с сайта scribla.io.\n\n"
        . "Если оно у вас в ящике — формы обратной связи и подписки\n"
        . "теперь доедут туда же.\n\n"
        . 'Отправлено ' . gmdate('d.m.Y H:i') . ' UTC'
    );
    $out['пробное_письмо'] = $r === true ? 'ушло на ' . $cfg['to'] : $r;
}

say(200, $out);
