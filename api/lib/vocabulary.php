<?php
/**
 * EverShelf — the vocabulary layer.
 *
 * Product names, genres, brands, plurals and the "what does this title mean" rules are
 * DATA: api/vocab/<lang>.json. This file is the algorithm that reads them, and it must
 * stay language-neutral — no product word, no genre, no brand, and no preg_match on
 * vocabulary ever belongs here. Adding a language = adding a JSON file; a missing entry
 * never invents anything.
 *
 * Term syntax inside the JSON (one term is one array item):
 *   "pane"                a whole word
 *   "pomodor*"            a stem (matches pomodoro, pomodori, pomodorini…)
 *   "fette biscottate"    consecutive words (a phrase)
 *   "estratto+vaniglia"   all these words, in any order
 */

/** Vocabulary file for a language, English as the fallback of the fallback. */
function evershelfVocabPath(string $lang): string {
    $lang = preg_replace('/[^a-z]/', '', strtolower($lang));
    $dir  = __DIR__ . '/../vocab/';
    if ($lang !== '' && is_file($dir . $lang . '.json')) {
        return $dir . $lang . '.json';
    }
    if (is_file($dir . 'en.json')) {
        return $dir . 'en.json';
    }
    return $dir . 'it.json';
}

/** The vocabulary of a language (cached). */
function evershelfVocab(?string $lang = null): array {
    static $cache = [];
    $lang = strtolower(trim((string)($lang ?? env('APP_LANG', 'it'))));
    if ($lang === '') {
        $lang = 'it';
    }
    if (!isset($cache[$lang])) {
        $path = evershelfVocabPath($lang);
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        $cache[$lang] = is_array($data) ? $data : [];
    }
    return $cache[$lang];
}

/** True when the character is one the tokenizer keeps INSIDE a word (data, not code). */
function vocabIsSeparator(string $char): bool {
    static $seps = null;
    if ($seps === null) {
        $list = (string)(evershelfVocab()['word_separators'] ?? " \t\n\r.,;:!?()[]{}\"'«»…/\\-–—_+*&%#@|");
        $seps = [];
        foreach (mb_str_split($list, 1, 'UTF-8') as $c) {
            $seps[$c] = true;
        }
    }
    return isset($seps[$char]);
}

/** Lowercase + diacritics folded, spaces collapsed (no regex, works for any script). */
function vocabFold(string $text): string {
    $fold = (array)(evershelfVocab()['fold'] ?? []);
    $out  = mb_strtolower(strtr(trim($text), $fold), 'UTF-8');
    $collapsed = '';
    $prevSpace = false;
    foreach (mb_str_split($out, 1, 'UTF-8') as $ch) {
        $isSpace = $ch === ' ';
        if ($isSpace && $prevSpace) {
            continue;
        }
        $collapsed .= $ch;
        $prevSpace = $isSpace;
    }
    return $collapsed;
}


/**
 * Words of a name, folded. Language-neutral: a "word" is any run of characters the
 * vocabulary does not list as separators, so Latin, accented, Cyrillic and CJK all work.
 *
 * @return list<string>
 */
function vocabTokens(string $text): array {
    $fold   = vocabFold($text);
    $tokens = [];
    $cur    = '';
    foreach (mb_str_split($fold, 1, 'UTF-8') as $ch) {
        if ($ch === ' ' || vocabIsSeparator($ch)) {
            if ($cur !== '') {
                $tokens[] = $cur;
                $cur = '';
            }
            continue;
        }
        $cur .= $ch;
    }
    if ($cur !== '') {
        $tokens[] = $cur;
    }
    return $tokens;
}

/**
 * Best match of one term against tokens: [startIndex, wordCount] or null.
 * "+" terms (all words, any order) score by the earliest matched part and the
 * number of required parts — still no product word in the algorithm.
 *
 * @return array{0:int,1:int}|null
 */
