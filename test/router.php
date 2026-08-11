<?php
/* Маршрутизатор для встроенного сервера PHP.
 *
 * Нужен, чтобы локально крутился ТОТ ЖЕ код, что на хостинге. Раньше
 * рядом жил server.js на Node, и это была вторая реализация тех же
 * ручек — а вторая реализация всегда чуть-чуть другая. Проверять форму
 * на ней значило проверять не то, что поедет людям.
 *
 * Здесь повторено ровно одно правило из .htaccess: красивый адрес
 * /api/имя ведёт в api/имя.php. Всё остальное отдаётся как файл.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if (preg_match('#^/api/(notify|feedback|admin|selftest|deploy|log|report|ai)/?$#', $path, $m)) {
    require __DIR__ . '/../api/' . $m[1] . '.php';
    return true;
}

$file = __DIR__ . '/..' . $path;
if ($path !== '/' && is_file($file)) {
    /* Стиль и сценарий отдаём сами — только ради «не кэшировать».
     * Браузер держал у себя вчерашний site.js, и стенд проверял код,
     * которого на диске уже не было: правка не действовала, а выглядело
     * это как ошибка в самой правке. На хостинге за свежесть отвечает
     * штамп ?v=<md5>, который проставляет deploy.php; локально штампа
     * нет, поэтому нужен заголовок.
     *
     * Остальное (картинки и особенно видео) оставляем встроенному
     * серверу: он умеет отдавать куски по Range, а <video> без этого
     * не перематывается. */
    if (preg_match('#\.(css|js)$#', $path)) {
        header('Cache-Control: no-store');
        header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'text/javascript') . '; charset=utf-8');
        readfile($file);
        return true;
    }
    return false;                                        // отдаст сервер
}

if (is_dir($file)) { $file = rtrim($file, '/') . '/index.html'; }
if ($path === '/') { $file = __DIR__ . '/../index.html'; }

if (is_file($file)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    return true;
}
http_response_code(404);
echo 'Нет такой страницы';
return true;
