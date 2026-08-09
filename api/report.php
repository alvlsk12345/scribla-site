<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';

/* Сводка по журналам за день — то, из чего делаются выводы.
 *
 * Отдельная ручка, а не выгрузка сырых строк, и на то две причины.
 * Первая: за день записей бывают тысячи, а решение принимается по
 * трём десяткам цифр — гнать остальное через сеть и через чужой
 * контекст незачем. Вторая: ключ от неё лежит у того, кто читает
 * отчёты, и пусть он не открывает ни отзывов с чужой почтой,
 * ни выкладки. Здесь и читать-то нечего, кроме чисел.
 *
 * Ключ свой — `scribla-data/report.key`, заводится выкладкой так же,
 * как ключ приёма. Пустой файл или его отсутствие означают «ручки нет».
 */

$keyFile = data_dir() . '/report.key';
$key = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
if ($key === '') { say(404, ['error' => 'Не найдено']); }
if (!hash_equals($key, (string) ($_GET['key'] ?? ''))) { say(403, ['error' => 'Нет']); }

$asked = (string) ($_GET['day'] ?? '');
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked) ? $asked : gmdate('Y-m-d');

/** Строки журнала за указанный день. */
function rows_for(string $day): array
{
    $path = data_dir() . '/logs/' . $day . '.jsonl';
    if (!is_file($path)) { return []; }

    $out = [];
    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        if ($line === '') { continue; }
        $row = json_decode($line, true);
        if (is_array($row)) { $out[] = $row; }
    }
    return $out;
}

/** Сообщение без чисел — чтобы одинаковые беды считались вместе.
 *
 * «Полировка не уложилась в 8 с» и «в 15 с» — одна и та же беда,
 * а как разные строки они разошлись бы по хвосту списка и не попались
 * на глаза. Числа заменяются на N, остальное оставляем как есть:
 * названия моделей и панелей — это как раз то, что различает случаи.
 */
function shape(string $message): string
{
    return preg_replace('/\d+([.,]\d+)?/u', 'N', $message) ?? $message;
}

$rows = rows_for($day);

$installs = [];
$byShape = [];
$byVersion = [];
$byDevice = [];
$byOS = [];
$keyboardInstalls = [];

foreach ($rows as $row) {
    $id = (string) ($row['install'] ?? '');
    $installs[$id] = true;

    $shape = shape((string) ($row['message'] ?? ''));
    if (!isset($byShape[$shape])) {
        $byShape[$shape] = ['count' => 0, 'installs' => [], 'example' => (string) ($row['message'] ?? '')];
    }
    $byShape[$shape]['count']++;
    $byShape[$shape]['installs'][$id] = true;

    $byVersion[(string) ($row['app'] ?? '?')] = ($byVersion[(string) ($row['app'] ?? '?')] ?? 0) + 1;
    $byDevice[(string) ($row['device'] ?? '?')] = ($byDevice[(string) ($row['device'] ?? '?')] ?? 0) + 1;
    $byOS[(string) ($row['os'] ?? '?')] = ($byOS[(string) ($row['os'] ?? '?')] ?? 0) + 1;

    // Клавиатура пишет в журнал только когда её открыли — то есть когда
    // человек довёл настройку до конца. Это единственная имеющаяся
    // отметка «дошёл», и она честнее любой догадки по числу запусков.
    if ((string) ($row['source'] ?? '') === 'клавиатура') { $keyboardInstalls[$id] = true; }
}

/* Чего вчера не было.
 *
 * Самый ценный сигнал во всей сводке: строка, которой раньше не
 * встречалось, означает путь, по которому до сих пор не ходили.
 * Сравниваем с предыдущей неделей, а не со вчерашним днём: редкая
 * беда, случившаяся позавчера, «новой» сегодня уже не является.
 */
$before = [];
for ($back = 1; $back <= 7; $back++) {
    $past = gmdate('Y-m-d', strtotime($day . ' -' . $back . ' day'));
    foreach (rows_for($past) as $row) {
        $before[shape((string) ($row['message'] ?? ''))] = true;
    }
}
$fresh = array_values(array_diff(array_keys($byShape), array_keys($before)));

/* Список бед — по числу задетых телефонов, а не по числу записей.
 *
 * Одна и та же поломка, повторившаяся у одного человека сорок раз,
 * — это один пострадавший, и наверх её пускать нельзя: она вытеснит
 * беду, которая случилась у десятерых по разу.
 */
$messages = [];
foreach ($byShape as $shape => $data) {
    $messages[] = [
        'shape' => $shape,
        'example' => $data['example'],
        'count' => $data['count'],
        'installs' => count($data['installs']),
        'new' => in_array($shape, $fresh, true),
    ];
}
usort($messages, static fn(array $a, array $b): int
    => [$b['installs'], $b['count']] <=> [$a['installs'], $a['count']]);
$messages = array_slice($messages, 0, 40);

/* Неделя одной строкой — чтобы на странице был не снимок, а движение. */
$week = [];
for ($back = 6; $back >= 0; $back--) {
    $past = gmdate('Y-m-d', strtotime($day . ' -' . $back . ' day'));
    $pastRows = rows_for($past);
    $pastInstalls = [];
    foreach ($pastRows as $row) { $pastInstalls[(string) ($row['install'] ?? '')] = true; }
    $week[] = ['day' => $past, 'installs' => count($pastInstalls), 'entries' => count($pastRows)];
}

arsort($byVersion);
arsort($byDevice);
arsort($byOS);

say(200, [
    'day' => $day,
    'installs' => count($installs),
    'entries' => count($rows),
    'reached_keyboard' => count($keyboardInstalls),
    'messages' => $messages,
    'new_shapes' => $fresh,
    'versions' => $byVersion,
    'devices' => array_slice($byDevice, 0, 15, true),
    'os' => $byOS,
    'week' => $week,
]);
