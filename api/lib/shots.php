<?php
declare(strict_types=1);

/* Скриншоты к отзыву.
 *
 * Лежат в scribla-data/uploads — ВЫШЕ public_html, как и всё остальное
 * из форм. Картинка с чужого экрана может содержать что угодно: переписку,
 * адрес, номер заказа. Такое не должно лежать по угадываемой ссылке;
 * смотрит их только владелец через admin.php с ключом.
 *
 * Тип определяем по содержимому, а не по имени и не по заголовку от
 * браузера — и то и другое пишет отправитель. Файл, который не разобрался
 * как картинка, не сохраняем вовсе.
 */

const SHOT_MAX      = 3;
const SHOT_BYTES    = 5 * 1024 * 1024;
const SHOT_TOTAL    = 9 * 1024 * 1024;   // потолок вложений в одном письме

const SHOT_TYPES = [
    IMAGETYPE_WEBP => ['webp', 'image/webp'],
    IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
    IMAGETYPE_PNG  => ['png',  'image/png'],
];

/**
 * Принимает $_FILES['shots'], возвращает то, что удалось сохранить.
 *
 * @return list<array{file:string,type:string,bytes:int,w:int,h:int}>
 */
function shots_take(): array
{
    $in = $_FILES['shots'] ?? null;
    if (!is_array($in) || !isset($in['tmp_name'])) { return []; }

    // Один файл и несколько приходят по-разному — приводим к одному виду.
    $names = (array) $in['tmp_name'];
    $errs  = (array) ($in['error'] ?? []);
    $sizes = (array) ($in['size'] ?? []);

    $dir = data_dir() . '/uploads/' . gmdate('Y-m');
    $out = [];

    foreach (array_slice(array_keys($names), 0, SHOT_MAX) as $i) {
        $tmp = (string) $names[$i];
        $err = (int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE);

        if ($err === UPLOAD_ERR_NO_FILE) { continue; }
        if ($err !== UPLOAD_ERR_OK) {
            error_log('scribla shots: код загрузки ' . $err);
            continue;
        }
        if ($tmp === '' || !is_uploaded_file($tmp)) { continue; }
        if ((int) ($sizes[$i] ?? 0) > SHOT_BYTES) { continue; }

        $probe = @getimagesize($tmp);
        if (!is_array($probe) || !isset(SHOT_TYPES[$probe[2]])) { continue; }
        [$ext, $mime] = SHOT_TYPES[$probe[2]];

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            error_log('scribla shots: не создать ' . $dir);
            return $out;
        }

        $name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
            error_log('scribla shots: не сохранить ' . $name);
            continue;
        }
        @chmod($dir . '/' . $name, 0600);

        $out[] = [
            'file'  => gmdate('Y-m') . '/' . $name,
            'type'  => $mime,
            'bytes' => (int) ($sizes[$i] ?? filesize($dir . '/' . $name)),
            'w'     => (int) $probe[0],
            'h'     => (int) $probe[1],
        ];
    }

    return $out;
}

/** Полный путь к сохранённому скриншоту либо null.
 *  Имя приходит из журнала, но проверяем всё равно: путь, собранный
 *  из строки, — классический способ уйти вверх по каталогам. */
function shots_path(string $rel): ?string
{
    if (!preg_match('#^\d{4}-\d{2}/[0-9a-z.\-]+$#', $rel)) { return null; }

    $root = data_dir() . '/uploads';
    $full = $root . '/' . $rel;
    $real = realpath($full);

    if ($real === false || !str_starts_with($real, (string) realpath($root))) { return null; }
    return is_file($real) ? $real : null;
}

/**
 * Вложения для письма: имена латиницей и по порядку, вес — под потолком.
 *
 * @param list<array{file:string,type:string,bytes:int}> $shots
 * @return list<array{path:string,name:string,type:string}>
 */
function shots_for_mail(array $shots): array
{
    $out = [];
    $sum = 0;
    foreach ($shots as $n => $s) {
        $path = shots_path($s['file']);
        if ($path === null) { continue; }
        $size = (int) filesize($path);
        if ($sum + $size > SHOT_TOTAL) { break; }
        $sum += $size;

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $out[] = [
            'path' => $path,
            'name' => 'screenshot-' . ($n + 1) . '.' . $ext,
            'type' => $s['type'],
        ];
    }
    return $out;
}
