<?php
/**
 * EverShelf — product "kind" (genere) resolution.
 *
 * The user wants every article title to start with what the product actually is
 * ("Yogurt Fiori di latte", "Formaggio Fiori di latte") because the generic type is
 * often missing from the name printed on the label.
 *
 * The same title also always opens with a capital letter (productTitleCapitalize()):
 * "latte fresco" and "Latte fresco" are one article and the pantry must spell it one
 * way, whichever pass wrote the name.
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
        'piadina romagnola'     => 'Piadina',
        'piadine romagnole'     => 'Piadina',
        'sfogliata tradizionale'=> 'Piadina',
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
        'piadine'       => 'Piadina',
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
        'yogurth'       => 'Yogurt',
        'jogurt'        => 'Yogurt',
        'joghurt'       => 'Yogurt',
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
        'avocados'      => 'Avocado',
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
        // Cereals & snacks — one buyable family so muesli/granola cover "Cereali"
        'muesli'        => 'Cereali',
        'granola'       => 'Cereali',
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
 * Number forms of the ONE vocabulary: the genres are fixed strings, but an Italian
 * title must read at the SINGULAR ("Mela", "Uovo medio") and the pantry list at the
 * plural of what is really there ("3 Mele", "12 Uova medie"). The dictionary already
 * holds the genre; here it is given its inflection, keyed by the SAME canonical, so no
 * second vocabulary is born:
 *
 *   canonical => [singular, plural, gender of the plural]
 *
 * Only countable piece genres are listed — fruit, veg, eggs, packs you buy as
 * "one mozzarella / three mozzarelle". Everything else is invariant by construction
 * and comes back unchanged:
 *
 *   * mass / foreign nouns ("Latte", "Riso", "Sale", "Yogurt", "Kiwi", "Caffè"),
 *   * package collectives whose singular is wrong on a shelf ("Ceci" must never
 *     become "Cece", nor "Fagioli"→"Fagiolo", "Lenticchie", "Piselli", "Cereali",
 *     "Spinaci", "Gnocchi", "Grissini", "Taralli", "Sardine", "Pelati") — one tin
 *     still holds many beans, so the title stays plural even for a single pack.
 *
 * A missing entry can therefore never invent a plural (or a bogus singular).
 *
 * @return array<string,array{0:string,1:string,2:string}>
 */
function evershelfProductKindNumberForms(): array {
    return [
        'Affettato'        => ['Affettato', 'Affettati', 'm'],
        'Aglio'            => ['Aglio', 'Agli', 'm'],
        'Arance'           => ['Arancia', 'Arance', 'f'],
        'Banane'           => ['Banana', 'Banane', 'f'],
        'Bevande'          => ['Bevanda', 'Bevande', 'f'],
        'Birra'            => ['Birra', 'Birre', 'f'],
        'Biscotti'         => ['Biscotto', 'Biscotti', 'm'],
        'Carote'           => ['Carota', 'Carote', 'f'],
        'Cipolla'          => ['Cipolla', 'Cipolle', 'f'],
        'Fette biscottate' => ['Fetta biscottata', 'Fette biscottate', 'f'],
        'Finocchio'        => ['Finocchio', 'Finocchi', 'm'],
        'Formaggio'        => ['Formaggio', 'Formaggi', 'm'],
        'Gelato'           => ['Gelato', 'Gelati', 'm'],
        'Insalata'         => ['Insalata', 'Insalate', 'f'],
        'Limone'           => ['Limone', 'Limoni', 'm'],
        'Liquore'          => ['Liquore', 'Liquori', 'm'],
        'Marmellata'       => ['Marmellata', 'Marmellate', 'f'],
        'Mele'             => ['Mela', 'Mele', 'f'],
        'Melone'           => ['Melone', 'Meloni', 'm'],
        'Mozzarella'       => ['Mozzarella', 'Mozzarelle', 'f'],
        'Passata'          => ['Passata', 'Passate', 'f'],
        'Pere'             => ['Pera', 'Pere', 'f'],
        'Piadina'          => ['Piadina', 'Piadine', 'f'],
        'Pomodori'         => ['Pomodoro', 'Pomodori', 'm'],
        'Pomodorini'       => ['Pomodorino', 'Pomodorini', 'm'],
        'Ricotta'          => ['Ricotta', 'Ricotte', 'f'],
        'Salsiccia'        => ['Salsiccia', 'Salsicce', 'f'],
        'Succo'            => ['Succo', 'Succhi', 'm'],
        'Sugo'             => ['Sugo', 'Sughi', 'm'],
        'Surgelati'        => ['Surgelato', 'Surgelati', 'm'],
        'Uova'             => ['Uovo', 'Uova', 'f'],
        'Verdure'          => ['Verdura', 'Verdure', 'f'],
        'Zucchine'         => ['Zucchina', 'Zucchine', 'f'],
    ];
}


