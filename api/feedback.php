<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/profanity.php';
require __DIR__ . '/lib/shots.php';
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
$message = trim((string) ($in['message'] ?? ''));
$email   = mb_strtolower(trim((string) ($in['email'] ?? '')), 'UTF-8');

if (mb_strlen($message, 'UTF-8') < 10) {
    say(400, ['error' => pick($in, [
        'ru' => 'Слишком коротко — из двух слов не понять, что случилось',
        'en' => "Too short — two words don't say what happened",
        'es' => 'Demasiado corto — con dos palabras no se entiende qué pasó',
        'zh' => '太短了——两个词说不清发生了什么',
    ])]);
}
if (mb_strlen($message, 'UTF-8') > 2000) {
    say(400, ['error' => pick($in, [
        'ru' => 'Длиннее двух тысяч знаков не влезет',
        'en' => 'Two thousand characters is the limit',
        'es' => 'Más de dos mil caracteres no caben',
        'zh' => '超过两千字就放不下了',
    ])]);
}
if ($email !== '' && !looks_like_email($email)) {
    say(400, ['error' => pick($in, [
        'ru' => 'Проверьте адрес — похоже, в нём опечатка',
        'en' => 'Check the address — looks like a typo',
        'es' => 'Revisa la dirección — parece que tiene una errata',
        'zh' => '请检查邮箱地址，看起来有笔误',
    ])]);
}

/* Мат не отбиваем в лицо. Человек, которого обозвали роботом, второй
 * раз не напишет, а среди грубых писем попадаются самые полезные.
 * Поэтому принимаем и помечаем, разбирает владелец. */
$foul = Profanity::isFoul($message);

$shots = shots_take();

store('feedback.jsonl', [
    'message' => $message,
    'email'   => $email,
    'foul'    => $foul,
    'shots'   => array_column($shots, 'file'),
    'at'      => gmdate('c'),
    'ip'      => $ip,
]);

/* Письмо владельцу. Журнал на сервере — это архив, а не уведомление:
 * пока в него не заглянешь, отзыв всё равно что не приходил. */
$head = mb_substr(preg_replace('/\s+/u', ' ', $message) ?? '', 0, 60, 'UTF-8');
$body = $message . "\n\n"
    . str_repeat('—', 20) . "\n"
    . 'Обратный адрес: ' . ($email !== '' ? $email : 'не оставили, ответить некуда') . "\n"
    . 'Страница: ' . lang_name($in) . "\n"
    . 'Когда: ' . gmdate('d.m.Y H:i') . " UTC\n"
    . 'Откуда: ' . $ip . "\n"
    . 'Скриншотов: ' . count($shots)
    . ($foul ? "\n\nФильтр отметил в тексте брань — письмо всё равно пришло." : '');

mail_later('Scribla — отзыв: ' . $head, $body, $email, shots_for_mail($shots));

say(200, ['message' => $foul
    ? pick($in, [
        'ru' => 'Отправлено. Это письмо посмотрят руками — так бывает.',
        'en' => 'Sent. This one gets read by hand — it happens.',
        'es' => 'Enviado. Este mensaje lo mirará una persona — a veces pasa.',
        'zh' => '已发送。这一条会有人手工看一遍——有时会这样。',
    ])
    : pick($in, [
        'ru' => 'Спасибо. Прочитаем всё.',
        'en' => 'Thank you. Everything gets read.',
        'es' => 'Gracias. Lo leemos todo.',
        'zh' => '谢谢。每一条我们都会读。',
    ])]);