function vocabTermMatchScore(array $tokens, string $term): ?array {
    $term = trim($term);
    if ($term === '' || $tokens === []) {
        return null;
    }
    if (str_contains($term, '+')) {
        $parts = explode('+', $term);
        $earliest = null;
        foreach ($parts as $part) {
            $score = vocabTermMatchScore($tokens, $part);
            if ($score === null) {
                return null;
            }
            if ($earliest === null || $score[0] < $earliest) {
                $earliest = $score[0];
            }
        }
        return [$earliest, count($parts)];
    }
    $isStem = str_ends_with($term, '*');
    $words  = vocabTokens(rtrim($term, '*'));
    if ($words === []) {
        return null;
    }
    $count = count($words);
    $last  = $count - 1;
    $total = count($tokens);
    for ($i = 0; $i <= $total - $count; $i++) {
        $ok = true;
        foreach ($words as $j => $word) {
            $token = $tokens[$i + $j] ?? '';
            $hit = ($isStem && $j === $last) ? str_starts_with($token, $word) : $token === $word;
            if (!$hit) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return [$i, $count];
        }
    }
    return null;
}

/** Does an already tokenized name satisfy one term of the vocabulary? */
function vocabMatchesTerm(array $tokens, string $term): bool {
    return vocabTermMatchScore($tokens, $term) !== null;
}

/** Does the token list satisfy ANY of these terms? */
function vocabMatchesTermsTokens(array $tokens, array $terms): bool {
    foreach ($terms as $term) {
        if (vocabMatchesTerm($tokens, (string)$term)) {
            return true;
        }
    }
    return false;
}

/** Does the NAME satisfy any of these terms? */
function vocabNameHasAnyTerm(string $name, array $terms): bool {
    return vocabMatchesTermsTokens(vocabTokens($name), $terms);
}

/**
 * Best (cat, pos, len) among the terms of one rule, or null when nothing hits.
 *
 * @return array{cat:string,pos:int,len:int}|null
 */
function vocabBestTermInRule(array $tokens, array $rule): ?array {
    $cat = (string)($rule['cat'] ?? '');
    if ($cat === '') {
        return null;
    }
    $best = null;
    foreach ((array)($rule['terms'] ?? []) as $term) {
        $score = vocabTermMatchScore($tokens, (string)$term);
        if ($score === null) {
            continue;
        }
        [$pos, $len] = $score;
        if ($best === null
            || $len > $best['len']
            || ($len === $best['len'] && $pos < $best['pos'])) {
            $best = ['cat' => $cat, 'pos' => $pos, 'len' => $len];
        }
    }
    return $best;
}

/**
 * Local category of a product title.
 *
 * Language-neutral ranking (no product word, no Italian rule):
 *   1. among category-term hits: longer phrase wins, then the earliest word in
 *      the title (the genre leads the name — "Biscotti Zuppalatte" is snack, not
 *      a drink just because a later token also sits in bevande),
 *   2. brand → category only when the title itself named no genre
 *      ("Baiocchi" alone → snack; "Passata La Molisana" → conserve).
 */
function vocabCategoryOfName(string $name, string $brand = ''): string {
    $vocab  = evershelfVocab();
    $tokens = vocabTokens(trim($name . ' ' . $brand));
    if ($tokens === []) {
        return 'altro';
    }
    $best = null;
    foreach ((array)($vocab['categories'] ?? []) as $rule) {
        $hit = vocabBestTermInRule($tokens, (array)$rule);
        if ($hit === null) {
            continue;
        }
        if ($best === null
            || $hit['len'] > $best['len']
            || ($hit['len'] === $best['len'] && $hit['pos'] < $best['pos'])) {
            $best = $hit;
        }
    }
    if ($best !== null) {
        return $best['cat'];
    }
    foreach ((array)($vocab['brands'] ?? []) as $rule) {
        if (vocabMatchesTermsTokens($tokens, (array)($rule['terms'] ?? []))) {
            return (string)($rule['cat'] ?? 'altro');
        }
    }
    return 'altro';
}

/**
 * Open Food Facts slug → local category map (data/off_categories.json).
 * Language-neutral: OFF tags are English. A null entry means catch-all.
 *
 * @return array<string, string|null>
 */