/**
 * The adjectives that follow a genre and must agree with it, in their four forms
 * [masculine-singular, feminine-singular, masculine-plural, feminine-plural].
 *
 * Only these are ever touched. A word nobody put here — a variety, a place, a brand
 * ("Gala", "Basmati", "Siracusa", "Zuppalatte") — keeps the spelling the user wrote,
 * so the rule can never invent an agreement for a word it does not understand.
 *
 * @return list<array{0:string,1:string,2:string,3:string}>
 */
function evershelfProductKindAgreementForms(): array {
    return [
        ['bio', 'bio', 'bio', 'bio'],
        ['biologico', 'biologica', 'biologici', 'biologiche'],
        ['cotto', 'cotta', 'cotti', 'cotte'],
        ['crudo', 'cruda', 'crudi', 'crude'],
        ['dolce', 'dolce', 'dolci', 'dolci'],
        ['dorato', 'dorata', 'dorati', 'dorate'],
        ['fino', 'fina', 'fini', 'fine'],
        ['fresco', 'fresca', 'freschi', 'fresche'],
        ['giallo', 'gialla', 'gialli', 'gialle'],
        ['grande', 'grande', 'grandi', 'grandi'],
        ['grattugiato', 'grattugiata', 'grattugiati', 'grattugiate'],
        ['grosso', 'grossa', 'grossi', 'grosse'],
        ['integrale', 'integrale', 'integrali', 'integrali'],
        ['intero', 'intera', 'interi', 'intere'],
        ['lungo', 'lunga', 'lunghi', 'lunghe'],
        ['macinato', 'macinata', 'macinati', 'macinate'],
        ['maturo', 'matura', 'maturi', 'mature'],
        ['medio', 'media', 'medi', 'medie'],
        ['naturale', 'naturale', 'naturali', 'naturali'],
        ['nero', 'nera', 'neri', 'nere'],
        ['pelato', 'pelata', 'pelati', 'pelate'],
        ['piccante', 'piccante', 'piccanti', 'piccanti'],
        ['piccolo', 'piccola', 'piccoli', 'piccole'],
        ['raffinato', 'raffinata', 'raffinati', 'raffinate'],
        ['rosso', 'rossa', 'rossi', 'rosse'],
        ['scremato', 'scremata', 'scremati', 'scremate'],
        ['secco', 'secca', 'secchi', 'secche'],
        ['sodo', 'soda', 'sodi', 'sode'],
        ['sottile', 'sottile', 'sottili', 'sottili'],
        ['surgelato', 'surgelata', 'surgelati', 'surgelate'],
        ['tondo', 'tonda', 'tondi', 'tonde'],
        ['verde', 'verde', 'verdi', 'verdi'],
    ];
}

/** Index of evershelfProductKindAgreementForms() by folded word (both numbers, both genders). */
function productKindAgreementIndex(): array {
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (evershelfProductKindAgreementForms() as $i => $forms) {
            foreach ($forms as $form) {
                $fold = productKindFoldWord($form);
                if ($fold !== '') {
                    $index[$fold] = $i;
                }
            }
        }
    }
    return $index;
}

/** True when the word is one of the curated adjectives — the only words that inflect. */
function productKindIsAgreementWord(string $word): bool {
    $index = productKindAgreementIndex();
    return isset($index[productKindFoldWord($word)]);
}
/**
 * Number forms of a genre, answerable from ANY of its forms ("Mele", "Mela" and the
 * stored "Mela" all give the same pair). An unknown genre — a mass noun, or a
 * shopping generic that came from the AI such as "Patate" — is invariant by
 * construction: singular and plural are the same string, so nothing downstream can
 * derive a form the dictionary never had.
 *
 * @return array{singular:string,plural:string,gender:string}
 */
