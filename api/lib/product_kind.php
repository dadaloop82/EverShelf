<?php
/**
 * EverShelf — product "kind" (genere) resolution.
 *
 * The user wants every article title to start with what the product actually is
 * ("Yogurt Fiori di latte", "Formaggio Fiori di latte") because the generic type is
 * often missing from the name printed on the label.
 *
 * Resolution order (cheap first, AI last, cached):
 *   1. the curated Italian dictionary below (the same one computeShoppingName()
 *      has always used for the Bring!/shopping name),
 *   2. the signature cache (data/product_kind_cache.json) so similar products
 *      reuse the genre already resolved for the family,
 *   3. a single one-word Gemini classification (cached in
 *      data/shopping_name_cache.json, daily-capped by GEMINI_CLASSIFY_DAILY_MAX),
 *   4. the localized app category as a last resort.
 *
 * The dictionaries live here (and not inline in api/index.php) because both
 * computeShoppingName() and the kind resolver must read the *same* vocabulary:
 * scripts/test-product-kind-prefix.php locks that down.
 */

/** Stop words / filler adjectives dropped before looking a token up. */
function evershelfShoppingStopWords(): array {
    return ['di','del','della','dei','degli','delle','da','in','con','per','su', 'a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli','allo', 'parzialmente','scremato','uht','bio','light','freschi','fresca','fresco'];
}

/**
 * Multi-word product types, checked against the whole lowercase name BEFORE the
 * single-token lookup ("Panna da cucina" must not collapse to "Panna").
 */
function evershelfShoppingPhraseMap(): array {
    return [
        // Breadcrumbs (MUST come before generic "pane")
        'pangrattato'           => 'Pangrattato',
        'pan grattato'          => 'Pangrattato',
        'pane grattato'         => 'Pangrattato',
        'pane grattugiato'      => 'Pangrattato',
        'pan grattugiato'       => 'Pangrattato',
        // Cooking cream (MUST come before generic "panna")
        'panna da cucina'       => 'Panna da cucina',
        'panna cucina'          => 'Panna da cucina',
        'panna chef'            => 'Panna da cucina',
        // Tea (must not collapse to "Limone" via token)
        'tè al limone'          => 'Tè al limone',
        'te al limone'          => 'Tè al limone',
        'the al limone'         => 'Tè al limone',
        'panna acida'           => 'Panna acida',
        // Tomato preparations (MUST come before generic "pomodoro/pomodori")
        'passata di pomodoro'   => 'Passata',
        'passata pomodoro'      => 'Passata',
        'polpa di pomodoro'     => 'Polpa di pomodoro',
        'polpa pomodoro'        => 'Polpa di pomodoro',
        'sugo al pomodoro'      => 'Sugo',
        'sugo di pomodoro'      => 'Sugo',
        'salsa di pomodoro'     => 'Sugo',
        'pomodori pelati'       => 'Pelati',
        'pomodoro pelato'       => 'Pelati',
        'datterini pelati'      => 'Pelati',
        'pelati'                => 'Pelati',
        // Frozen / prep vegetables
        'misto soffritto'       => 'Misto soffritto',
        'misto per soffritto'   => 'Misto soffritto',
        // Plant-based milks (MUST come before generic "latte")
        'latte condensato'      => 'Latte condensato',
        'latte evaporato'       => 'Latte condensato',
        'latte di soia'         => 'Latte di soia',
        'latte soia'            => 'Latte di soia',
        'latte vegetale'        => 'Latte vegetale',
        'latte di mandorla'     => 'Latte di mandorla',
        'latte mandorla'        => 'Latte di mandorla',
        'latte di avena'        => 'Latte di avena',
        'latte avena'           => 'Latte di avena',
        'latte di riso'         => 'Latte di riso',
        'latte riso'            => 'Latte di riso',
        'latte di cocco'        => 'Latte di cocco',
        'latte cocco'           => 'Latte di cocco',
        // Baked bakery — different from bread
        'fette biscottate'      => 'Fette biscottate',
        'pan di spagna'         => 'Pan di Spagna',
        // Specific vinegars
        'aceto balsamico'       => 'Aceto balsamico',
        'glassa balsamico'      => 'Aceto balsamico',
        'glassa balsamic'       => 'Aceto balsamico',
        // Cold cuts — specific cuts
        'prosciutto cotto'      => 'Prosciutto cotto',
        // Flour subtypes (MUST come before generic "farina")
        'farina di riso'        => 'Farina di riso',
        'farina riso'           => 'Farina di riso',
        'farina di mais'        => 'Farina di mais',
        'farina mais'           => 'Farina di mais',
        'farina integrale'      => 'Farina integrale',
        'farina 00'             => 'Farina',
        // Roux / sugar subtypes
        'zucchero di canna'     => 'Zucchero di canna',
        'zucchero canna'        => 'Zucchero di canna',
        'zucchero velo'         => 'Zucchero a velo',
        'zucchero a velo'       => 'Zucchero a velo',
        // Fresh pasta
        'pasta fresca'          => 'Pasta fresca',
        // Broth / stock
        'brodo vegetale'        => 'Brodo',
        'brodo pollo'           => 'Brodo',
        'brodo manzo'           => 'Brodo',
        // Mixed vegetable purée / passato (MUST come before generic carote/patate)
        'passato di verdure'    => 'Verdure',
        'passato di patate'     => 'Verdure',
        // Water
        'acqua frizzante'       => 'Acqua',
        'acqua gassata'         => 'Acqua',
        'acqua minerale'        => 'Acqua',
        // Aroma / flavouring
        'aroma vaniglia'        => 'Ingredienti Spezie',
        'aroma mandorla'        => 'Ingredienti Spezie',
        'aroma limone'          => 'Ingredienti Spezie',
        'aroma rum'             => 'Ingredienti Spezie',
        'aroma arancia'         => 'Ingredienti Spezie',
        // Prepared salads (not fresh greens)
        'insalata di riso'      => 'Insalata di riso',
        'insalata di pasta'     => 'Insalata di pasta',
        'insalata di farro'     => 'Insalata di farro',
        'insalata di orzo'      => 'Insalata di orzo',
        'insalata di couscous'  => 'Insalata di couscous',
        'insalata di quinoa'    => 'Insalata di quinoa',
    ];
}

