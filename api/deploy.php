<?php
declare(strict_types=1);

/* Самообновление сайта из GitHub.
 *
 * Почему так, а не по-человечески. На этом хостинге нет SSH (Node тоже
 * нет), а раздел добавления SSH-ключей в панели отдаёт ошибку бэкенда.
 * Остаётся файловый менеджер — то есть архив руками при каждой правке
 * запятой. Эта ручка убирает ручную работу: она сама скачивает срез
 * ветки main из публичного репозитория и раскладывает файлы.
 *
 * Чем это НЕ является: сюда нельзя ничего загрузить. Адрес репозитория
 * зашит в коде, тело запроса не читается вовсе. Даже с ключом в руках
 * посторонний может добиться ровно одного — чтобы сайт переустановил
 * сам себя из своего же исходника.
 *
 * Ключ тот же, что у страницы отзывов: заводить второй секрет ради
 * действия, которое не даёт новых возможностей, — лишняя сущность.
 * Метод только POST: по ссылке из письма или префетчем не сработает.
 */

require __DIR__ . '/lib/http.php';

const REPO_TAR = 'https://codeload.github.com/alvlsk12345/scribla-site/tar.gz/refs/heads/main';
const REPO_DIR = 'scribla-site-main';

/* Что раскладываем. Список закрытый: всё, чего в нём нет, на сервер
 * не попадёт, как бы оно ни называлось в архиве. */
const TAKE = ['index.html', 'privacy.html', 'support.html', '.htaccess', 'assets', 'api'];

/* Каталоги, которые заменяются целиком. Иначе удалённый в репозитории
 * файл остался бы на сервере навсегда. `api` сюда не входит намеренно:
 * мы сейчас исполняемся изнутри него. */
const REPLACE_WHOLE = ['assets'];

require_post();

$keyFile = data_dir() . '/admin.key';
$key = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
if ($key === '') { say(404, ['error' => 'Не найдено']); }
if (!hash_equals($key, (string) ($_GET['key'] ?? ''))) { say(403, ['error' => 'Нет']); }

$root = dirname(__DIR__);              // public_html
$tmp  = data_dir() . '/deploy';
$log  = [];

// ------------------------------------------------------------- скачивание

@mkdir($tmp, 0700, true);
$tarGz = $tmp . '/main.tar.gz';
@unlink($tarGz);

$bytes = fetch(REPO_TAR);
if ($bytes === null || strlen($bytes) < 1024) {
    say(502, ['error' => 'Не скачался архив из GitHub', 'log' => $log]);
}
file_put_contents($tarGz, $bytes);
$log[] = 'скачано ' . strlen($bytes) . ' байт';

// ------------------------------------------------------------- распаковка

$work = $tmp . '/x';
rm_rf($work);
@mkdir($work, 0700, true);

try {
    $tar = $tmp . '/main.tar';
    @unlink($tar);
    (new PharData($tarGz))->decompress();      // .tar.gz → .tar
    (new PharData($tar))->extractTo($work, null, true);
    @unlink($tar);
} catch (Throwable $e) {
    say(500, ['error' => 'Не распаковался архив: ' . $e->getMessage(), 'log' => $log]);
}

$src = $work . '/' . REPO_DIR;
if (!is_dir($src)) { say(500, ['error' => 'В архиве нет ' . REPO_DIR, 'log' => $log]); }
$log[] = 'распаковано';

// ------------------------------------------------------------- раскладка

foreach (TAKE as $item) {
    $from = $src . '/' . $item;
    $to   = $root . '/' . $item;
    if (!file_exists($from)) { $log[] = 'нет в архиве: ' . $item; continue; }

    if (in_array($item, REPLACE_WHOLE, true) && is_dir($to)) { rm_rf($to); }
    copy_over($from, $to);
    $log[] = 'обновлено: ' . $item;
}

rm_rf($work);
@unlink($tarGz);

/* Отметку о выкладке кладём рядом с данными: по ней видно, когда сайт
 * обновлялся, не заходя в панель. */
@file_put_contents(data_dir() . '/deployed.txt', gmdate('c') . "\n", FILE_APPEND);

say(200, ['message' => 'Выложено', 'at' => gmdate('c'), 'log' => $log]);

// ------------------------------------------------------------- утилиты

function fetch(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'scribla-deploy',
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close() с PHP 8.0 ничего не делает, а с 8.5 ещё и ругается
        // в вывод — предупреждение вклеивалось перед JSON и ломало ответ.
        if (is_string($out) && $code === 200) { return $out; }
    }
    if (ini_get('allow_url_fopen')) {
        $out = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 60, 'user_agent' => 'scribla-deploy'],
        ]));
        if (is_string($out)) { return $out; }
    }
    return null;
}

function copy_over(string $from, string $to): void
{
    if (is_dir($from)) {
        if (!is_dir($to)) { @mkdir($to, 0755, true); }
        foreach (scandir($from) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            copy_over($from . '/' . $e, $to . '/' . $e);
        }
        return;
    }
    @copy($from, $to);
    @chmod($to, 0644);
}

function rm_rf(string $p): void
{
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (!is_dir($p)) { return; }
    foreach (scandir($p) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        rm_rf($p . '/' . $e);
    }
    @rmdir($p);
}
