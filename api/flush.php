<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';

/* Сброс скомпилированного кэша PHP после выкладки.
 *
 * Поймано опытом: выложили правку в существующий .php — сервер отдаёт
 * старое поведение, хотя файл на диске новый. Новые файлы при этом
 * работают сразу. Это opcache: он держит скомпилированный код и,
 * судя по всему, не сверяется со временем файла.
 *
 * Отдельная ручка, а не строчка в deploy.php, по простой причине:
 * deploy.php тоже лежит в этом кэше. Правка внутри него не заработала
 * бы до сброса, а сбросить некому — замкнутый круг. Файл, который
 * никогда не меняется, разрывает его надёжно.
 */

$keyFile = data_dir() . '/admin.key';
$key = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
if ($key === '') { say(404, ['error' => 'Не найдено']); }
if (!hash_equals($key, (string) ($_GET['key'] ?? ''))) { say(403, ['error' => 'Нет']); }

$was = [
    'opcache.enable'             => ini_get('opcache.enable'),
    'opcache.validate_timestamps'=> ini_get('opcache.validate_timestamps'),
    'opcache.revalidate_freq'    => ini_get('opcache.revalidate_freq'),
];

$done = function_exists('opcache_reset') ? opcache_reset() : null;

say(200, [
    'настройки' => $was,
    'сброс'     => match (true) {
        $done === true  => 'выполнен',
        $done === false => 'не дали — вероятно, opcache.restrict_api',
        default         => 'opcache_reset недоступен',
    },
]);