/**
 * Curated keyword -> canonical group name. Covers the most common Italian pantry
 * items so the (cached, daily-capped) AI call is rarely needed.
 */
function evershelfShoppingKeywordMap(): array {
    return [
        // Cold cuts / affettati
        'mortadella'    => 'Affettato',
        'nduja'         => 'Affettato',
        'salame'        => 'Affettato',
        'salami'        => 'Affettato',
        'coppa'         => 'Affettato',
        'capicola'      => 'Affettato',
        'speck'         => 'Affettato',
        'schinkenspeck' => 'Affettato',
        'schinken'      => 'Affettato',
        'prosciutto'    => 'Affettato',
        // Items with their own Bring! entry
        'bresaola'      => 'Bresaola',
        'pancetta'      => 'Pancetta',
        'salsiccia'     => 'Salsiccia',
        'wurstel'       => 'Wurstel',
        // Bread & bakery
        'pane'          => 'Pane',
        'bauletto'      => 'Pane',
        'pancarrè'      => 'Pane',
        'pancare'       => 'Pane',
        'toast'         => 'Pane',
        'focaccia'      => 'Pane',
        'ciabatta'      => 'Pane',
        'baguette'      => 'Pane',
        'grissini'      => 'Grissini',
        'crackers'      => 'Cracker',
        'cracker'       => 'Cracker',
        'taralli'       => 'Taralli',
        'tarallini'     => 'Taralli',
        'piadina'       => 'Piadina',
        'piadelle'      => 'Piadina',
        'biscotto'      => 'Biscotti',
        'biscotti'      => 'Biscotti',
        // Breadcrumbs single-token safety net (phrase map has priority, but just in case)
        'grattugiato'   => 'Pangrattato',
        'grattato'      => 'Pangrattato',
        'pangrattato'   => 'Pangrattato',
        'biscottate'    => 'Fette biscottate',
        // Leavening agents
        'lievito'       => 'Lievito',
        // Flavourings / aromas (single-token fallback; phrases handled above)
        'aroma'         => 'Ingredienti Spezie',
        // Dairy
        'latte'         => 'Latte',
        'yogurt'        => 'Yogurt',
        'yaourt'        => 'Yogurt',
        'yougurt'       => 'Yogurt',
        'burro'         => 'Burro',
        'butter'        => 'Burro',
        'butterschmalz' => 'Burro',
        'panna'         => 'Panna',
        'mozzarella'    => 'Mozzarella',
        'formaggio'     => 'Formaggio',
        'ricotta'       => 'Ricotta',
        'ricottina'     => 'Ricotta',
        'casatella'     => 'Formaggio',
        'philadelphia'  => 'Formaggio cremoso',
        // "Bel Paese" — known Italian cheese brand
        'bel'           => 'Formaggio',
        // Pasta
        'pasta'         => 'Pasta',
        'spaghetti'     => 'Pasta',
        'penne'         => 'Pasta',
        'rigatoni'      => 'Pasta',
        'fusilli'       => 'Pasta',
        'orecchiette'   => 'Pasta',
        'tortiglioni'   => 'Pasta',
        'linguine'      => 'Pasta',
        'sedani'        => 'Pasta',
        'lasagne'       => 'Pasta',
        'tortellini'    => 'Pasta',
        'gnocchi'       => 'Gnocchi',
        // Rice
        'riso'          => 'Riso',
        // Eggs
        'uova'          => 'Uova',
        'uovo'          => 'Uova',
        // Fruit & veg
        'mela'          => 'Mele',
        'mele'          => 'Mele',
        'pera'          => 'Pere',
        'arancia'       => 'Arance',
        'arance'        => 'Arance',
        'limone'        => 'Limone',
        'banana'        => 'Banane',
        'banane'        => 'Banane',
        'kiwi'          => 'Kiwi',
        'avocado'       => 'Avocado',
        'pomodoro'      => 'Pomodori',
        'pomodori'      => 'Pomodori',
        'pomodorini'    => 'Pomodorini',
        'carota'        => 'Carote',
        'carote'        => 'Carote',
        'cipolla'       => 'Cipolla',
        'cipolle'       => 'Cipolla',
        'aglio'         => 'Aglio',
        'zucchina'      => 'Zucchine',
        'zucchine'      => 'Zucchine',
        'spinaci'       => 'Spinaci',
        'lattuga gentile'       => 'Insalata',
        'lattuga'               => 'Insalata',
        'melone'        => 'Melone',
        'finocchio'     => 'Finocchio',
        // Condiments & pantry
        'olio'          => 'Olio',
        'aceto'         => 'Aceto',
        'sale'          => 'Sale',
        'zucchero'      => 'Zucchero',
        'farina'        => 'Farina',
        'lievito'       => 'Lievito',
        'miele'         => 'Miele',
        'marmellata'    => 'Marmellata',
        'confettura'    => 'Marmellata',
        'maionese'      => 'Maionese',
        'senape'        => 'Senape',
        'ketchup'       => 'Ketchup',
        // Canned / preserved
        'passata'       => 'Passata',
        'polpa'         => 'Polpa di pomodoro',
        'pelati'        => 'Pelati',
        'tonno'         => 'Tonno',
        'sardine'       => 'Sardine',
        'ceci'          => 'Ceci',
        'lenticchie'    => 'Lenticchie',
        'fagioli'       => 'Fagioli',
        'piselli'       => 'Piselli',
        'mais'          => 'Mais',
        // Frozen
        'surgelato'     => 'Surgelati',
        'surgelati'     => 'Surgelati',
        // Drinks
        'vino'          => 'Vino',
        'birra'         => 'Birra',
        'succo'         => 'Succo',
        // Cereals & snacks
        'muesli'        => 'Muesli',
        'cereali'       => 'Cereali',
        // Frozen & desserts (before coffee/tea tokens to avoid "gelato caffè → Caffè")
        'gelato'        => 'Gelato',
        'semifreddo'    => 'Gelato',
        // Beverages (coffee, tea, herbal)
        'camomilla'     => 'Camomilla',
        'camomille'     => 'Camomilla',
        'tisana'        => 'Tè',
        // Cat food / pet
        'gatto'         => 'Cibo per gatti',
        'cane'          => 'Cibo per cani',
        // Known product/brand single tokens → category override
        'risofrolle'    => 'Cracker',
        'zuppalatte'    => 'Biscotti',
        'kaffee'        => 'Caffè',
        'ovomaltine'    => 'Bevande',
        'ciobar'        => 'Cioccolata calda',
        'apfelsaft'     => 'Succo',
        'kartoffelpüree'=> 'Purè',
        'purée'         => 'Purè',
        'pure'          => 'Purè',
        'inchusa'       => 'Birra',
        'ichnusa'       => 'Birra',
        'vesoletto'     => 'Vino',
        'trebbiano'     => 'Vino',
        'sangiovese'    => 'Vino',
        'barbera'       => 'Vino',
        'chianti'       => 'Vino',
        'soave'         => 'Vino',
        'prosecco'      => 'Vino',
        'frizzante'     => 'Acqua',
        'semolino'      => 'Semolino',
        'bicarbonato'   => 'Bicarbonato',
        'sambuca'       => 'Liquore',
        'limoncello'    => 'Liquore',
        'grappa'        => 'Liquore',
        'dado'          => 'Brodo',
        'zuccheri'      => 'Zucchero',
        'zucchero'      => 'Zucchero',
        // Foreign-language tokens
        'jus'           => 'Succo',
        'zumo'          => 'Succo',
        'arome'         => 'Aroma',
        'caffe'         => 'Caffè',
        'caffè'         => 'Caffè',
    ];
}

