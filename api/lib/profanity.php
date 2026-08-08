<?php
declare(strict_types=1);

/* Фильтр брани — построчный перенос lib/profanity.js.
 *
 * Два файла с одной логикой — плохо, и держатся они рядом только
 * потому, что площадки две: PHP на общем хостинге и Node в облаке.
 * Расхождение между ними ловит test/profanity.php: он гоняет тот же
 * список случаев, что и test/run.js. Разошлись — тест красный.
 *
 * Задача фильтра — не «запретить», а «пометить»: письмо принимается
 * в любом случае, помеченное просто читают глазами.
 *
 * ГЛАВНЫЙ УРОК, оплаченный провалом на стенде. Первая версия убирала
 * из текста все разделители и склеивала строку в одно слово. Она
 * ловила спрятанное — и заодно метила «тебе баня» (стык даёт «еба»),
 * «всё бы ничего» («ебы»), «дам андроид» («манд») и, что убийственно
 * для страницы про переводы, «рубля» («бля»). Поэтому склейка теперь
 * не сплошная: слова остаются словами, склеиваются только цепочки
 * одиночных букв — та самая запись, ради которой приём и существует.
 */

final class Profanity
{
    /** Латиница и цифры, которыми подменяют кириллицу — по начертанию. */
    private const LOOKALIKE = [
        'a' => 'а', 'b' => 'в', 'c' => 'с', 'e' => 'е', 'h' => 'н',
        'k' => 'к', 'm' => 'м', 'o' => 'о', 'p' => 'р', 't' => 'т',
        'x' => 'х', 'y' => 'у', 'u' => 'и', 'n' => 'п', 'r' => 'г',
        '3' => 'е', '0' => 'о', '4' => 'ч', '6' => 'б', '9' => 'я',
        '1' => 'и', '7' => 'т', '@' => 'а', '|' => 'и',
    ];

    /* Семейства, которые нельзя искать простым вхождением: их буквы
     * слишком часто попадаются в середине обычных слов. У настоящей
     * брани корень стоит в начале, максимум после приставки. */
    private const ANCHORED = [
        '/^(?:за|на|вы|при|у|до|пере|под|разъ|раз|съ|объ|по|от|из|про|недо)?еб/u',
        '/^(?:на|по|о|за)?ху[йеяию]/u',
        '/^бля/u',
        '/^манда/u',
        '/^(?:на|по)?хер/u',
    ];

    private const ROOTS = [
        'пизд', 'пезд',
        'бляд', 'блят',
        'муде', 'мудо', 'мудак', 'мудил',
        'гандон', 'гондон',
        'залуп', 'дроч', 'пидор', 'пидар', 'педик',
        'сука', 'суки', 'суке', 'суку', 'сучар', 'сучк',
        'срак', 'срать', 'обосра', 'просра',
        'говн', 'гавн', 'дерьм',
        'долбо', 'шлюх',
        'придур', 'дебил', 'кретин', 'тупиц', 'ублюд',
        'мраз', 'падл', 'сволоч', 'урод',
    ];

    private const EN = [
        'fuck', 'shit', 'bitch', 'bastard', 'asshole', 'cunt',
        'motherfuck', 'wanker', 'twat', 'douche', 'retard',
        'faggot', 'whore', 'slut', 'dickhead', 'jackass',
    ];

    /* Начала слов, совпадающие с корнем по случайности. Список короткий
     * намеренно: растёт по живым промахам, а не по догадкам. */
    private const ALLOW = [
        'мудр',
        'мандарин', 'мандат', 'мандол',
        'херсон', 'хертфорд',
        'хутор', 'хулиган',
        'сукно', 'сукна', 'сукон',
        'дроздов', 'дрожж',
        'ебип',
    ];

    /** Текст → слова в нормальном виде. Границы слов сохраняются. */
    public static function words(string $input): array
    {
        $s = mb_strtolower($input, 'UTF-8');
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace_callback(
            '/[a-z0-9@|]/u',
            static fn(array $m): string => self::LOOKALIKE[$m[0]] ?? $m[0],
            $s
        );
        $s = preg_replace('/[^а-я]+/u', ' ', $s);
        $out = [];
        foreach (preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $out[] = preg_replace('/(.)\1+/u', '$1', $w);
        }
        return $out;
    }

    /** «с у к а» → «сука». Три подряд и больше: «в и о» бывает в речи. */
    public static function unspace(array $list): array
    {
        $out = [];
        $run = [];
        $flush = static function () use (&$run, &$out): void {
            if (count($run) >= 3) { $out[] = implode('', $run); }
            $run = [];
        };
        foreach ($list as $w) {
            if (mb_strlen($w, 'UTF-8') === 1) { $run[] = $w; }
            else { $flush(); $out[] = $w; }
        }
        $flush();
        return $out;
    }

    private static function allowed(string $w): bool
    {
        foreach (self::ALLOW as $a) {
            if (str_starts_with($w, $a)) { return true; }
        }
        return false;
    }

    private static function enWords(string $text): array
    {
        $s = preg_replace('/[^a-z]+/', ' ', mb_strtolower($text, 'UTF-8'));
        $out = [];
        foreach (preg_split('/\s+/', trim((string) $s), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $out[] = preg_replace('/(.)\1+/', '$1', $w);
        }
        return $out;
    }

    /** Что именно совпало — нужно при разборе промаха. */
    public static function found(string $text): array
    {
        if ($text === '') { return []; }

        $list = self::words($text);
        $ru = array_values(array_unique(array_merge($list, self::unspace($list))));
        $en = self::enWords($text);

        $hits = [];
        foreach (self::ANCHORED as $re) {
            foreach ($ru as $w) {
                if (!self::allowed($w) && preg_match($re, $w)) { $hits[] = $re; break; }
            }
        }
        foreach (self::ROOTS as $r) {
            foreach ($ru as $w) {
                if (!self::allowed($w) && str_contains($w, $r)) { $hits[] = $r; break; }
            }
        }
        foreach (self::EN as $word) {
            $needle = preg_replace('/(.)\1+/', '$1', $word);
            foreach ($en as $w) {
                if (str_contains($w, (string) $needle)) { $hits[] = $word; break; }
            }
        }
        return array_values(array_unique($hits));
    }

    public static function isFoul(string $text): bool
    {
        return self::found($text) !== [];
    }
}
