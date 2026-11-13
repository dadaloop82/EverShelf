<?php
/**
 * EverShelf — AI prompt fragments that depend on the UI language.
 *
 * The photo-identification prompt (action `gemini_identify`) used to be a
 * hardcoded Italian string, so an English — or German, French, Spanish, Chinese —
 * user got Italian product names, descriptions and confidence labels back, even
 * though the client always knew which language the UI was in (issue #260).
 *
 * The fragments live here, outside the router, so every shipped locale carries the
 * same keys, Italian stays in the `it` block only, and the canonical category tokens
 * are never translated.
 */

/**
 * Prompt fragments for the photo-identification flow.
 *
 * The `category` fragment always lists the same canonical Italian tokens:
 * mapToLocalCategory() in assets/js/app.js matches them against CATEGORY_ICONS
 * keys, so they must never be translated — only the sentence around them is.
 *
 * @param string $lang One of it|en|de|fr|es|zh (see recipeNormalizeLang()).
 * @return array<string,string> intro, lang_rule, json_rule, name, brand,
 *                              category, search_terms, confidence, description.
 *                              Unknown locales fall back to the English set.
 */
/**
 * Meal / sub-type labels used INSIDE the recipe prompt.
 *
 * This is prose the model reads (examples included), not name matching — which is why it
 * lives with the other prompt copy instead of in the logic. The words themselves are
 * content, not a vocabulary rule: nothing here decides how a product is classified.
 */
function evershelfRecipePromptLabels(): array {
    return [
        'meal_labels' => [
            'colazione' => 'colazione (mattina)',
            'pranzo'    => 'pranzo (mezzogiorno)',
            'cena'      => 'cena (sera)',
            'dolce'     => 'dolce/dessert',
            'succo'     => 'succo di frutta/bevanda',
        ],
        'meal_labels_simple' => [
            'colazione' => 'colazione',
            'pranzo'    => 'pranzo',
            'cena'      => 'cena',
            'dolce'     => 'dolce',
            'succo'     => 'succo',
        ],
        'sub_types' => [
            'dolce' => [
                'torta'    => 'Torta (soffice, da forno: torta di mele, ciambellone, plumcake, angel cake, ecc.)',
                'crema'    => 'Crema o Budino (crema pasticcera, panna cotta, mousse, tiramisù, budino, semifreddo)',
                'crumble'  => 'Crumble o Crostata (base croccante: crumble di frutta, crostata, sbriciolata)',
                'biscotti' => 'Biscotti o Pasticcini (biscotti, cookies, muffin, cupcake, pasticcini)',
                'frutta'   => 'Dolce alla Frutta (macedonia creativa, frutta caramellata, sorbetto, frullato dolce)',
            ],
            'succo' => [
                'dolce'        => 'Succo Dolce e Fruttato (mix di frutta dolce: pesca, mela, pera, fragola, banana)',
                'energizzante' => 'Succo Energizzante (con zenzero, curcuma, barbabietola, carota, mela verde)',
                'detox'        => 'Succo Detox / Verde (cetriolo, sedano, spinaci, mela verde, limone)',
                'rinfrescante' => 'Succo Rinfrescante (anguria, menta, lime, cetriolo, acqua di cocco)',
                'vitaminico'   => 'Succo Vitaminico / Agrumi (arancia, pompelmo, limone, kiwi, mandarino)',
            ],
        ],
    ];
}