/**
 * Significant tokens of a product name: lowercase, punctuation/numbers dropped,
 * length > 2, stop words and (optionally) the brand words removed.
 *
 * Same semantics as the inline tokenizer computeShoppingName() used before the
 * move, so shopping_name values do not change.
 *
 * @return list<string>
 */
function evershelfSignificantTokens(string $name, string $brand = ''): array {
    $lower = mb_strtolower(trim($name));
    $stop  = evershelfShoppingStopWords();

    if ($brand !== '') {
        foreach ((preg_split('/[^\p{L}]+/u', mb_strtolower(trim($brand))) ?: []) as $brandWord) {
            $brandWord = trim((string)$brandWord);
            if (mb_strlen($brandWord) > 2) {
                $stop[] = $brandWord;
            }
        }
    }

    $parts = preg_split('/\s+/', (string)preg_replace('/[^\p{L}\s]/u', ' ', $lower)) ?: [];

    return array_values(array_filter(
        $parts,
        static fn ($w) => mb_strlen((string)$w) > 2 && !in_array($w, $stop, true)
    ));
}

/**
 * Every genre noun the curated dictionaries can produce, lowercase and unique.
 * Used to detect a title that already starts with a genre ("Yogurt Fiori di latte").
 *
 * @return list<string>
 */
