<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/profanity.php';

require_post();

$ip = client_ip();
if (too_often($ip)) { say(429, ['error' => 'Слишком часто. Попробуйте попозже.']); }

$in = body();
$message = trim((string) ($in['message'] ?? ''));
$email   = mb_strtolower(trim((string) ($in['email'] ?? '')), 'UTF-8');

if (mb_strlen($message, 'UTF-8') < 10) {
    say(400, ['error' => 'Слишком коротко — из двух слов не понять, что случилось']);
}
if (mb_strlen($message, 'UTF-8') > 2000) {
    say(400, ['error' => 'Длиннее двух тысяч знаков не влезет']);
}
if ($email !== '' && !looks_like_email($email)) {
    say(400, ['error' => 'Проверьте адрес — похоже, в нём опечатка']);
}

/* Мат не отбиваем в лицо. Человек, которого обозвали роботом, второй
 * раз не напишет, а среди грубых писем попадаются самые полезные.
 * Поэтому принимаем и помечаем, разбирает владелец. */
$foul = Profanity::isFoul($message);

store('feedback.jsonl', [
    'message' => $message,
    'email'   => $email,
    'foul'    => $foul,
    'at'      => gmdate('c'),
    'ip'      => $ip,
]);

say(200, ['message' => $foul
    ? 'Отправлено. Это письмо посмотрят руками — так бывает.'
    : 'Спасибо. Прочитаем всё.']);