function evershelfIdentifyPromptFragments(string $lang): array {
    $categories = 'latticini, pasta, bevande, snack, carne, pesce, frutta, verdura, surgelati, condimenti, conserve, cereali, pane, igiene, pulizia, altro';

    $strings = [
        'it' => [
            'intro'        => 'Analizza questa foto di un prodotto alimentare o di uso domestico. Identifica il prodotto nel modo più preciso possibile.',
            'lang_rule'    => 'IMPORTANTE: scrivi nome, descrizione e confidenza in italiano.',
            'json_rule'    => 'Rispondi SOLO con un JSON valido (senza markdown, senza backtick):',
            'name'         => 'Nome del prodotto, il più specifico possibile (es: Yogurt Greco Bianco)',
            'brand'        => 'Marca se visibile (es: Fage, Müller) o stringa vuota',
            'category'     => "Chiave categoria: usa ESATTAMENTE uno di questi token senza tradurli — {$categories}",
            'unit'         => 'Unità di dispensa, UNA sola: pz (pezzi contabili, es. 2 pomodori, 1 melone), g (pesato, es. formaggio al taglio), ml (liquidi versati), conf (confezione sigillata: barattolo, busta, bottiglia)',
            'quantity'     => 'Quantità plausibile per UN acquisto di questo prodotto (es. 2 per due pomodori, 500 per 500 g di formaggio, 1 per un barattolo)',
            'package_size' => 'Peso/volume stampato sulla confezione (es. "500 g", "1 l") o stringa vuota se non è una confezione',
            'search_terms' => 'Termini di ricerca per trovare il prodotto in un database (es: greek yogurt fage, pasta barilla spaghetti)',
            'confidence'   => 'alta/media/bassa',
            'description'  => 'Breve descrizione del prodotto identificato',
        ],
        'en' => [
            'intro'        => 'Analyze this photo of a food or household product. Identify the product as precisely as possible.',
            'lang_rule'    => 'IMPORTANT: write the name, the description and the confidence in English.',
            'json_rule'    => 'Reply with ONLY valid JSON (no markdown, no backticks):',
            'name'         => 'Product name, as specific as possible (e.g. Plain Greek Yogurt)',
            'brand'        => 'Brand if visible (e.g. Fage, Müller) or an empty string',
            'category'     => "Category key: use EXACTLY one of these tokens without translating them — {$categories}",
            'unit'         => 'Pantry unit, exactly ONE of: pz (countable pieces, e.g. 2 tomatoes, 1 melon), g (weighed, e.g. cheese at the counter), ml (poured liquids), conf (sealed pack: jar, bag, bottle)',
            'quantity'     => 'Plausible amount for ONE purchase of this product (e.g. 2 for two tomatoes, 500 for 500 g of cheese, 1 for a jar)',
            'package_size' => 'Weight/volume printed on the pack (e.g. "500 g", "1 l") or an empty string when it is not a pack',
            'search_terms' => 'Search terms to look the product up in a database (e.g. greek yogurt fage, barilla spaghetti pasta)',
            'confidence'   => 'high/medium/low',
            'description'  => 'Short description of the identified product',
        ],
        'de' => [
            'intro'        => 'Analysiere dieses Foto eines Lebensmittels oder Haushaltsprodukts. Identifiziere das Produkt so genau wie möglich.',
            'lang_rule'    => 'WICHTIG: Schreibe Name, Beschreibung und Konfidenz auf Deutsch.',
            'json_rule'    => 'Antworte NUR mit gültigem JSON (kein Markdown, keine Backticks):',
            'name'         => 'Produktname, so genau wie möglich (z. B. Griechischer Joghurt Natur)',
            'brand'        => 'Marke, falls sichtbar (z. B. Fage, Müller), sonst leerer String',
            'category'     => "Kategorieschlüssel: verwende GENAU einen dieser Tokens und übersetze sie nicht — {$categories}",
            'unit'         => 'Vorratseinheit, GENAU eine: pz (zählbare Stücke, z. B. 2 Tomaten, 1 Melone), g (gewogen, z. B. Käse an der Theke), ml (Flüssigkeiten), conf (verschlossene Packung: Glas, Beutel, Flasche)',
            'quantity'     => 'Plausible Menge für EINEN Einkauf dieses Produkts (z. B. 2 für zwei Tomaten, 500 für 500 g Käse, 1 für ein Glas)',
            'package_size' => 'Auf der Packung gedrucktes Gewicht/Volumen (z. B. "500 g", "1 l") oder leerer String, wenn es keine Packung ist',
            'search_terms' => 'Suchbegriffe, um das Produkt in einer Datenbank zu finden (z. B. greek yogurt fage, barilla spaghetti pasta)',
            'confidence'   => 'hoch/mittel/niedrig',
            'description'  => 'Kurze Beschreibung des identifizierten Produkts',
        ],
        'fr' => [
            'intro'        => 'Analyse cette photo d’un produit alimentaire ou ménager. Identifie le produit le plus précisément possible.',
            'lang_rule'    => 'IMPORTANT : écris le nom, la description et la confiance en français.',
            'json_rule'    => 'Réponds UNIQUEMENT avec un JSON valide (sans markdown, sans backticks) :',
            'name'         => 'Nom du produit, le plus précis possible (ex : Yaourt grec nature)',
            'brand'        => 'Marque si visible (ex : Fage, Müller), sinon chaîne vide',
            'category'     => "Clé de catégorie : utilise EXACTEMENT un de ces tokens sans les traduire — {$categories}",
            'unit'         => 'Unité de garde-manger, UNE seule : pz (pièces comptables, ex. 2 tomates, 1 melon), g (pesé, ex. fromage à la coupe), ml (liquides versés), conf (emballage scellé : bocal, sachet, bouteille)',
            'quantity'     => 'Quantité plausible pour UN achat de ce produit (ex. 2 pour deux tomates, 500 pour 500 g de fromage, 1 pour un bocal)',
            'package_size' => 'Poids/volume imprimé sur l’emballage (ex. « 500 g », « 1 l ») ou chaîne vide si ce n’est pas un emballage',
            'search_terms' => 'Termes de recherche pour trouver le produit dans une base de données (ex : greek yogurt fage, barilla spaghetti pasta)',
            'confidence'   => 'élevée/moyenne/faible',
            'description'  => 'Brève description du produit identifié',
        ],
        'es' => [
            'intro'        => 'Analiza esta foto de un producto alimentario o de uso doméstico. Identifica el producto con la mayor precisión posible.',
            'lang_rule'    => 'IMPORTANTE: escribe el nombre, la descripción y la confianza en español.',
            'json_rule'    => 'Responde SOLO con un JSON válido (sin markdown, sin backticks):',
            'name'         => 'Nombre del producto, lo más específico posible (ej.: Yogur griego natural)',
            'brand'        => 'Marca si es visible (ej.: Fage, Müller), o cadena vacía',
            'category'     => "Clave de categoría: usa EXACTAMENTE uno de estos tokens sin traducirlos — {$categories}",
            'unit'         => 'Unidad de despensa, UNA sola: pz (piezas contables, ej. 2 tomates, 1 melón), g (pesado, ej. queso al corte), ml (líquidos servidos), conf (envase sellado: tarro, bolsa, botella)',
            'quantity'     => 'Cantidad plausible para UNA compra de este producto (ej. 2 para dos tomates, 500 para 500 g de queso, 1 para un tarro)',
            'package_size' => 'Peso/volumen impreso en el envase (ej. "500 g", "1 l") o cadena vacía si no es un envase',
            'search_terms' => 'Términos de búsqueda para encontrar el producto en una base de datos (ej.: greek yogurt fage, barilla spaghetti pasta)',
            'confidence'   => 'alta/media/baja',
            'description'  => 'Breve descripción del producto identificado',
        ],
        'zh' => [
            'intro'        => '分析这张食品或家用产品的照片。尽可能准确地识别该产品。',
            'lang_rule'    => '重要：请用简体中文填写名称、描述和置信度。',
            'json_rule'    => '只返回有效的 JSON（不要 markdown，不要反引号）：',
            'name'         => '产品名称，越具体越好（例如：原味希腊酸奶）',
            'brand'        => '可见的品牌（例如：Fage、Müller），没有则留空字符串',
            'category'     => "分类键：必须使用以下其中一个 token，并且不要翻译 — {$categories}",
            'unit'         => '储藏单位，只能选一个：pz（可数件，如 2 个番茄、1 个甜瓜）、g（称重，如柜台奶酪）、ml（倒出的液体）、conf（密封包装：罐、袋、瓶）',
            'quantity'     => '该商品一次购买的合理数量（如两个番茄为 2，500 克奶酪为 500，一罐为 1）',
            'package_size' => '包装上印的重量/容量（如 "500 g"、"1 l"），若不是包装则为空字符串',
            'search_terms' => '用于在数据库中查找该产品的搜索词（例如：greek yogurt fage, barilla spaghetti pasta）',
            'confidence'   => '高/中/低',
            'description'  => '对所识别产品的简短描述',
        ],
    ];

    return $strings[$lang] ?? $strings['en'];
}