function evershelfProductKindVocabulary(): array {
    static $vocab = null;
    if ($vocab !== null) {
        return $vocab;
    }
    $seen = [];
    foreach ([evershelfShoppingPhraseMap(), evershelfShoppingKeywordMap()] as $map) {
        foreach ($map as $kind) {
            $k = mb_strtolower(trim((string)$kind));
            if ($k !== '') {
                $seen[$k] = true;
            }
        }
    }
    return $vocab = array_keys($seen);
}

/**
 * Genre straight from the curated dictionary — no AI, no cache.
 *
 * THE single dictionary lookup of the project: computeShoppingName() returns this
 * result verbatim for the shopping list, so the buyable generic and the genre embedded
 * in the title are the same vocabulary and can never drift apart. `confident` is the
 * only thing the two consumers read differently:
 *
 *   * the shopping name accepts the match anywhere in the name (the list wants a
 *     buyable word either way),
 *   * the genre prefix is authoritative only when the matched word LEADS the name:
 *     "Fiori di latte" matches "latte", but the dictionary cannot know whether the
 *     product is a yoghurt or a cheese, so the caller must ask the AI (cached).
 *
 * @return array{kind:string,confident:bool}
 */
function productKindFromDictionary(string $name, string $brand = ''): array {
    $lower = mb_strtolower(trim($name));

    foreach (evershelfShoppingPhraseMap() as $phrase => $canonical) {
        if ($phrase !== '' && mb_strpos($lower, (string)$phrase) !== false) {
            return ['kind' => (string)$canonical, 'confident' => true];
        }
    }

    $keywordMap = evershelfShoppingKeywordMap();
    foreach (evershelfSignificantTokens($name, $brand) as $i => $token) {
        if (isset($keywordMap[$token])) {
            return ['kind' => (string)$keywordMap[$token], 'confident' => $i === 0];
        }
    }

    return ['kind' => '', 'confident' => false];
}

