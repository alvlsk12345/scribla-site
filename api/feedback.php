<?php
declare(strict_types=1);
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/profanity.php';

require_post();

$in = body();

$ip = client_ip();
if (too_often($ip)) {
    say(429, ['error' => pick($in, 'Слишком часто. Попробуйте попозже.',
                                  'Too often. Try again later.')]);
}
$message = trim((string) ($in['message'] ?? ''));
$email   = mb_strtolower(trim((string) ($in['email'] ?? '')), 'UTF-8');

if (mb_strlen($message, 'UTF-8') < 10) {
    say(400, ['error' => pick($in, 'Слишком коротко — из двух слов не понять, что случилось',
                                   "Too short — two words don't say what happened")]);
}
if (mb_strlen($message, 'UTF-8') > 2000) {
    say(400, ['error' => pick($in, 'Длиннее двух тысяч знаков не влезет',
                                   'Two thousand characters is the limit')]);
}
if ($email !== '' && !looks_like_email($email)) {
    say(400, ['error' => pick($in, 'Проверьте адрес — похоже, в нём опечатка',
                                   'Check the address — looks like a typo')]);
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
    ? pick($in, 'Отправлено. Это письмо посмотрят руками — так бывает.',
                'Sent. This one gets read by hand — it happens.')
    : pick($in, 'Спасибо. Прочитаем всё.',
                'Thank you. Everything gets read.')]);