function evershelfOffCategoryMap(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $path = dirname(__DIR__, 2) . '/data/off_categories.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    $by   = is_array($data['by_slug'] ?? null) ? $data['by_slug'] : [];
    $out  = [];
    foreach ($by as $slug => $cat) {
        if (!is_string($slug) || $slug === '') {
            continue;
        }
        $key = strtolower(trim($slug));
        if ($cat === null || $cat === '') {
            $out[$key] = null;
            continue;
        }
        if (is_string($cat)) {
            $out[$key] = strtolower(trim($cat));
        }
    }
    $map = $out;
    return $map;
}

/**
 * Map an OFF (or OFF-like) slug to a local category.
 * Returns the local slug, or null when the tag is a catch-all / unknown
 * (caller must guess from the product title via vocabCategoryOfName).
 */
function vocabMapOffSlug(string $slug): ?string {
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return null;
    }
    // Strip a leading "en:" / "it:" / … if the caller forgot.
    if (preg_match('/^[a-z]{2}:(.+)$/', $slug, $m)) {
        $slug = $m[1];
    }
    $map = evershelfOffCategoryMap();
    if (!array_key_exists($slug, $map)) {
        return null;
    }
    $cat = $map[$slug];
    return ($cat !== null && $cat !== '') ? $cat : null;
}

/** Does the title carry a pack size ("500 g", "1,5 l")? Format, not vocabulary. */
function vocabHasPackSize(string $name): bool {
    $units  = (array)(evershelfVocab()['pack_size_units'] ?? []);
    $tokens = vocabTokens($name);
    $count  = count($tokens);
    for ($i = 0; $i < $count - 1; $i++) {
        $number = str_replace(',', '.', $tokens[$i]);
        if (is_numeric($number) && in_array($tokens[$i + 1], $units, true)) {
            return true;
        }
    }
    return false;
}

/** Fresh produce this household counts by piece (and not a preparation of it). */
function vocabIsPieceProduce(string $name): bool {
    $vocab = evershelfVocab();
    if (vocabNameHasAnyTerm($name, (array)($vocab['produce_preparations'] ?? [])) || vocabHasPackSize($name)) {
        return false;
    }
    return vocabNameHasAnyTerm($name, (array)($vocab['piece_produce'] ?? []));
}

/** A preparation of a product: never the fresh article of the same word. */
function vocabLooksProcessed(string $name): bool {
    return vocabNameHasAnyTerm($name, (array)(evershelfVocab()['processed'] ?? []));
}