/**
 * Token signature used to share one resolved genre with similar products
 * ("Fiori di latte" ↔ "Fiori di latte Santa Lucia 200 g").
 */
function productKindSignature(string $name, string $brand = ''): string {
    $tokens = array_values(array_unique(evershelfSignificantTokens($name, $brand)));
    sort($tokens);
    return implode(' ', $tokens);
}

/** Read the signature cache (a flat JSON map, same style as shopping_name_cache.json). */
function productKindCacheLoad(): array {
    if (!defined('PRODUCT_KIND_CACHE_PATH') || !file_exists(PRODUCT_KIND_CACHE_PATH)) {
        return [];
    }
    $raw = @file_get_contents(PRODUCT_KIND_CACHE_PATH);
    if ($raw === false || $raw === '') {
        return [];
    }
    $cache = json_decode($raw, true);
    return is_array($cache) ? $cache : [];
}

function productKindCacheSave(array $cache): void {
    if (!defined('PRODUCT_KIND_CACHE_PATH')) {
        return;
    }
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }
    $tmp = PRODUCT_KIND_CACHE_PATH . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return;
    }
    @rename($tmp, PRODUCT_KIND_CACHE_PATH);
}

/** Normalize one cache entry: ['v' => kind, 'src' => source, 'ts' => int]; legacy strings accepted. */
function productKindCacheEntry($entry): ?array {
    if (is_string($entry)) {
        $v = trim($entry);
        return $v === '' ? null : ['v' => $v, 'src' => 'cache', 'ts' => 0];
    }
    if (!is_array($entry)) {
        return null;
    }
    $v = trim((string)($entry['v'] ?? ''));
    if ($v === '') {
        return null;
    }
    return ['v' => $v, 'src' => (string)($entry['src'] ?? 'cache'), 'ts' => (int)($entry['ts'] ?? 0)];
}

/**
 * Look the signature up: exact first, then the most specific cached signature whose
 * tokens are a subset of the requested one ("Fiori di latte" serves
 * "Fiori di latte Santa Lucia").
 *
 * @return array{v:string,src:string,ts:int}|null
 */
function productKindCacheLookup(array $cache, string $signature): ?array {
    if ($signature === '') {
        return null;
    }
    if (isset($cache[$signature])) {
        $hit = productKindCacheEntry($cache[$signature]);
        if ($hit !== null) {
            return $hit;
        }
    }

    $sigTokens = explode(' ', $signature);
    $best = null;
    $bestCount = 0;
    foreach ($cache as $key => $val) {
        $keyTokens = explode(' ', trim((string)$key));
        if ($keyTokens === [] || $keyTokens === [''] || count($keyTokens) >= count($sigTokens)) {
            continue;
        }
        if (count($keyTokens) <= $bestCount) {
            continue;
        }
        if (array_diff($keyTokens, $sigTokens) !== []) {
            continue;
        }
        $hit = productKindCacheEntry($val);
        if ($hit === null) {
            continue;
        }
        $best = $hit;
        $bestCount = count($keyTokens);
    }

    return $best;
}

