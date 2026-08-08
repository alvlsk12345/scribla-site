<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';

require_post();

$ip = client_ip();
if (too_often($ip)) { say(429, ['error' => 'Слишком часто. Попробуйте попозже.']); }

$in = body();
$email = mb_strtolower(trim((string) ($in['email'] ?? '')), 'UTF-8');

if (!looks_like_email($email)) {
    say(400, ['error' => 'Проверьте адрес — похоже, в нём опечатка']);
}

store('notify.jsonl', [
    'email' => $email,
    'at'    => gmdate('c'),
    'ip'    => $ip,
]);

say(200, ['message' => 'Записали. Напишем один раз — когда выйдет.']);
