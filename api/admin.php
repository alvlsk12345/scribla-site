<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/shots.php';

/* Ключ читается из файла рядом с данными, а не из кода: репозиторий
 * публичный. Нет файла — страницы нет вовсе; лучше не отдать владельцу,
 * чем отдать первому встречному. */
$keyFile = data_dir() . '/admin.key';
$key = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';

if ($key === '') { say(404, ['error' => 'Не найдено']); }

$given = (string) ($_GET['key'] ?? '');
if (!hash_equals($key, $given)) { say(403, ['error' => 'Нет']); }

/* Скриншот из отзыва. Лежит выше public_html, поэтому отдаём его руками
 * и только по ключу.
 *
 * Заголовки строгие: тип не угадывать, ничего не подгружать. Файл мы
 * проверили при приёме, но он всё равно пришёл снаружи — пусть браузер
 * считает его картинкой и ничем больше. */
$want = (string) ($_GET['file'] ?? '');
if ($want !== '') {
    $path = shots_path($want);
    if ($path === null) { say(404, ['error' => 'Не найдено']); }

    $type = match (@getimagesize($path)[2] ?? 0) {
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        default        => 'application/octet-stream',
    };

    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

$read = static function (string $f): array {
    $p = data_dir() . '/' . $f;
    if (!is_file($p)) { return []; }
    $out = [];
    foreach (explode("\n", (string) file_get_contents($p)) as $line) {
        if ($line === '') { continue; }
        $row = json_decode($line, true);
        if (is_array($row)) { $out[] = $row; }
    }
    return $out;
};

say(200, [
    'notify'   => $read('notify.jsonl'),
    'feedback' => $read('feedback.jsonl'),
    /* Видно сразу, уходят письма или копятся молча. Без этого поломка
     * почты выглядит как «отзывов нет». */
    'mail'     => is_file(data_dir() . '/mail.json') ? 'настроена' : 'не настроена',
]);