/** Store one resolved genre under its signature (keeps the file bounded). */
function productKindCacheStore(array $cache, string $signature, string $kind, string $source): array {
    $kind = trim($kind);
    if ($signature === '' || $kind === '') {
        return $cache;
    }
    $cache[$signature] = ['v' => $kind, 'src' => $source, 'ts' => time()];
    if (count($cache) > 3000) {
        uasort($cache, static fn ($a, $b) => (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0));
        $cache = array_slice($cache, 0, 2500, true);
    }
    return $cache;
}

/**
 * One-word AI classification (the only paid step). Delegates to
 * _geminiClassifyProduct() which already caches per name+brand in
 * data/shopping_name_cache.json and honours GEMINI_CLASSIFY_DAILY_MAX, so the whole
 * label family costs at most one request.
 */
function productKindFromAi(string $name, string $brand, string $category): string {
    if (!function_exists('_geminiClassifyProduct') || !function_exists('aiIsEnabled') || !aiIsEnabled()) {
        return '';
    }
    $kind = _geminiClassifyProduct($name, $brand, $category);
    return is_string($kind) ? trim($kind) : '';
}

/** Localized app category as a last resort ("latticini" → "Latticini"/"Milchprodukte"). */
function productKindFromCategory(string $category, string $lang): string {
    $category = strtolower(trim($category));
    if ($category === '' || $category === 'altro' || !function_exists('evershelfTr')) {
        return '';
    }
    $label = trim(evershelfTr('categories.' . $category, $lang));
    return ($label === '' || $label === 'categories.' . $category) ? '' : $label;
}

/**
 * Resolve the genre to prefix inside the title: curated dictionary → signature
 * cache → one-word AI → localized category. Returns an empty kind when every step
 * misses (the title is then left untouched).
 *
 * @return array{kind:string,source:string}
 */
function resolveProductKind(string $name, string $brand = '', string $category = '', string $lang = 'en', bool $allowAi = true): array {
    $name = trim($name);
    if ($name === '') {
        return ['kind' => '', 'source' => ''];
    }

    // 1. Curated dictionary — only authoritative when the genre word leads the name.
    $dict = productKindFromDictionary($name, $brand);
    if ($dict['kind'] !== '' && $dict['confident']) {
        return ['kind' => $dict['kind'], 'source' => 'dictionary'];
    }

    // 2. Cache first: a similar product already paid for the AI call.
    $signature = productKindSignature($name, $brand);
    $cache     = productKindCacheLoad();
    $cached    = productKindCacheLookup($cache, $signature);
    if ($cached !== null) {
        return ['kind' => $cached['v'], 'source' => 'cache:' . $cached['src']];
    }

    // 3. One-word AI classification (cached per name+brand, daily capped).
    if ($allowAi) {
        $ai = productKindFromAi($name, $brand, $category);
        if ($ai !== '') {
            productKindCacheSave(productKindCacheStore($cache, $signature, $ai, 'ai'));
            return ['kind' => $ai, 'source' => 'ai'];
        }
    }

    // 4. Weak dictionary hit ("Fiori di latte" → Latte) beats a coarse category.
    if ($dict['kind'] !== '') {
        return ['kind' => $dict['kind'], 'source' => 'dictionary-weak'];
    }

    // 5. Localized app category, excluding the 'altro' catch-all.
    $fromCategory = productKindFromCategory($category, $lang);
    if ($fromCategory !== '') {
        return ['kind' => $fromCategory, 'source' => 'category'];
    }

    return ['kind' => '', 'source' => ''];
}

/** True when $name already contains $kind as a standalone word ("Yogurt Fiori di latte"). */
function productNameHasKind(string $name, string $kind): bool {
    $needle = trim((string)preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($kind)));
    if ($needle === '') {
        return false;
    }
    $haystack = ' ' . trim((string)preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($name))) . ' ';
    return mb_strpos($haystack, ' ' . $needle . ' ') !== false;
}

