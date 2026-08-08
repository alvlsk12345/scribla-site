<?php
declare(strict_types=1);

/* То же, что test/run.js, но для PHP-версии фильтра. Случаи — общий
 * cases.json: две реализации обязаны отвечать одинаково. */

require __DIR__ . '/../api/lib/profanity.php';

$cases = json_decode((string) file_get_contents(__DIR__ . '/cases.json'), true);
$bad = 0;

foreach ($cases['foul'] as $s) {
    if (!Profanity::isFoul($s)) { echo "ПРОПУСТИЛ: " . json_encode($s, JSON_UNESCAPED_UNICODE) . "\n"; $bad++; }
}
foreach ($cases['clean'] as $s) {
    if (Profanity::isFoul($s)) {
        echo "ЛОЖНОЕ:    " . json_encode($s, JSON_UNESCAPED_UNICODE)
           . " → " . json_encode(Profanity::found($s), JSON_UNESCAPED_UNICODE) . "\n";
        $bad++;
    }
}

$total = count($cases['foul']) + count($cases['clean']);
if ($bad > 0) { echo "\n$bad из $total мимо.\n"; exit(1); }
echo "PHP: все $total проверок сошлись.\n";