function productKindNumberFormsFor(string $kind): array {
    $kind = trim($kind);
    if ($kind === '') {
        return ['singular' => '', 'plural' => '', 'gender' => ''];
    }
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (evershelfProductKindNumberForms() as $canonical => $forms) {
            foreach ([$canonical, $forms[0], $forms[1]] as $key) {
                $fold = productKindFoldWord((string)$key);
                if ($fold !== '' && !isset($index[$fold])) {
                    $index[$fold] = $forms;
                }
            }
        }
    }
    $fold = productKindFoldWord($kind);
    if (isset($index[$fold])) {
        return ['singular' => $index[$fold][0], 'plural' => $index[$fold][1], 'gender' => $index[$fold][2]];
    }
    return ['singular' => $kind, 'plural' => $kind, 'gender' => ''];
}

/** Singular form of a genre ("Mele" → "Mela"); an invariant genre comes back unchanged. */
function productKindSingularForm(string $kind): string {
    return productKindNumberFormsFor($kind)['singular'];
}

/** Plural form of a genre ("Mela" → "Mele"); '' when the genre has no distinct plural. */
function productKindPluralForm(string $kind): string {
    $forms = productKindNumberFormsFor($kind);
    return $forms['plural'] === $forms['singular'] ? '' : $forms['plural'];
}

/** Gender of a genre word from its ending ('m'/'f'); '' when the ending does not tell. */
function productKindGenderOfWord(string $word): string {
    $parts = preg_split('/\s+/u', trim($word)) ?: [];
    $first = (string)($parts[0] ?? '');
    if (preg_match('/a$/u', $first)) {
        return 'f';
    }
    if (preg_match('/o$/u', $first)) {
        return 'm';
    }
    return '';
}

/**
 * Put one word in the number/gender the head of the title asks for: "uova medie" →
 * "uovo medio" asks for 'medio', "mela rossa" → "mele rosse" for 'rosse'. A word that
 * is not one of the curated adjectives keeps its own spelling — the rule never guesses
 * an ending for a word it does not know.
 */