/** True when the title already starts with ANY known genre — never prefix twice. */
function productNameStartsWithKnownKind(string $name): bool {
    $norm = trim((string)preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($name)));
    if ($norm === '') {
        return false;
    }
    foreach (evershelfProductKindVocabulary() as $kind) {
        $k = trim((string)preg_replace('/[^\p{L}]+/u', ' ', $kind));
        if ($k === '') {
            continue;
        }
        if ($norm === $k || mb_strpos($norm, $k . ' ') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * True when the title opens with exactly that word ("Toast …" for the genre "Toast",
 * "Yogurt …" for "Yogurt"): whole word, punctuation and case ignored.
 */
function productKindStartsWithWord(string $name, string $word): bool {
    $word = trim((string)preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($word)));
    $name = trim((string)preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($name)));
    if ($word === '' || $name === '') {
        return false;
    }
    return $name === $word || mb_strpos($name, $word . ' ') === 0;
}

/** Comparison form of a word: lowercase, accents folded, letters only. */
function productKindFoldWord(string $word): string {
    $word = mb_strtolower(trim($word), 'UTF-8');
    if ($word === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $word);
        if (is_string($folded) && $folded !== '') {
            $word = $folded;
        }
    }
    return (string)preg_replace('/[^a-z]+/', '', $word);
}

/**
 * Crude singular/plural stem: "taralli"/"tarallini" → "tarall", "pera"/"pere" → "per",
 * "biscotti"/"biscotto" → "biscott".
 */
function productKindStem(string $word): string {
    $w = productKindFoldWord($word);
    $w = (string)preg_replace('/(etti|ette|otti|otte|ini|ine|oni|one)$/', '', $w);
    return (string)preg_replace('/(ie|[aeio])$/', '', $w);
}

/** True when one word is the genre and the other the same word written slightly differently. */
function productKindWordsSimilar(string $a, string $b, bool $strict = false): bool {
    $a = productKindFoldWord($a);
    $b = productKindFoldWord($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }

    $stemA = productKindStem($a);
    $stemB = productKindStem($b);
    if ($strict) {
        // Strict mode (a word *anywhere* in the title): only the same root clearly
        // written in the singular/plural, or one character apart. "Zuccheri" counts as
        // the genre "Zucchero"; unrelated look-alikes ("sale"/"salumi") do not.
        if ($stemA !== '' && $stemA === $stemB && mb_strlen($stemA) >= 4) {
            return true;
        }
        return max(mb_strlen($a), mb_strlen($b)) >= 5 && levenshtein($a, $b) <= 1;
    }

    if ($stemA !== '' && $stemA === $stemB && mb_strlen($stemA) >= 3) {
        return true; // pera/pere, taralli/tarallini, biscotti/biscotto
    }

    $longest = max(mb_strlen($a), mb_strlen($b));
    $shortest = min(mb_strlen($a), mb_strlen($b));
    $distance = levenshtein($a, $b);
    if ($longest <= 6 && $distance <= 1) {
        return true; // one typo on a short word
    }
    if ($longest >= 5 && $distance <= 2) {
        return true; // kaffee/caffe, yogurt/yogurth
    }

    // Shared opening ("piadelle"/"piadina", "riso"/"risotto") — only when it covers
    // most of the shorter word, so unrelated words that merely start alike stay untouched.
    $shared = 0;
    while ($shared < $shortest && $a[$shared] === $b[$shared]) {
        $shared++;
    }
    $gap = abs(mb_strlen($a) - mb_strlen($b));
    if ($shared >= 4 && $gap <= 4) {
        return true; // same root, different suffix (piadelle/piadina)
    }
    return $shared >= 3 && $shared / $shortest >= 0.6 && $gap <= 3;
}

/**
 * True when the title already carries the genre or a close variant of it — either as
 * the opening word ("Tarallini" vs "Taralli", "Pera Italiana …" vs "Pere", "Kaffee"
 * vs "Caffè") or somewhere further along ("Italia Zuccheri …" vs "Zucchero").
 *
 * The rule exists because the prefix must be added only "where it — or something
 * similar — is not already there": prefixing those titles would produce
 * "Taralli Tarallini" and "Pere Pera Italiana Succo e polpa frutta".
 *
 * The opening word is compared loosely (plural, one letter, same root); any other
 * word strictly, so a mere look-alike further along the title never blocks a prefix.
 */
