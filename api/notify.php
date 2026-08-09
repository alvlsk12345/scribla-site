<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/mailer.php';

require_post();

$in = body();

$ip = client_ip();
if (too_often($ip)) {
    say(429, ['error' => pick($in, 'Слишком часто. Попробуйте попозже.',
                                  'Too often. Try again later.')]);
}
$email = mb_strtolower(trim((string) ($in['email'] ?? '')), 'UTF-8');

if (!looks_like_email($email)) {
    say(400, ['error' => pick($in, 'Проверьте адрес — похоже, в нём опечатка',
                                   'Check the address — looks like a typo')]);
}

store('notify.jsonl', [
    'email' => $email,
    'at'    => gmdate('c'),
    'ip'    => $ip,
]);

mail_later('Scribla — ждут выхода: ' . $email,
    'В список подписки добавился адрес.' . "\n\n"
    . $email . "\n\n"
    . 'Страница: ' . (($in['lang'] ?? '') === 'en' ? 'английская' : 'русская') . "\n"
    . 'Когда: ' . gmdate('d.m.Y H:i') . ' UTC');

say(200, ['message' => pick($in, 'Записали. Напишем один раз — когда выйдет.',
                                   "Noted. We'll write once — when it ships.")]);
