<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';

/* Ключ читается из файла рядом с данными, а не из кода: репозиторий
 * публичный. Нет файла — страницы нет вовсе; лучше не отдать владельцу,
 * чем отдать первому встречному. */
$keyFile = data_dir() . '/admin.key';
$key = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';

if ($key === '') { say(404, ['error' => 'Не найдено']); }

$given = (string) ($_GET['key'] ?? '');
if (!hash_equals($key, $given)) { say(403, ['error' => 'Нет']); }

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

say(200, ['notify' => $read('notify.jsonl'), 'feedback' => $read('feedback.jsonl')]);