function productKindNameAlreadyHasKind(string $name, string $kind): bool {
    $nameWords = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [] as $w) {
        $folded = productKindFoldWord($w);
        if ($folded !== '') {
            $nameWords[] = $folded;
        }
    }
    if ($nameWords === []) {
        return false;
    }

    $stop    = array_map('productKindFoldWord', evershelfShoppingStopWords());
    $kindWords = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', $kind) ?: [] as $w) {
        $folded = productKindFoldWord($w);
        if ($folded !== '' && !in_array($folded, $stop, true)) {
            $kindWords[] = $folded;
        }
    }
    if ($kindWords === []) {
        return false;
    }

    // "Latte di soia" is already there in "Soia drink", "Panna da cucina" in "Panna Chef".
    foreach ($nameWords as $i => $nameWord) {
        foreach ($kindWords as $kindWord) {
            if (productKindWordsSimilar($nameWord, $kindWord, $i > 0)) {
                return true;
            }
        }
    }
    // …and the opening words of the title taken together ("latte di soia").
    $joined = implode(' ', $nameWords);
    $phrase = implode(' ', $kindWords);
    return $phrase !== '' && (mb_strpos($joined . ' ', $phrase . ' ') === 0);
}

/**
 * Make the genre an integral part of the article title
 * ("Fiori di latte" → "Yogurt Fiori di latte"). Idempotent: a title that already
 * carries a genre (any genre) is returned untouched.
 */
function applyProductKindPrefix(string $name, string $kind): string {
    $name = trim($name);
    $kind = trim($kind);
    if ($name === '' || $kind === '') {
        return $name;
    }
    if (productNameHasKind($name, $kind) || productNameStartsWithKnownKind($name) || productKindNameAlreadyHasKind($name, $kind)) {
        return $name;
    }
    return $kind . ' ' . $name;
}

/** PRODUCT_KIND_PREFIX=false switches the genre prefix off (default: on). */
function productKindPrefixEnabled(): bool {
    return strtolower(trim((string)env('PRODUCT_KIND_PREFIX', 'true'))) !== 'false';
}

/** Normalize a UI language to one of the six shipped locales (fallback: English). */
function productKindNormalizeLang($lang): string {
    $lang = strtolower(substr(trim((string)$lang), 0, 2));
    return in_array($lang, ['it', 'en', 'de', 'fr', 'es', 'zh'], true) ? $lang : 'en';
}

/**
 * Full pipeline used on save: resolve the genre and return the prefixed title.
 *
 * A title that already starts with a genre short-circuits: re-saving a product must
 * never cost another AI call (and the genre we already stored is carried over).
 *
 * @return array{name:string,kind:string,source:string}
 */
function productKindApply(string $name, string $brand = '', string $category = '', string $lang = 'en', bool $allowAi = true, string $knownKind = ''): array {
    $name = trim($name);
    if ($name === '' || !productKindPrefixEnabled()) {
        return ['name' => $name, 'kind' => '', 'source' => ''];
    }
    // The title already opens with the genre stored on the product ("Toast Sandwich
    // American Style" + kind "Toast"): whichever pass wrote it, this one has no business
    // rewriting it — the dictionary may well suggest a broader genre for the same word
    // (its own "toast" → "Pane"), which would drift the title at every run.
    if ($knownKind !== '' && productKindStartsWithWord($name, $knownKind)) {
        return ['name' => $name, 'kind' => $knownKind, 'source' => 'existing'];
    }
    if (productNameStartsWithKnownKind($name)) {
        return ['name' => $name, 'kind' => $knownKind, 'source' => 'existing'];
    }
    $resolved = resolveProductKind($name, $brand, $category, productKindNormalizeLang($lang), $allowAi);
    if ($resolved['kind'] === '') {
        return ['name' => $name, 'kind' => '', 'source' => ''];
    }
    return [
        'name'   => applyProductKindPrefix($name, $resolved['kind']),
        'kind'   => $resolved['kind'],
        'source' => $resolved['source'],
    ];
}