function productKindAgreeWord(string $word, string $number, string $gender): string {
    if ($gender === '' || !preg_match('/^([^\p{L}]*)(\p{L}+)([^\p{L}]*)$/u', $word, $m)) {
        return $word;
    }
    $index = productKindAgreementIndex();
    $fold  = productKindFoldWord($m[2]);
    if (!isset($index[$fold])) {
        return $word;
    }
    $forms    = evershelfProductKindAgreementForms()[$index[$fold]];
    $position = ($number === 'pl' ? 2 : 0) + ($gender === 'f' ? 1 : 0);
    $agreed   = $forms[$position];
    if (preg_match('/^\p{Lu}/u', $m[2])) {
        $agreed = mb_strtoupper(mb_substr($agreed, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($agreed, 1, null, 'UTF-8');
    }
    return $m[1] . $agreed . $m[3];
}

/**
 * Rewrite the head of a title into another number, carrying the words that follow with
 * it: "Uova medie" → "Uovo medio", "Mela rossa" → "Mele rosse".
 *
 * The head is matched with productKindStartsWithWord() (whole words, punctuation and
 * case ignored), so "Tarallini" is not "Taralli" and the pass leaves it alone; the
 * words after it are agreed only through productKindAgreeWord(), so a variety or a
 * brand stays exactly as the user wrote it.
 */
function productKindRewriteNameNumber(string $name, string $from, string $to, string $number, string $gender): string {
    $name = trim($name);
    $from = trim($from);
    $to   = trim($to);
    if ($name === '' || $from === '' || $to === '' || $from === $to || !productKindStartsWithWord($name, $from)) {
        return $name;
    }
    // A title whose FIRST word carries no letter at all (a scanned quantity, an emoji) is
    // not a genre-led title: the matcher forgives the "6" in front, but rebuilding the
    // title would push it behind the genre ("Uovo6 uova pasta giallo"). A quote in front
    // of the genre is fine — see the lead tokens below —, a number is not.
    $tokens     = (array)preg_split('/\s+/u', $name);
    $firstToken = (string)($tokens[0] ?? '');
    if (!preg_match('/\p{L}/u', $firstToken)) {
        return $name;
    }
    $headWords = count((array)preg_split('/\s+/u', $from));
    $lead      = []; // tokens carried in front of the head (a quote, a symbol)
    $head      = []; // the head words themselves
    $tail      = [];
    $pending   = $headWords;
    foreach ($tokens as $token) {
        if ($pending > 0 && productKindFoldWord($token) === '') {
            $lead[] = $token; // no letters: never the head, but still part of the title
            continue;
        }
        if ($pending > 0) {
            $head[] = $token;
            $pending--;
            continue;
        }
        $tail[] = $token;
    }
    if ($pending > 0) {
        return $name; // the matcher saw the genre, the tokens do not: never guess
    }
    // Whatever punctuation the user typed around the head travels with it ("«Uova»" → "«Uovo»").
    $leadPunct  = preg_match('/^([^\p{L}]+)/u', $head[0], $m) ? $m[1] : '';
    $trailPunct = preg_match('/([^\p{L}]+)$/u', (string)end($head), $m) ? $m[1] : '';
    // Only the run of adjectives that directly follows the head moves with it:
    // "Uova Fresche Grandi" → "Uovo fresco grande", while "Biscotti Macine con Panna
    // Fresca" → "Biscotto macine con panna fresca" — that "Fresca" belongs to the panna,
    // not to the biscotto, and a rule that guessed otherwise would write "panna fresco".
    $inRun = true;
    foreach ($tail as $i => $word) {
        if (!$inRun || !productKindIsAgreementWord($word)) {
            $inRun = false;
            continue;
        }
        $tail[$i] = productKindAgreeWord($word, $number, $gender);
    }
    return implode(' ', array_merge($lead, [$leadPunct . $to . $trailPunct], $tail));
}
/**
 * The stored article title, at the singular of the genre it carries:
 * "Uova medie" → "Uovo medio", "Zucchine bio Bimby" → "Zucchina bio Bimby".
 *
 * A title that does not OPEN with the genre's plural comes back untouched: the genre
 * may sit anywhere in the name ("Bucce cotte di pomodoro" matches "Pomodori" but
 * already says "pomodoro" in the singular), and an invariant genre ("Cracker
 * integrali", "Latte di Montagna") has no singular form to move to.
 */
function productKindSingularizeName(string $name, string $kind): string {
    $name = trim($name);
    $kind = trim($kind);
    if ($name === '' || $kind === '') {
        return $name;
    }
    $forms = productKindNumberFormsFor($kind);
    if ($forms['plural'] === $forms['singular']) {
        return $name;
    }
    return productKindRewriteNameNumber($name, $forms['plural'], $forms['singular'], 'sg', productKindGenderOfWord($forms['singular']));
}

/**
 * The name the pantry list must show for the pieces really on the shelf: the plural
 * when there is more than one ("3 Mele", "12 Uova medie"), the stored (singular) title
 * otherwise ("1 Mela", "500 g Pasta").
 *
 * Only piece units are pluralised: g/ml measure a mass and a mass has no plural. The
 * stored title is never touched by this rule — it stays the singular, which is what the
 * catalog, the search and the maintenance pass must always see.
 */
function productNameForPieces(string $name, float $quantity, string $unit = '', string $kind = ''): string {
    $name = trim($name);
    if ($name === '' || $quantity <= 1 || !productKindUnitCountsPieces($unit)) {
        return $name;
    }
    $kind = trim($kind);
    if ($kind === '') {
        $kind = (string)(productKindFromDictionary($name)['kind'] ?? '');
    }
    if ($kind === '') {
        return $name;
    }
    $forms = productKindNumberFormsFor($kind);
    if ($forms['plural'] === $forms['singular']) {
        return $name;
    }
    return productKindRewriteNameNumber($name, $forms['singular'], $forms['plural'], 'pl', $forms['gender']);
}

/**
 * True for the units that count pieces — the app's own "pz"/"conf" (products.unit
 * defaults to 'pz') and their full words. g/ml and anything unknown measure a mass:
 * "500 g Pasta" must never become "500 g Paste".
 */
function productKindUnitCountsPieces(string $unit): bool {
    $unit = mb_strtolower(trim($unit), 'UTF-8');
    if ($unit === '') {
        return true;
    }
    return in_array($unit, ['pz', 'pezzo', 'pezzi', 'nr', 'n', 'unità', 'unita', 'conf', 'confezione', 'confezioni', 'box', 'piece', 'pieces'], true);
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
        $phrase = (string)$phrase;
        if ($phrase === '' || mb_strpos($lower, $phrase) === false) {
            continue;
        }
        // Ingredient phrases ("farina di riso") only count when they lead the title —
        // otherwise "Campagnole con farina di riso" would shop as flour.
        $phraseHead = explode(' ', $phrase, 2)[0];
        static $ingredientPhraseHeads = [
            'farina' => true, 'olio' => true, 'sale' => true,
            'zucchero' => true, 'aceto' => true, 'lievito' => true,
        ];
        $atLead = mb_strpos($lower, $phrase) === 0
            || (bool)preg_match('/^(?:il|la|lo|i|gli|le|un|uno|una|the|a|an)\s+' . preg_quote($phrase, '/') . '/u', $lower);
        if (isset($ingredientPhraseHeads[$phraseHead]) && !$atLead) {
            continue;
        }
        return ['kind' => (string)$canonical, 'confident' => true];
    }

    $keywordMap = evershelfShoppingKeywordMap();
    // Trailing genres — packaging/brand openers ("La sfogliata… piadine") and
    // mid-title product signals ("Fiori di latte", "bucce… pomodoro") resolve;
    // bulk ingredient tokens stay lead-only via the phrase rule above.
    $trailingGenre = [
        'piadina' => true, 'piadine' => true, 'piadelle' => true,
        'avocado' => true, 'avocados' => true,
        'muesli' => true, 'granola' => true, 'cereali' => true,
        'yogurt' => true, 'yogurth' => true, 'jogurt' => true, 'joghurt' => true,
        'yaourt' => true, 'yougurt' => true,
        'gelato' => true, 'semifreddo' => true,
        'latte' => true,
        'pomodoro' => true, 'pomodori' => true, 'pomodorini' => true, 'pelati' => true,
    ];
    $trailing = null;
    $trailingIdx = PHP_INT_MAX;
    foreach (evershelfSignificantTokens($name, $brand) as $i => $token) {
        if (!isset($keywordMap[$token])) {
            continue;
        }
        $kind = (string)$keywordMap[$token];
        if ($i === 0) {
            return ['kind' => $kind, 'confident' => true];
        }
        if (!isset($trailingGenre[$token])) {
            continue;
        }
        if ($i < $trailingIdx) {
            $trailing = $kind;
            $trailingIdx = $i;
        }
    }
    if ($trailing !== null) {
        return ['kind' => $trailing, 'confident' => false];
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
 * True when a word already belongs to the vocabulary this file owns (the curated
 * genre/shopping dictionaries, the stop words, the number forms, the adjectives that
 * agree). Such a word is a word, never a sigla — so productTitleCapitalize()
 * lowercases it.
 */
function productKindIsKnownWord(string $word): bool {
    $fold = productKindFoldWord($word);
    if ($fold === '') {
        return false;
    }
    static $known = null;
    if ($known === null) {
        $known = [];
        foreach ([evershelfShoppingKeywordMap(), evershelfShoppingPhraseMap()] as $map) {
            foreach ($map as $key => $value) {
                foreach (array_merge((array)preg_split('/\s+/u', (string)$key), [$value]) as $w) {
                    $f = productKindFoldWord((string)$w);
                    if ($f !== '') {
                        $known[$f] = true;
                    }
                }
            }
        }
        foreach (array_merge(evershelfShoppingStopWords(), evershelfProductKindNumberForms()) as $entry) {
            foreach (is_array($entry) ? $entry : [$entry] as $w) {
                $f = productKindFoldWord((string)$w);
                if ($f !== '') {
                    $known[$f] = true;
                }
            }
        }
        foreach (evershelfProductKindAgreementForms() as $forms) {
            foreach ($forms as $w) {
                $known[productKindFoldWord($w)] = true;
            }
        }
    }
    return isset($known[$fold]);
}

/**
 * The sigle this app knows by name (Italian food certifications and the like). A token
 * in here is written back in its canonical capitals whatever case the user typed it in
 * ("Igp" → "IGP"), which the mechanical all-caps rule alone could not do.
 */
function evershelfProductKindSigle(): array {
    return ['IGP', 'IGT', 'DOP', 'DOC', 'DOCG', 'DOCA', 'STG', 'PGI', 'PDO', 'EVO', 'OGM', 'GMO', 'MSC', 'ASC'];
}

/**
 * True when a token is a sigla (IGP, DOP, IGT, EVO, XXL, I.G.P.): all capitals, no
 * digit, 2-5 letters, and not a word of the vocabulary. "BIO", "UOVA" and "PASTA" are
 * words, so they are not sigle and go back to their lowercase spelling.
 */
function productTitleIsAcronym(string $token): bool {
    return productTitleSiglaForm($token) !== '';
}

/**
 * The canonical capital spelling of a sigla, or '' when the token is an ordinary word
 * that must go back to lowercase. The curated sigle are recognised in any case the user
 * typed them; everything else has to be all caps to qualify.
 */
function productTitleSiglaForm(string $token): string {
    // HTML entities are stripped first: a title that carries them ("&quot;Limone … I.G.P.&quot;")
    // must still have its sigla recognised, and the entity itself is given back by
    // productTitleRestoreAcronyms().
    $letters = (string)preg_replace('/&\w+;|[^\p{L}]/u', '', $token);
    if ($letters === '' || preg_match('/\p{Nd}/u', $token)) {
        return '';
    }
    $upper = mb_strtoupper($letters, 'UTF-8');
    if (in_array($upper, evershelfProductKindSigle(), true)) {
        return $upper; // canonical: "Igp", "igp" and "I.G.P." all read back as "IGP"
    }
    if (mb_strtoupper($letters, 'UTF-8') !== $letters) {
        return ''; // mixed case is a word the user capitalised, not a sigla
    }
    $len = mb_strlen($letters, 'UTF-8');
    if ($len < 2 || $len > 5) {
        return '';
    }
    return productKindIsKnownWord($letters) ? '' : $upper;
}

/**
 * Give back the capitals the lowercasing took from the sigle of a title
 * ("aceto balsamico IGP" → "Aceto balsamico IGP"). Tokens are matched position by
 * position against the title as the user wrote it, so no other capital survives.
 */
function productTitleRestoreAcronyms(string $original, string $lowered): string {
    $originals = (array)preg_split('/\s+/u', trim($original));
    $i         = 0;
    return (string)preg_replace_callback('/\S+/u', static function (array $m) use ($originals, &$i): string {
        $token = (string)($originals[$i] ?? '');
        $i++;
        $sigla = productTitleSiglaForm($token);
        if ($sigla === '') {
            return $m[0];
        }
        // The user's own punctuation travels with the sigla ("(IGP)" stays "(IGP)", an
        // entity stays an entity); the dots are the sigla's own spelling and go
        // ("I.G.P." → "IGP", "I.G.P.," → "IGP,").
        $pre  = preg_match('/^(&\w+;|[^\p{L}]+)/u', $token, $p) ? $p[1] : '';
        $post = preg_match('/(&\w+;|[^\p{L}]+)$/u', $token, $p) ? $p[1] : '';
        $post = (string)preg_replace('/\.+/', '', $post);
        return $pre . $sigla . $post;
    }, $lowered);
}

/**
 * The stored title: first letter capital, everything else lowercase
 * ("LATTE Fresco" → "Latte fresco", "iPhone 15" → "Iphone 15"). The user asked for one
 * single spelling of an article, so "latte fresco" and "Latte Fresco" can never be two
 * products in the pantry. Whatever wrote the name — scan, import, catalog, AI,
 * hand-typed rename — the stored title goes through here (one exit, in productKindApply).
 *
 * Two exceptions, both mechanical, so the rule cannot disagree with itself:
 *   * a sigla keeps its capitals ("I.G.P.", "DOP", "IGT", "EVO"): an all-caps token of
 *     2-5 letters the vocabulary does not know as a word — "BIO" and "UOVA" are words
 *     and do go back to lowercase,
 *   * a title that does not open with a letter ("3 mele", "🍎 mela") is left alone:
 *     there is no first letter to raise, and lowercasing a quantity-led title is a
 *     guess nobody asked for.
 *
 * Idempotent: running it twice on the same title changes nothing, so the maintenance
 * pass can replay it without drifting a single title.
 *
 * NOT to be confused with normalizeProductName() (api/index.php), which lowercases a
 * *copy* of the name to compare two products: the stored title keeps the case this
 * function gives it.
 */
function productTitleCapitalize(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $first = mb_substr($name, 0, 1, 'UTF-8');
    if (!preg_match('/^\p{L}$/u', $first)) {
        return $name; // opens with a digit / symbol / emoji: there is no letter to raise
    }
    $lowered = mb_strtolower($name, 'UTF-8');
    $lowered = mb_strtoupper(mb_substr($lowered, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($lowered, 1, null, 'UTF-8');
    return productTitleRestoreAcronyms($name, $lowered);
}

/**
 * Make the genre an integral part of the article title
 * ("Fiori di latte" → "Yogurt Fiori di latte"). Idempotent: a title that already
 * carries a genre (any genre) is returned untouched.
 *
 * The prefix is the genre's SINGULAR form ("Fetta biscottata", not "Fette biscottate"):
 * the stored title is the singular of the article (productKindApply), so a genre the
 * dictionary only knows at the plural must not put that plural in front of the name.
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
    $singular = productKindSingularForm($kind);
    if (productNameHasKind($name, $singular) || productKindNameAlreadyHasKind($name, $singular)) {
        return $name;
    }
    return $singular . ' ' . $name;
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
 * The returned title always opens with a capital letter and carries the singular of
 * the article's genre: this is the ONE place the stored name is shaped, so the rule
 * holds for a save and for the maintenance pass (products_apply_auto_rules) alike —
 * even with PRODUCT_KIND_PREFIX=false (the capital letter survives, the genre is only
 * carried over when the title already has one).
 *
 * A title that already starts with a genre short-circuits: re-saving a product must
 * never cost another AI call (and the genre we already stored is carried over).
 *
 * @return array{name:string,kind:string,source:string}
 */
function productKindApply(string $name, string $brand = '', string $category = '', string $lang = 'en', bool $allowAi = true, string $knownKind = ''): array {
    $name = trim($name);
    // ONE exit for every branch: the title rules — the singular number and the capital
    // letter — must not depend on which branch happened to answer, so nobody can forget
    // them when adding a new one.
    $out = static function (string $title, string $kind, string $source): array {
        // The stored title is the SINGULAR of the article ("Uova medie" → "Uovo medio"):
        // the pantry list derives the plural of what is really there on the fly
        // (productNameForPieces).
        if ($kind !== '') {
            $title = productKindSingularizeName($title, $kind);
            // The genre is stored in its singular form only when it is the genre OF THIS
            // TITLE — the one the title leads with. A genre the dictionary merely suggested
            // for a title that words it differently ("Tarallini" and its weak suggestion
            // "Taralli", "Datterini pelati" and its "Pelati") is left as it was resolved:
            // narrowing it to "Tarallo"/"Pelato" would store a word the article never had,
            // and products.kind must stay a description of the title, not a rewrite of it.
            if (productKindStartsWithWord($title, $kind) || productKindStartsWithWord($title, productKindSingularForm($kind))) {
                $kind = productKindSingularForm($kind);
            }
        }
        return ['name' => productTitleCapitalize($title), 'kind' => $kind, 'source' => $source];
    };
    if ($name === '' || !productKindPrefixEnabled()) {
        return $out($name, '', '');
    }
    // The title already opens with the genre stored on the product ("Toast Sandwich
    // American Style" + kind "Toast"): whichever pass wrote it, this one has no business
    // rewriting it — the dictionary may well suggest a broader genre for the same word
    // (its own "toast" → "Pane"), which would drift the title at every run.
    if ($knownKind !== '' && productKindStartsWithWord($name, $knownKind)) {
        return $out($name, $knownKind, 'existing');
    }
    if (productNameStartsWithKnownKind($name)) {
        // The title already opens with a genre, but maybe nobody ever stored WHICH one
        // (a product created before the genre was kept, or a title the user typed
        // himself): the dictionary says it for free — the same lookup the shopping name
        // reads — so the article keeps its genre without paying a second AI word. Only a
        // confident answer (the one the dictionary gives for the leading word) is taken,
        // so a mere look-alike further along the title cannot invent a genre.
        $dict     = productKindFromDictionary($name);
        $dictKind = !empty($dict['confident']) ? (string)$dict['kind'] : '';
        return $out($name, $knownKind !== '' ? $knownKind : $dictKind, 'existing');
    }
    $resolved = resolveProductKind($name, $brand, $category, productKindNormalizeLang($lang), $allowAi);
    if ($resolved['kind'] === '') {
        return $out($name, '', '');
    }
    return $out(applyProductKindPrefix($name, $resolved['kind']), $resolved['kind'], $resolved['source']);
}
