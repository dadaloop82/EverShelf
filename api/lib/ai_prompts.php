<?php
/**
 * EverShelf — AI prompt fragments that depend on the UI language.
 *
 * The photo-identification prompt (action `gemini_identify`) used to be a
 * hardcoded Italian string, so an English — or German, French, Spanish, Chinese —
 * user got Italian product names, descriptions and confidence labels back, even
 * though the client always knew which language the UI was in (issue #260).
 *
 * The fragments live here, outside the router, so scripts/test-ai-language.php can
 * assert that every shipped locale carries the same keys, that Italian stays in
 * the `it` block only, and that the canonical category tokens are never translated.
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
            'search_terms' => '用于在数据库中查找该产品的搜索词（例如：greek yogurt fage, barilla spaghetti pasta）',
            'confidence'   => '高/中/低',
            'description'  => '对所识别产品的简短描述',
        ],
    ];

    return $strings[$lang] ?? $strings['en'];
}
