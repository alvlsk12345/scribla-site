<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/mailer.php';

require_post();

$in = body();

$ip = client_ip();
if (too_often($ip)) {
    say(429, ['error' => pick($in, [
        'ru' => 'Слишком часто. Попробуйте попозже.',
        'en' => 'Too often. Try again later.',
        'es' => 'Demasiado a menudo. Inténtalo más tarde.',
        'zh' => '太频繁了，请稍后再试。',
    ])]);
}
$email = mb_strtolower(trim((string) ($in['email'] ?? '')), 'UTF-8');

if (!looks_like_email($email)) {
    say(400, ['error' => pick($in, [
        'ru' => 'Проверьте адрес — похоже, в нём опечатка',
        'en' => 'Check the address — looks like a typo',
        'es' => 'Revisa la dirección — parece que tiene una errata',
        'zh' => '请检查邮箱地址，看起来有笔误',
    ])]);
}

store('notify.jsonl', [
    'email' => $email,
    'at'    => gmdate('c'),
    'ip'    => $ip,
]);

mail_later('Scribla — ждут выхода: ' . $email,
    'В список подписки добавился адрес.' . "\n\n"
    . $email . "\n\n"
    . 'Страница: ' . lang_name($in) . "\n"
    . 'Когда: ' . gmdate('d.m.Y H:i') . ' UTC');

say(200, ['message' => pick($in, [
    'ru' => 'Записали. Напишем один раз — когда выйдет.',
    'en' => "Noted. We'll write once — when it ships.",
    'es' => 'Apuntado. Escribiremos una vez: cuando salga.',
    'zh' => '已记下。发布的时候我们只写一封信。',
])]);
