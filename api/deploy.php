<?php
declare(strict_types=1);

/* Самообновление сайта из GitHub.
 *
 * Почему так, а не по-человечески. На этом хостинге нет SSH (Node тоже
 * нет), а раздел добавления SSH-ключей в панели отдаёт ошибку бэкенда.
 * Остаётся файловый менеджер — то есть архив руками при каждой правке
 * запятой. Эта ручка убирает ручную работу: она сама скачивает срез
 * ветки main из публичного репозитория и раскладывает файлы.
 *
 * Чем это НЕ является: файлы сайта сюда загрузить нельзя. Адрес
 * репозитория зашит в коде, и даже с ключом в руках посторонний добьётся
 * ровно одного — чтобы сайт переустановил сам себя из своего же
 * исходника.
 *
 * Единственное исключение — настройки почты в теле запроса. Они ложатся
 * в scribla-data/mail.json, то есть ВЫШЕ public_html и вне досягаемости
 * из веба; ничего исполняемого в них нет. Исключение понадобилось потому,
 * что пароль от ящика — единственный секрет, который нельзя ни завести
 * на сервере самому (его выдаёт хостинг), ни положить в репозиторий
 * (он публичный). Тело, а не строка адреса, — чтобы пароль не осел
 * в истории команд и в журналах прокси.
 *
 * Ключ тот же, что у страницы отзывов: заводить второй секрет ради
 * действия, которое не даёт новых возможностей, — лишняя сущность.
 * Метод только POST: по ссылке из письма или префетчем не сработает.
 */

require __DIR__ . '/lib/http.php';

const REPO = 'https://codeload.github.com/alvlsk12345/scribla-site/tar.gz/';

/* Что раскладываем. Список закрытый: всё, чего в нём нет, на сервер
 * не попадёт, как бы оно ни называлось в архиве. */
const TAKE = ['index.html', 'privacy.html', 'support.html', '.htaccess', 'assets', 'api', 'en'];

/* Каталоги, которые заменяются целиком. Иначе удалённый в репозитории
 * файл остался бы на сервере навсегда. `api` сюда не входит намеренно:
 * мы сейчас исполняемся изнутри него. */
const REPLACE_WHOLE = ['assets'];

/* Отправленные в отставку файлы. Выкладка умеет только докладывать,
 * поэтому убрать что-то с сервера можно единственным способом —
 * назвать это здесь. Список короткий и разбирается вручную. */
const REMOVE = ['api/flush.php'];

require_post();

$keyFile = data_dir() . '/admin.key';
$key = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
if ($key === '') { say(404, ['error' => 'Не найдено']); }
if (!hash_equals($key, (string) ($_GET['key'] ?? ''))) { say(403, ['error' => 'Нет']); }

$root = dirname(__DIR__);              // public_html
$tmp  = data_dir() . '/deploy';
$log  = [];

// ------------------------------------------------------------- скачивание

@mkdir($tmp, 0700, true);
$tarGz = $tmp . '/main.tar.gz';
@unlink($tarGz);

/* Забираем не ветку, а конкретный коммит — и это оплачено промахом.
 *
 * Архив ветки codeload отдаёт из кэша CDN. Две выкладки подряд: вторая
 * привезла срез до второго коммита, сайт молча остался прежним, а сам
 * `deploy.php` при этом отчитался «Выложено». Полчаса ушло на поиски
 * несуществующей поломки в PHP.
 *
 * Хэш коммита — неизменяемый адрес: что попросили, то и приехало,
 * а кэш из вредителя превращается в ускорение. */
$ref = (string) ($_GET['ref'] ?? '');
if ($ref !== '' && !preg_match('/^[0-9a-f]{40}$/', $ref)) {
    say(400, ['error' => 'Непонятный коммит']);
}
$url = REPO . ($ref !== '' ? $ref : 'refs/heads/main');

$bytes = fetch($url);
if ($bytes === null || strlen($bytes) < 1024) {
    say(502, ['error' => 'Не скачался архив из GitHub', 'log' => $log]);
}
file_put_contents($tarGz, $bytes);
$log[] = 'скачано ' . strlen($bytes) . ' байт' . ($ref !== '' ? ', коммит ' . substr($ref, 0, 7) : ', ветка main');

// ------------------------------------------------------------- распаковка

$work = $tmp . '/x';
rm_rf($work);
@mkdir($work, 0700, true);

try {
    $tar = $tmp . '/main.tar';
    @unlink($tar);
    (new PharData($tarGz))->decompress();      // .tar.gz → .tar
    (new PharData($tar))->extractTo($work, null, true);
    @unlink($tar);
} catch (Throwable $e) {
    say(500, ['error' => 'Не распаковался архив: ' . $e->getMessage(), 'log' => $log]);
}

/* Имя папки внутри архива зависит от того, что просили: у ветки это
 * `scribla-site-main`, у коммита — `scribla-site-<хэш>`. Не угадываем,
 * а смотрим: папка там всегда ровно одна. */
