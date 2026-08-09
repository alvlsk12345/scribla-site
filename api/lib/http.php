<?php
declare(strict_types=1);

/* Общее для всех ручек: ответ, тело запроса, хранилище, лимит частоты.
 *
 * Каталог данных лежит ВЫШЕ public_html — иначе его содержимое можно
 * было бы скачать по прямой ссылке. Отзывы с чужой почтой в открытом
 * доступе — это утечка, и .htaccess тут ненадёжная защита: он живёт
 * внутри той же папки, которую защищает.
 */

/* Предупреждения и уведомления PHP — в журнал, но не в тело ответа.
 * На этом уже обожглись: `curl_close()` в PHP 8.5 объявлен устаревшим,
 * его сообщение вклеилось перед JSON, и разбор ответа сломался. Ошибку
 * починили, но любая следующая устаревшая функция сделает то же самое.
 * Ответ ручки API обязан быть чистым JSON и ничем больше. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* Ответ человеку уже ушёл, а письмо владельцу — ещё нет. Закрытая
 * вкладка не должна отменять эту работу: заявка принята, значит она
 * дойдёт. */
ignore_user_abort(true);

const SCRIBLA_MAX_BODY = 65536;

function data_dir(): string
{
    $env = getenv('SCRIBLA_DATA');
    if (is_string($env) && $env !== '') { return rtrim($env, '/'); }

    // public_html/api/lib → на три уровня вверх, рядом с public_html
    return dirname(__DIR__, 3) . '/scribla-data';
}

function say(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    /* Отпускаем человека сразу. Дальше через register_shutdown_function
     * уходит письмо владельцу, а это разговор с чужим сервером на
     * секунду-другую — ждать его кнопке «Отправляем…» незачем. */
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    exit;
}

/** Тело запроса с потолком: без него любой желающий забьёт нам память.
 *
 * Форма отзывов со скриншотами приходит как multipart/form-data —
 * иначе картинки пришлось бы гнать в base64, а это плюс треть веса
 * на ровном месте. Поля в обоих случаях называются одинаково, так что
 * ручки разницы не замечают. */
function body(): array
{
    $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

    if (str_starts_with($type, 'multipart/form-data')) {
        /* Тело такого запроса PHP разобрал до нас. Если оно не влезло
         * в post_max_size, разбор пуст, а сервер молчит — единственный
         * след остаётся в CONTENT_LENGTH. Отвечаем понятно, иначе
         * человек видит «не разобрали запрос» и не знает, что делать. */
        if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            say(413, ['error' => 'Слишком большой файл']);
        }
        return $_POST;
    }

    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > SCRIBLA_MAX_BODY) { say(413, ['error' => 'Слишком длинно']); }

    $raw = file_get_contents('php://input', false, null, 0, SCRIBLA_MAX_BODY + 1);
    if ($raw === false || strlen($raw) > SCRIBLA_MAX_BODY) {
        say(413, ['error' => 'Слишком длинно']);
    }
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) { say(400, ['error' => 'Не разобрали запрос']); }
    return $data;
}

/* Ответ на языке страницы, с которой пришли.
 *
 * Поймано проверкой на живом сайте: английская форма отвечала
 * по-русски. Скрипт подставляет свою строку только когда сервер молчит,
 * а сервер не молчал — и перебивал её. Значит язык должен приезжать
 * вместе с запросом: браузерный Accept-Language тут не годится, у людей
 * он сплошь и рядом не совпадает с выбранной на сайте версией. */
function pick(array $in, string $ru, string $en): string
{
    return ($in['lang'] ?? '') === 'en' ? $en : $ru;
}

function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if (is_string($v) && $v !== '') { return trim(explode(',', $v)[0]); }
    }
    return '?';
}

/* Проверка адреса намеренно нестрогая: полная по RFC пропускает мусор
 * и отбивает живые адреса, а поймать надо опечатку. */
function looks_like_email(string $s): bool
{
    return $s !== '' && strlen($s) <= 254
        && (bool) preg_match('/^[^@\s]+@[^@\s.]+\.[^@\s]{2,}$/u', $s);
}

/** Запись строкой в JSONL. Формат переживает обрыв: теряется последняя
 *  строка, а не весь файл. Невозможность записи не должна ронять ответ
 *  человеку — он свою часть выполнил. */
function store(string $file, array $row): void
{
    $dir = data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('scribla: не создать ' . $dir);
        error_log('scribla ' . $file . ' ' . json_encode($row, JSON_UNESCAPED_UNICODE));
        return;
    }
    $line = json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    if (@file_put_contents($dir . '/' . $file, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('scribla: не записать ' . $file);
        error_log('scribla ' . $file . ' ' . $line);
    }
}

/** Грубый лимит по адресу. Файловый, потому что на общем хостинге
 *  память между запросами не живёт. */
function too_often(string $ip, int $max = 5, int $window = 600): bool
{
    $dir = data_dir() . '/rate';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return false; }

    $f = $dir . '/' . substr(hash('sha256', $ip), 0, 32);
    $now = time();
    $hits = [];
    if (is_file($f)) {
        $hits = array_filter(
            array_map('intval', explode(',', (string) @file_get_contents($f))),
            static fn(int $t): bool => $now - $t < $window
        );
    }
    $hits[] = $now;
    @file_put_contents($f, implode(',', $hits), LOCK_EX);

    // Изредка подметаем за собой, иначе каталог растёт вечно.
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*') ?: [] as $old) {
            if (is_file($old) && $now - (int) filemtime($old) > $window * 4) { @unlink($old); }
        }
    }
    return count($hits) > $max;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        say(405, ['error' => 'Так нельзя']);
    }
}