/** "Insalata di riso" is a prepared dish, not leafy produce. */
function vocabIsPreparedSalad(string $name, string $brand = ''): bool {
    $rules  = (array)(evershelfVocab()['prepared_salad'] ?? []);
    $tokens = vocabTokens($name);
    foreach ((array)($rules['phrases'] ?? []) as $phrase) {
        if (vocabMatchesTerm($tokens, implode(' ', (array)$phrase))) {
            return true;
        }
    }
    foreach ((array)($rules['requires'] ?? []) as $pair) {
        $all = true;
        foreach ((array)$pair as $word) {
            if (!vocabMatchesTerm($tokens, (string)$word)) {
                $all = false;
                break;
            }
        }
        if ($all) {
            return true;
        }
    }
    if ($brand !== '') {
        $brandTokens = vocabTokens($brand);
        foreach ((array)($rules['brands'] ?? []) as $word) {
            if (vocabMatchesTerm($brandTokens, (string)$word)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Number forms of a word: the curated table first ("Pomodoro"/"Pomodori"), nothing else.
 * An unknown word has no plural to invent.
 *
 * @return array{singular:string,plural:string,gender:string}|null
 */
function vocabNumberForms(string $word): ?array {
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach ((array)(evershelfVocab()['number_forms'] ?? []) as $canonical => $forms) {
            if (!is_array($forms) || count($forms) < 2) {
                continue;
            }
            foreach ([$canonical, $forms[0], $forms[1]] as $key) {
                $fold = vocabFold((string)$key);
                if ($fold !== '' && !isset($index[$fold])) {
                    $index[$fold] = ['singular' => (string)$forms[0], 'plural' => (string)$forms[1],
                                     'gender' => (string)($forms[2] ?? '')];
                }
            }
        }
    }
    return $index[vocabFold($word)] ?? null;
}

/** Dictionary form of a word (the singular when the table knows it), else the word. */
function vocabLemma(string $word): string {
    $forms = vocabNumberForms($word);
    return $forms !== null ? vocabFold($forms['singular']) : vocabFold($word);
}

/** Index of the curated agreement forms (one group per adjective), data-driven. */
function vocabAgreementIndex(): array {
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach ((array)(evershelfVocab()['agreement_forms'] ?? []) as $group => $forms) {
            foreach ((array)$forms as $form) {
                $fold = vocabFold((string)$form);
                if ($fold !== '') {
                    $index[$fold] = $group;
                }
            }
        }
    }
    return $index;
}

/**
 * The same word at another number ("Pomodoro" ↔ "Pomodori"): the curated table first,
 * then the agreement groups ("medie" ↔ "medio"), then the per-language suffix pairs of
 * the JSON — compared with mb_substr, never a regex.
 */
function vocabSameLemmaWord(string $a, string $b): bool {
    $fa = vocabFold($a);
    $fb = vocabFold($b);
    if ($fa === '' || $fb === '' || $fa === $fb) {
        return $fa === $fb && $fa !== '';
    }
    $formsA = vocabNumberForms($fa);
    $formsB = vocabNumberForms($fb);
    if ($formsA !== null && $formsB !== null && $formsA['singular'] === $formsB['singular']) {
        return true;
    }
    $agreements = vocabAgreementIndex();
    if (isset($agreements[$fa], $agreements[$fb]) && $agreements[$fa] === $agreements[$fb]) {
        return true;
    }
    $len = min(mb_strlen($fa, 'UTF-8'), mb_strlen($fb, 'UTF-8'));
    if ($len < 4) {
        return false;
    }
    foreach ((array)(evershelfVocab()['plural_pairs'] ?? []) as $pair) {
        $suffixA = (string)($pair[0] ?? '');
        $suffixB = (string)($pair[1] ?? '');
        if ($suffixA === '' || $suffixB === '') {
            continue;
        }
        $aEndsA = mb_substr($fa, -mb_strlen($suffixA, 'UTF-8'), null, 'UTF-8') === $suffixA;
        $bEndsB = mb_substr($fb, -mb_strlen($suffixB, 'UTF-8'), null, 'UTF-8') === $suffixB;
        $aEndsB = mb_substr($fa, -mb_strlen($suffixB, 'UTF-8'), null, 'UTF-8') === $suffixB;
        $bEndsA = mb_substr($fb, -mb_strlen($suffixA, 'UTF-8'), null, 'UTF-8') === $suffixA;
        $cutA = mb_substr($fa, 0, mb_strlen($fa, 'UTF-8') - mb_strlen($suffixA, 'UTF-8'), 'UTF-8');
        $cutB = mb_substr($fb, 0, mb_strlen($fb, 'UTF-8') - mb_strlen($suffixB, 'UTF-8'), 'UTF-8');
        if ($aEndsA && $bEndsB && $cutA === $cutB) {
            return true;
        }
        $cutA2 = mb_substr($fa, 0, mb_strlen($fa, 'UTF-8') - mb_strlen($suffixB, 'UTF-8'), 'UTF-8');
        $cutB2 = mb_substr($fb, 0, mb_strlen($fb, 'UTF-8') - mb_strlen($suffixA, 'UTF-8'), 'UTF-8');
        if ($aEndsB && $bEndsA && $cutA2 === $cutB2) {
            return true;
        }
    }
    return false;
}

/**
 * Two TITLES at another number, word by word: "Uova Medie" ↔ "Uovo Medio" (only the
 * words the table/pairs know may move; a variety or a brand never does).
 */
function vocabSameLemma(string $a, string $b): bool {
    $ta = vocabTokens($a);
    $tb = vocabTokens($b);
    if ($ta === [] || count($ta) !== count($tb)) {
        return false;
    }
    foreach ($ta as $i => $word) {
        if (!vocabSameLemmaWord($word, $tb[$i])) {
            return false;
        }
    }
    return true;
}