$dirs = array_values(array_filter((array) glob($work . '/*'), 'is_dir'));
if (count($dirs) !== 1) { say(500, ['error' => 'Архив выглядит не так, как ожидали', 'log' => $log]); }
$src = $dirs[0];
$log[] = 'распаковано';

// ------------------------------------------------------------- раскладка

foreach (TAKE as $item) {
    $from = $src . '/' . $item;
    $to   = $root . '/' . $item;
    if (!file_exists($from)) { $log[] = 'нет в архиве: ' . $item; continue; }

    if (in_array($item, REPLACE_WHOLE, true) && is_dir($to)) { rm_rf($to); }
    copy_over($from, $to);
    $log[] = 'обновлено: ' . $item;
}

foreach (REMOVE as $gone) {
    $p = $root . '/' . $gone;
    if (is_file($p) && @unlink($p)) { $log[] = 'убрано: ' . $gone; }
}

$log[] = stamp_assets($root);

/* Ключ приёма журналов заводит сервер и показывает один раз.
 *
 * Положить его сюда больше нечем: SSH на хостинге нет, админка только
 * читает, а репозиторий публичный — в коде такому файлу не место.
 * Оставался файловый менеджер панели, то есть ручная работа при каждой
 * смене ключа.
 *
 * Генерируем на сервере, а не присылаем в запросе, и это важнее, чем
 * кажется: присланный ключ прошёл бы через строку команды, историю
 * терминала и журналы прокси. Здесь он не путешествует вовсе — рождается
 * на месте и один раз показывается в ответе, чтобы попасть в сборку
 * приложения (`GOLOS_LOG_KEY` в Config.xcconfig).
 *
 * Второй раз не покажется: существующий ключ ручка не трогает и не
 * отдаёт. Потерялся — удалите файл в панели, следующая выкладка заведёт
 * новый; старые сборки приложения после этого перестанут доходить,
 * что и правильно.
 */
$freshKeys = [];
foreach (['log' => 'приёма журналов', 'report' => 'чтения сводок'] as $name => $what) {
    $file = data_dir() . '/' . $name . '.key';
    if (is_file($file) && trim((string) file_get_contents($file)) !== '') { continue; }

    $candidate = bin2hex(random_bytes(24));
    if (@file_put_contents($file, $candidate, LOCK_EX) !== false) {
        @chmod($file, 0600);
        $freshKeys[$name . '_key'] = $candidate;
        $log[] = 'ключ ' . $what . ' заведён — показан ниже, второй раз не покажется';
    } else {
        $log[] = 'ключ ' . $what . ' завести не удалось: ' . $file;
    }
}

/* Настройки почты, если их прислали.
 *
 * Не прислали — ничего не делаем: лежащий на сервере mail.json остаётся
 * нетронутым, и выкладка идёт как раньше. Так что забыть про них нельзя
 * нечаянно испортить почту: молчание значит «оставь как есть».
 *
 * Пароль обратно не показываем никогда — ни в ответе, ни в журнале
 * выкладки. Показать его было бы удобно и ровно один раз полезно,
 * а осел бы он в терминале навсегда.
 */
$sent = body();
$mail = $sent['mail'] ?? null;
if (is_array($mail)) {
    foreach (['user', 'pass', 'to'] as $need) {
        if (trim((string) ($mail[$need] ?? '')) === '') {
            say(400, ['error' => 'В настройках почты нет поля ' . $need]);
        }
    }
    /* Берём только известные поля. Прислать сюда что-нибудь своё нельзя:
     * mail.json читает мейлер, и лишнее поле в нём — это ошибка, которую
     * потом ищут в SMTP. */
    $clean = [];
    foreach (['host', 'port', 'secure', 'user', 'pass', 'to', 'from_name'] as $field) {
        if (isset($mail[$field]) && $mail[$field] !== '') { $clean[$field] = $mail[$field]; }
    }

    $file = data_dir() . '/mail.json';
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($file, $json, LOCK_EX) === false) {
        say(500, ['error' => 'Не удалось записать настройки почты']);
    }
    @chmod($file, 0600);
    $log[] = 'почта настроена: ' . $clean['user'] . ' → ' . $clean['to'];
}

/* Ключи для ручки AI — по той же причине и тем же способом, что почта.
 *
 * Их два. `ollama` — настоящий ключ от чужого платного сервиса; он не
 * может лежать в публичном репозитории и его нельзя завести на сервере
 * самому. `ai` — наш собственный, которым приложение подписывает
 * обращения; он тоже не для репозитория, потому что вместе с кодом
 * ручки он и есть весь пропуск.
 *
 * Молчание значит «оставь как есть» — как и с почтой. Обратно ни один
 * из них не показываем: увидеть их ещё раз незачем, а осесть в терминале
 * они успеют навсегда.
 *
 * Имена файлов закрытым списком. Через это место когда-нибудь захочется
 * положить «ещё один маленький файлик», и вот тогда произвольное имя
 * превратит выкладку в запись чего угодно куда угодно.
 */
$keys = $sent['keys'] ?? null;
if (is_array($keys)) {
    foreach (['ai' => 'ai.key', 'ollama' => 'ollama.key'] as $field => $name) {
        $value = trim((string) ($keys[$field] ?? ''));
        if ($value === '') { continue; }
        $file = data_dir() . '/' . $name;
        if (@file_put_contents($file, $value . "\n", LOCK_EX) === false) {
            say(500, ['error' => 'Не удалось записать ' . $name]);
        }
        @chmod($file, 0600);
        $log[] = $name . ' записан, ' . strlen($value) . ' знаков';
    }
}

rm_rf($work);
@unlink($tarGz);

/* Отметку о выкладке кладём рядом с данными: по ней видно, когда сайт
 * обновлялся, не заходя в панель. */
@file_put_contents(data_dir() . '/deployed.txt', gmdate('c') . "\n", FILE_APPEND);

say(200, ['message' => 'Выложено', 'at' => gmdate('c'), 'ref' => $ref, 'log' => $log] + $freshKeys);

// ------------------------------------------------------------- утилиты

function fetch(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'scribla-deploy',
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close() с PHP 8.0 ничего не делает, а с 8.5 ещё и ругается
        // в вывод — предупреждение вклеивалось перед JSON и ломало ответ.
        if (is_string($out) && $code === 200) { return $out; }
    }
    if (ini_get('allow_url_fopen')) {
        $out = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 60, 'user_agent' => 'scribla-deploy'],
        ]));
        if (is_string($out)) { return $out; }
    }
    return null;
}

/* Отпечаток версии в ссылках на стили и скрипт.
 *
 * Зачем это вообще. Статику здесь отдаёт nginx впереди Apache, до
 * `.htaccess` она не доходит — проверено: HTML получает заголовок
 * nosniff из наших правил, а `assets/site.js` нет. nginx ставит свой
 * кэш на год, и управлять им мы не можем. Один день это стоило того,
 * что кнопка переводов на живом сайте писала «скоро откроется», хотя
 * сервер уже отдавал файл с рабочей ссылкой.
 *
 * Заголовками не побороть — значит меняем адрес. HTML отдаётся с
 * max-age=0, то есть свежий всегда; подставляем в ссылку отпечаток
 * содержимого, и браузер видит новый адрес — а старый пусть лежит
 * в кэше хоть год, он никому не нужен.
 *
 * `fonts.css` не штампуем: он объявляет четыре неизменных файла
 * шрифтов и меняться ему незачем. Если однажды поменяется — правьте
 * заодно и `site.css`, тогда отпечаток обновится у обоих.
 *
 * Ролики штампуем по той же причине и по горькому опыту: звук в них
 * пересобирали, сервер отдавал новый файл (размер сходился байт в байт),
 * а всякий, кто открывал сайт раньше, ещё год слушал бы старый из
 * своего кэша. Постер не штампуем — картинка первого кадра не менялась.
 */
function stamp_assets(string $root): string
{
    $marks = [];
    foreach (['site.css', 'site.js', 'metrika.js',
              'video/hero-ru.mp4', 'video/hero-en.mp4'] as $name) {
        $f = $root . '/assets/' . $name;
        if (is_file($f)) { $marks[$name] = substr(md5_file($f) ?: '', 0, 8); }
    }
    if (!$marks) { return 'нечего штамповать'; }

    $pages = array_merge(
        glob($root . '/*.html') ?: [],
        glob($root . '/*/*.html') ?: []
    );
    $touched = 0;
    foreach ($pages as $page) {
        $html = (string) file_get_contents($page);
        $before = $html;
        foreach ($marks as $name => $mark) {
            $q = preg_quote($name, '~');
            // Ловим и «assets/…», и «../assets/…», со старым штампом и без.
            $html = preg_replace(
                '~((?:\.\./)?assets/' . $q . ')(\?v=[0-9a-f]+)?~',
                '$1?v=' . $mark,
                $html
            );
        }
        if ($html !== $before) { file_put_contents($page, $html); $touched++; }
    }
    return 'проштамповано страниц: ' . $touched . ' (' . implode(', ', $marks) . ')';
}

function copy_over(string $from, string $to): void
{
    if (is_dir($from)) {
        if (!is_dir($to)) { @mkdir($to, 0755, true); }
        foreach (scandir($from) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            copy_over($from . '/' . $e, $to . '/' . $e);
        }
        return;
    }
    @copy($from, $to);
    @chmod($to, 0644);
}

function rm_rf(string $p): void
{
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (!is_dir($p)) { return; }
    foreach (scandir($p) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        rm_rf($p . '/' . $e);
    }
    @rmdir($p);
}
