/**
 * Dispensa Manager - Main Application JS
 * Complete pantry management with barcode scanning and AI identification
 */

// ===== REMOTE LOGGING =====
// Global remote logger: captures all errors, warnings and key operations
const _remoteLogBuffer = [];
let _remoteLogTimer = null;
const _origConsoleError = console.error.bind(console);
const _origConsoleWarn = console.warn.bind(console);

function remoteLog(level, ...args) {
    const msg = args.map(a => {
        if (a instanceof Error) return `${a.name}: ${a.message}`;
        if (typeof a === 'object') try { return JSON.stringify(a); } catch { return String(a); }
        return String(a);
    }).join(' ');
    _remoteLogBuffer.push(`[${level}] ${msg}`);
    if (!_remoteLogTimer) {
        _remoteLogTimer = setTimeout(flushRemoteLog, 2000);
    }
}

function flushRemoteLog() {
    _remoteLogTimer = null;
    if (_remoteLogBuffer.length === 0) return;
    const msgs = _remoteLogBuffer.splice(0);
    fetch(`api/index.php?action=client_log`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: msgs })
    }).catch(() => {});
}

// Override console.error and console.warn to also send remotely
console.error = function(...args) {
    _origConsoleError(...args);
    remoteLog('ERROR', ...args);
};
console.warn = function(...args) {
    _origConsoleWarn(...args);
    remoteLog('WARN', ...args);
};

// Catch unhandled errors
window.addEventListener('error', function(e) {
    remoteLog('UNCAUGHT', `${e.message} at ${e.filename}:${e.lineno}:${e.colno}`);
});
window.addEventListener('unhandledrejection', function(e) {
    remoteLog('UNHANDLED_PROMISE', e.reason);
});

// ===== CONFIGURATION =====
const API_BASE = 'api/index.php';
const LOCATIONS = {
    'dispensa': { icon: '🗄️', label: 'Dispensa' },
    'frigo': { icon: '🧊', label: 'Frigo' },
    'freezer': { icon: '❄️', label: 'Freezer' },
    'altro': { icon: '📦', label: 'Altro' },
};
const CATEGORY_ICONS = {
    'latticini': '🥛', 'carne': '🥩', 'pesce': '🐟', 'frutta': '🍎',
    'verdura': '🥬', 'pasta': '🍝', 'pane': '🍞', 'surgelati': '🧊',
    'bevande': '🥤', 'condimenti': '🧂', 'snack': '🍪', 'conserve': '🥫',
    'cereali': '🌾', 'igiene': '🧴', 'pulizia': '🧹', 'altro': '📦'
};

// Auto-detect location based on category and product name
const CATEGORY_LOCATION = {
    'latticini': 'frigo', 'carne': 'frigo', 'pesce': 'frigo',
    'frutta': 'frigo', 'verdura': 'frigo', 'surgelati': 'freezer',
    'pasta': 'dispensa', 'pane': 'dispensa', 'bevande': 'dispensa',
    'condimenti': 'dispensa', 'snack': 'dispensa', 'conserve': 'dispensa',
    'cereali': 'dispensa', 'igiene': 'altro', 'pulizia': 'altro', 'altro': 'dispensa'
};

// Shopping section (reparto) map — groups categories into grocery departments
const SHOPPING_SECTIONS = [
    { key: 'frutta_verdura', icon: '🥬', label: 'Frutta & Verdura', cats: new Set(['frutta','verdura']) },
    { key: 'carne_pesce',    icon: '🥩', label: 'Carne & Pesce',    cats: new Set(['carne','pesce']) },
    { key: 'latticini',      icon: '🥛', label: 'Latticini & Fresco', cats: new Set(['latticini']) },
    { key: 'pane_dolci',     icon: '🍞', label: 'Pane & Dolci',     cats: new Set(['pane','snack','cereali']) },
    { key: 'pasta',          icon: '🍝', label: 'Pasta & Cereali',  cats: new Set(['pasta']) },
    { key: 'conserve',       icon: '🥫', label: 'Conserve & Salse', cats: new Set(['conserve','condimenti']) },
    { key: 'surgelati',      icon: '❄️',  label: 'Surgelati',        cats: new Set(['surgelati']) },
    { key: 'bevande',        icon: '🥤', label: 'Bevande',          cats: new Set(['bevande']) },
    { key: 'pulizia_igiene', icon: '🧴', label: 'Pulizia & Igiene', cats: new Set(['igiene','pulizia']) },
    { key: 'altro',          icon: '📦', label: 'Altro',            cats: new Set(['altro']) },
];

function getItemSection(name) {
    const cat = guessCategoryFromName(name) || 'altro';
    for (const s of SHOPPING_SECTIONS) { if (s.cats.has(cat)) return s; }
    return SHOPPING_SECTIONS[SHOPPING_SECTIONS.length - 1];
}

const URGENCY_WEIGHT = { critical: 4, high: 3, medium: 2, low: 1 };
const URGENCY_BG = {
    critical: 'rgba(194,65,12,0.14)',
    high:     'rgba(234,88,12,0.09)',
    medium:   'rgba(245,158,11,0.07)',
    low:      'rgba(34,197,94,0.05)',
};

// Map Open Food Facts categories to local categories
function mapToLocalCategory(ofCategory, productName) {
    if (!ofCategory) {
        // No category tag — try to guess from product name
        return guessCategoryFromName(productName || '');
    }
    const cat = ofCategory.toLowerCase();
    // Direct match with our local keys
    for (const key of Object.keys(CATEGORY_ICONS)) {
        if (cat === key) return key;
    }
    
    // Handle specific Open Food Facts tags FIRST (before generic regex)
    // "plant-based-foods-and-beverages" is a catch-all — use product name to decide
    if (/plant-based-foods/.test(cat)) {
        return guessCategoryFromName(productName || '');
    }
    // "beverages-and-beverages-preparations" = actual beverages
    if (/^en:beverages/.test(cat)) return 'bevande';
    // sweeteners = condimenti
    if (/sweetener|dolcific/.test(cat)) return 'condimenti';
    
    // Specific tag patterns
    if (/dairy|lait|cheese|fromage|yoghurt|milk|latticin|latte/.test(cat)) return 'latticini';
    if (/meat|viande|carne|sausage|salum|prosciutt/.test(cat)) return 'carne';
    if (/fish|poisson|pesce|seafood|tuna|tonno|salmone/.test(cat)) return 'pesce';
    if (/fruit|frutta|juice|succo|apple|banana/.test(cat)) return 'frutta';
    if (/vegetable|verdur|legum|salad|insalat|tomato|pomodor/.test(cat)) return 'verdura';
    if (/pasta|rice|riso|noodle|spaghetti|penne|grain/.test(cat)) return 'pasta';
    if (/bread|pane|forno|biscott|toast|cracker|grissini|fette/.test(cat)) return 'pane';
    if (/frozen|surgelé|surgel|gelat/.test(cat)) return 'surgelati';
    if (/sauce|condiment|oil|olio|vinegar|aceto|mayo|ketchup|spice|salt|sugar|zuccher/.test(cat)) return 'condimenti';
    if (/snack|chip|crisp|chocolate|cioccolat|candy|biscuit|cookie|wafer|merendine|patatine/.test(cat)) return 'snack';
    if (/conserve|canned|can|pelati|passata|preserve|jam|marmellat|miele|honey/.test(cat)) return 'conserve';
    if (/cereal|muesli|granola|oat|fiocchi/.test(cat)) return 'cereali';
    if (/hygiene|soap|shampoo|igien|dentifricio|deodorant/.test(cat)) return 'igiene';
    if (/clean|detergent|pulizia|detersiv/.test(cat)) return 'pulizia';
    // Beverage check LAST (to avoid false matches on compound tags)
    if (/^(?!.*plant-based).*(beverage|drink|boisson|bevand|water|acqua|beer|birra|wine|vino|coffee|caffè|tea\b)/.test(cat)) return 'bevande';
    return 'altro';
}

// Guess a local category purely from product name
function guessCategoryFromName(name) {
    if (!name) return 'altro';
    const n = name.toLowerCase();
    // Pasta & Rice
    if (/spaghetti|penne|fusilli|rigatoni|linguine|orecchiette|farfalle|pasta\b|riso\b|basmati|carnaroli|arborio/.test(n)) return 'pasta';
    // Pane & Forno
    if (/pane\b|fette biscottate|grissini|cracker|toast|piadina|piadelle|focaccia|panini|sandwich|taralli/.test(n)) return 'pane';
    // Conserve
    if (/passata|pelati|pomodoro|sugo|polpa di pomod|marmellata|miele|legumi|ceci|fagioli|lenticchie|olive/.test(n)) return 'conserve';
    // Condimenti
    if (/olio\b|aceto|sale\b|pepe\b|zucchero|zuccher|farina|maionese|ketchup|senape|salsa/.test(n)) return 'condimenti';
    // Bevande
    if (/acqua|birra|vino|succo|spremuta|coca.cola|aranciata|caffè|tè\b|tea\b|latte\b/.test(n)) return 'bevande';
    // Latticini
    if (/latte\b|yogurt|formaggio|mozzarella|burro|panna|ricotta|mascarpone|gorgonzola|parmigiano|grana\b/.test(n)) return 'latticini';
    // Carne
    if (/pollo|manzo|maiale|vitello|tacchino|prosciutto|salame|bresaola|mortadella|wurstel|speck/.test(n)) return 'carne';
    // Pesce
    if (/tonno|salmone|merluzzo|pesce|sgombro|gamberi|acciughe/.test(n)) return 'pesce';
    // Frutta
    if (/mela|mele|banana|arancia|pera|fragola|uva|kiwi|limone|frutta/.test(n)) return 'frutta';
    // Verdura
    if (/insalata|zucchina|pomodor|cipolla|carota|spinaci|rucola|peperoni|melanzane|broccoli|patata/.test(n)) return 'verdura';
    // Surgelati
    if (/surgelat|frozen|findus|4.salti|gelato/.test(n)) return 'surgelati';
    // Snack
    if (/biscott|cioccolat|nutella|merendine|patatine|caramelle|wafer|sfornatini/.test(n)) return 'snack';
    // Cereali
    if (/cereali|muesli|fiocchi|granola|polenta/.test(n)) return 'cereali';
    // Igiene / Pulizia
    if (/sapone|shampoo|dentifricio|deodorante/.test(n)) return 'igiene';
    if (/detersivo|pulito|sgrassatore/.test(n)) return 'pulizia';
    return 'altro';
}

// Determine safety level for expired products
// Returns { level: 'danger'|'warning'|'ok', icon, label, tip }
function getExpiredSafety(item, daysExpired) {
    const cat = mapToLocalCategory(item.category || '', item.name || '');
    const loc = (item.location || '').toLowerCase();
    const inFreezer = loc === 'freezer';
    const inFrigo = loc === 'frigo';

    // === FREEZER: il congelamento allunga molto la vita ===
    // Carne/pesce in freezer: +3 mesi. Verdura/frutta: +6 mesi. Pane: +2 mesi.
    // Latticini in freezer: +1-2 mesi. Tutto il resto: +3-6 mesi.
    if (inFreezer) {
        const highRiskFreezer = ['carne', 'pesce'];
        const medRiskFreezer = ['latticini', 'pane'];
        const produceRiskFreezer = ['verdura', 'frutta'];

        let bonusDays;
        if (highRiskFreezer.includes(cat)) bonusDays = 90;       // +3 mesi
        else if (produceRiskFreezer.includes(cat)) bonusDays = 180; // +6 mesi
        else if (medRiskFreezer.includes(cat)) bonusDays = 60;    // +2 mesi
        else bonusDays = 120;                                      // +4 mesi default

        const effectiveDays = daysExpired - bonusDays;

        if (effectiveDays <= 0) {
            return { level: 'ok', icon: '✅', label: 'OK', tip: `In freezer: ancora sicuro (~${bonusDays - daysExpired}g di margine)` };
        }
        if (effectiveDays <= 30) {
            return { level: 'warning', icon: '👀', label: 'Controlla', tip: `In freezer da molto, potrebbe aver perso qualità. Consumare presto` };
        }
        return { level: 'danger', icon: '🗑️', label: 'Buttare', tip: 'In freezer da troppo tempo, rischio di bruciatura da gelo e degrado' };
    }

    // === FRIGO e DISPENSA ===
    const highRisk = ['latticini', 'carne', 'pesce', 'verdura', 'frutta'];
    const medRisk = ['pane', 'surgelati'];

    if (highRisk.includes(cat)) {
        if (inFrigo && daysExpired <= 2) {
            return { level: 'warning', icon: '👀', label: 'Controlla', tip: 'Scaduto da poco, controlla odore e aspetto prima di consumare' };
        }
        return { level: 'danger', icon: '🗑️', label: 'Buttare', tip: 'Prodotto deperibile scaduto: da buttare per sicurezza' };
    }

    if (medRisk.includes(cat)) {
        if (daysExpired <= 7) {
            return { level: 'warning', icon: '👀', label: 'Controlla', tip: 'Controlla aspetto e odore prima di consumare' };
        }
        if (daysExpired <= 30) {
            return { level: 'warning', icon: '👀', label: 'Controlla', tip: 'Scaduto da un po\', verificare bene prima dell\'uso' };
        }
        return { level: 'danger', icon: '🗑️', label: 'Buttare', tip: 'Troppo tempo dalla scadenza, meglio buttare' };
    }

    // LOW RISK - lunga conservazione (pasta, conserve, condimenti, cereali, snack)
    if (daysExpired <= 30) {
        return { level: 'ok', icon: '✅', label: 'OK', tip: 'Prodotto a lunga conservazione, ancora sicuro da consumare' };
    }
    if (daysExpired <= 180) {
        return { level: 'warning', icon: '👀', label: 'Controlla', tip: 'Scaduto da oltre un mese, controllare integrità confezione' };
    }
    return { level: 'danger', icon: '🗑️', label: 'Buttare', tip: 'Scaduto da troppo tempo, meglio non rischiare' };
}

// Nice Italian labels for local categories
const CATEGORY_LABELS = {
    'latticini': '🥛 Latticini', 'carne': '🥩 Carne', 'pesce': '🐟 Pesce',
    'frutta': '🍎 Frutta', 'verdura': '🥬 Verdura', 'pasta': '🍝 Pasta & Riso',
    'pane': '🍞 Pane & Forno', 'surgelati': '🧊 Surgelati', 'bevande': '🥤 Bevande',
    'condimenti': '🧂 Condimenti', 'snack': '🍪 Snack & Dolci', 'conserve': '🥫 Conserve',
    'cereali': '🌾 Cereali & Legumi', 'igiene': '🧴 Igiene', 'pulizia': '🧹 Pulizia',
    'altro': '📦 Altro'
};

// Detect best unit/quantity from Open Food Facts quantity_info string
// Returns the actual package weight/volume as default (e.g. 700g → unit:'g', quantity:700)
function detectUnitAndQuantity(quantityInfo) {
    if (!quantityInfo) return { unit: 'pz', quantity: 1, weightInfo: '' };
    const q = quantityInfo.toLowerCase().trim();
    // Match multi-pack patterns like "6 x 1l", "4 x 125g" → confezioni
    const multiMatch = q.match(/(\d+)\s*x\s*([\d.,]+)\s*(ml|l|g|kg|cl)/i);
    if (multiMatch) {
        const count = parseInt(multiMatch[1]);
        let perUnitVal = parseFloat(multiMatch[2].replace(',', '.'));
        let perUnitUnit = multiMatch[3].toLowerCase();
        if (perUnitUnit === 'cl') { perUnitUnit = 'ml'; perUnitVal *= 10; }
        if (perUnitUnit === 'kg') { perUnitUnit = 'g'; perUnitVal *= 1000; }
        if (perUnitUnit === 'l') { perUnitUnit = 'ml'; perUnitVal *= 1000; }
        return { unit: 'conf', quantity: perUnitVal, packageUnit: perUnitUnit, confCount: count, weightInfo: quantityInfo };
    }
    // Match single package patterns like "500 g", "1 l", "750 ml", "1.5 kg"
    const match = q.match(/([\d.,]+)\s*(kg|g|l|ml|cl)/i);
    if (match) {
        let unit = match[2].toLowerCase();
        let val = parseFloat(match[1].replace(',', '.'));
        if (unit === 'cl') { unit = 'ml'; val *= 10; }
        if (unit === 'kg') { unit = 'g'; val *= 1000; }
        if (unit === 'l') { unit = 'ml'; val *= 1000; }
        return { unit, quantity: val, weightInfo: quantityInfo };
    }
    return { unit: 'pz', quantity: 1, weightInfo: quantityInfo };
}

// Estimate expiry days based on category/product type
const EXPIRY_DAYS = {
    'latticini': 7, 'carne': 4, 'pesce': 3, 'frutta': 7, 'verdura': 7,
    'pasta': 730, 'pane': 4, 'surgelati': 180, 'bevande': 365, 'condimenti': 365,
    'snack': 180, 'conserve': 730, 'cereali': 365, 'igiene': 1095, 'pulizia': 1095, 'altro': 180
};

// More specific expiry by product name keywords
function estimateExpiryDays(product, location) {
    const name = (product.name || '').toLowerCase();
    const cat = (product.category || '').toLowerCase();
    const loc = (location || '').toLowerCase();
    
    let days;
    
    // Specific product overrides
    if (/latte\s+(fresco|intero|parzial|scremato)/.test(name)) days = 7;
    else if (/latte\s+uht|latte\s+a\s+lunga/.test(name)) days = 90;
    else if (/yogurt/.test(name)) days = 21;
    else if (/mozzarella|burrata|stracciatella/.test(name)) days = 5;
    else if (/formaggio\s+(fresco|ricotta|mascarpone|stracchino|crescenza)/.test(name)) days = 10;
    else if (/parmigiano|grana|pecorino|provolone/.test(name)) days = 60;
    else if (/burro/.test(name)) days = 60;
    else if (/panna/.test(name)) days = 14;
    else if (/prosciutto\s+cotto|mortadella|wurstel/.test(name)) days = 7;
    else if (/prosciutto\s+crudo|salame|bresaola|speck/.test(name)) days = 30;
    else if (/nduja/.test(name)) days = 90;
    else if (/uova/.test(name)) days = 28;
    else if (/pane\s+fresco|pane\s+in\s+cassetta/.test(name)) days = 5;
    else if (/pane\s+confezionato|pan\s+carr|pancarrè/.test(name)) days = 14;
    else if (/insalata|rucola|spinaci\s+freschi/.test(name)) days = 5;
    else if (/pollo|tacchino|maiale|manzo|vitello|sovracosci|cosci/.test(name)) days = 3;
    else if (/salmone|tonno\s+fresco|pesce/.test(name) && !/tonno\s+in\s+scatola|tonno\s+rio/.test(name)) days = 2;
    else if (/tonno\s+in\s+scatola|tonno\s+rio|sgombro\s+in/.test(name)) days = 1095;
    else if (/surgelat|frozen|findus|4\s*salti/.test(name)) days = 180;
    else if (/gelato/.test(name)) days = 365;
    else if (/succo|spremuta/.test(name)) days = 7;
    else if (/birra|vino/.test(name)) days = 365;
    else if (/acqua/.test(name)) days = 365;
    else if (/mela|mele\b/.test(name)) days = 7;
    else if (/arancia|arance|mandarini|agrumi/.test(name)) days = 7;
    else if (/banana|banane/.test(name)) days = 5;
    else if (/pera|pere\b|fragola|fragole|uva|kiwi/.test(name)) days = 5;
    else if (/carota|carote|zucchina|zucchine|peperoni|melanzane/.test(name)) days = 7;
    else if (/broccoli|cavolfiore|cavolo|spinaci|bietola/.test(name)) days = 5;
    else if (/cipolla|cipolle/.test(name)) days = 10;
    else if (/patata|patate/.test(name)) days = 14;
    else if (/biscott|cracker|grissini|fette\s+biscott/.test(name)) days = 180;
    else if (/nutella|marmellata|miele/.test(name)) days = 365;
    else if (/passata|pelati|pomodor/.test(name)) days = 730;
    else if (/olio|aceto/.test(name)) days = 548;
    else {
        // Fallback to category
        days = 180; // generic default
        for (const [key, d] of Object.entries(EXPIRY_DAYS)) {
            if (cat.includes(key)) { days = d; break; }
        }
    }
    
    // Fridge extends shelf life for produce and short-lived items (sealed only)
    if (loc === 'frigo') {
        // Specific fridge-friendly produce overrides
        if (/mela|mele/.test(name)) days = Math.max(days, 28);
        else if (/arancia|arance|agrumi|mandarini|limone|limoni/.test(name)) days = Math.max(days, 21);
        else if (/carota|carote/.test(name)) days = Math.max(days, 21);
        else if (/cipolla/.test(name)) days = Math.max(days, 14);
        else if (/patata|patate/.test(name)) days = Math.max(days, 21);
        else if (/pera|pere/.test(name)) days = Math.max(days, 21);
        else if (/kiwi/.test(name)) days = Math.max(days, 28);
        else if (/uva/.test(name)) days = Math.max(days, 14);
        else if (/fragola|fragole/.test(name)) days = Math.max(days, 7);
        else if (/peperoni/.test(name)) days = Math.max(days, 14);
        else if (/zucchina|zucchine/.test(name)) days = Math.max(days, 14);
        else if (/melanzane/.test(name)) days = Math.max(days, 14);
        else if (/broccoli|cavolfiore|cavolo/.test(name)) days = Math.max(days, 10);
        // General fridge bonus: fruits and vegs that aren't already long
        else if (days <= 7 && (/frutta|fruit/.test(cat) || /verdur|vegetable|plant-based/.test(cat))) {
            days = Math.round(days * 2); // ~double shelf life in fridge
        }
    }
    
    // Freezer extends shelf life significantly
    if (loc === 'freezer' && days < 180) {
        // Fresh meat/fish: 3-6 months in freezer
        if (days <= 4) days = 120;
        // Short-lived (cheese, dairy, bread): 2-3 months
        else if (days <= 14) days = 75;
        // Medium (yogurt, cured meats): 3-4 months
        else if (days <= 30) days = 120;
        // Already long-lasting: at least 6 months
        else days = Math.max(days, 180);
    }
    
    return days;
}

function formatEstimatedExpiry(days) {
    if (days <= 7) return `~${days} giorni`;
    if (days <= 30) return `~${Math.round(days / 7)} settimane`;
    if (days <= 365) return `~${Math.round(days / 30)} mesi`;
    return `~${Math.round(days / 365)} anni`;
}

/**
 * Estimate shelf life in days for an OPENED product.
 * Much shorter than sealed shelf life — based on typical "once opened, consume within X days".
 */
function estimateOpenedExpiryDays(product, location) {
    const name = (product.name || '').toLowerCase();
    const cat = (product.category || '').toLowerCase();
    const loc = (location || '').toLowerCase();

    // Freezer: opened items still last a long time
    if (loc === 'freezer') return 90;
    // Dispensa: opened dry goods
    if (loc === 'dispensa') return 30;

    // Specific product overrides (fridge)
    if (/latte\s+(fresco|intero|parzial|scremato)/.test(name)) return 3;
    if (/latte\s+uht|latte\s+a\s+lunga/.test(name)) return 5;
    if (/latte/.test(name)) return 4;
    if (/yogurt/.test(name)) return 3;
    if (/mozzarella|burrata|stracciatella/.test(name)) return 2;
    if (/philadelphia|spalmabile/.test(name)) return 7;
    if (/formaggio.*(fresco|ricotta|mascarpone|stracchino|crescenza)/.test(name)) return 5;
    if (/parmigiano|grana|pecorino|provolone/.test(name)) return 21;
    if (/formaggio/.test(name)) return 10;
    if (/burro/.test(name)) return 21;
    if (/panna/.test(name)) return 3;
    if (/prosciutto\s+cotto|mortadella|wurstel/.test(name)) return 3;
    if (/prosciutto\s+crudo|salame|bresaola|speck|pancetta|nduja/.test(name)) return 7;
    if (/pollo|tacchino|maiale|manzo|vitello/.test(name)) return 2;
    if (/salmone|tonno\s+fresco|pesce/.test(name)) return 2;
    if (/passata|pelati|polpa|sugo/.test(name)) return 5;
    if (/marmellata|confettura/.test(name)) return 30;
    if (/miele/.test(name)) return 180;
    if (/nutella/.test(name)) return 60;
    if (/succo|spremuta/.test(name)) return 4;
    if (/olio|aceto/.test(name)) return 90;
    if (/vino|birra/.test(name)) return 5;
    if (/limone|limmi/.test(name)) return 21;
    if (/tonno\s+in\s+scatola|tonno\s+rio|sgombro\s+in/.test(name)) return 3;
    if (/insalata|rucola|spinaci/.test(name)) return 3;

    // Category fallbacks
    if (/dairy|latticin|lait|dairies/.test(cat)) return 5;
    if (/meat|carne|meats/.test(cat)) return 3;
    if (/fish|pesce/.test(cat)) return 2;
    if (/fruit|frutta/.test(cat)) return 5;
    if (/verdur|vegetable|plant-based/.test(cat)) return 5;
    if (/conserve/.test(cat)) return 5;
    if (/condimenti|sauce/.test(cat)) return 21;
    if (/bevand|beverage/.test(cat)) return 4;

    return 5; // safe default for fridge
}

function addDays(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().split('T')[0];
}

// Guess location from product name keywords (fallback if no category)
function guessLocationFromName(name) {
    const n = (name || '').toLowerCase();
    // Frigo keywords
    if (/latte|yogurt|formaggio|mozzarella|burro|panna|uova|prosciutto|salame|wurstel|ricotta|mascarpone|gorgonzola|insalata|rucola|spinaci|pollo|manzo|maiale|salmone|tonno fresco|bresaola/.test(n)) return 'frigo';
    // Freezer keywords
    if (/surgel|frozen|gelato|ghiaccioli|bastoncini|findus|4 salti|pizza surgel|verdure surgel|minestrone surg/.test(n)) return 'freezer';
    // Dispensa keywords
    if (/pasta|riso|farina|zucchero|sale|olio|aceto|biscott|cracker|grissini|caffè|tè|the |tea |tonno|pelati|passata|legumi|ceci|fagioli|lenticchie|cereali|muesli|marmell|nutella|miele|cioccolat/.test(n)) return 'dispensa';
    return null; // unknown
}

function guessLocation(product) {
    // 1. Category-based
    if (product.category) {
        const cat = product.category.toLowerCase().replace(/^en:/, '').split(',')[0].trim();
        // Check our map
        for (const [key, loc] of Object.entries(CATEGORY_LOCATION)) {
            if (cat.includes(key)) return loc;
        }
        // Open Food Facts categories
        if (/dairy|lait|cheese|fromage|yoghurt|milk|latticin/i.test(cat)) return 'frigo';
        if (/meat|viande|carne|fish|poisson|pesce/i.test(cat)) return 'frigo';
        if (/frozen|surgelé|surgel/i.test(cat)) return 'freezer';
        if (/fruit|vegetable|verdur|frutta/i.test(cat)) return 'frigo';
        if (/beverage|drink|boisson|bevand/i.test(cat)) return 'dispensa';
        if (/pasta|cereal|grain|bread|biscuit|snack|sauce|condiment|conserv|can/i.test(cat)) return 'dispensa';
    }
    // 2. Name-based fallback
    const nameLoc = guessLocationFromName(product.name);
    if (nameLoc) return nameLoc;
    // 3. Default
    return 'dispensa';
}

// ===== STATE =====
let currentProduct = null;
let currentInventory = [];
let _actionInventoryItems = [];
let currentLocation = '';
let scannerStream = null;
let quaggaRunning = false;
let aiStream = null;
let _scanZoomLevel = 1; // 1 or 2

async function toggleScanZoom() {
    _scanZoomLevel = _scanZoomLevel === 1 ? 2 : 1;
    const btn = document.getElementById('scan-zoom-btn');
    if (btn) btn.textContent = `x${_scanZoomLevel}`;
    if (scannerStream) {
        const track = scannerStream.getVideoTracks()[0];
        if (track) {
            const caps = track.getCapabilities ? track.getCapabilities() : {};
            if (caps.zoom) {
                // Hardware zoom (Android Chrome)
                const z = _scanZoomLevel === 2
                    ? Math.min(caps.zoom.max, caps.zoom.min * 2 || 2)
                    : caps.zoom.min;
                try { await track.applyConstraints({ advanced: [{ zoom: z }] }); } catch(e) {}
            } else {
                // Software zoom via CSS scale on the video element
                const video = document.getElementById('scanner-video');
                if (video) video.style.transform = _scanZoomLevel === 2 ? 'scale(2)' : 'scale(1)';
            }
        }
    }
}

// ===== CAMERA HELPER =====
function getCameraConstraints(extraVideo = {}) {
    const s = getSettings();
    const mode = s.camera_facing || 'environment';
    // Front cameras on older devices often have lower resolution — don't over-request
    const isFront = (mode === 'user');
    const videoConstraints = {
        width: { ideal: isFront ? 640 : 1280 },
        height: { ideal: isFront ? 480 : 720 },
        ...extraVideo
    };
    if (mode === 'environment' || mode === 'user') {
        videoConstraints.facingMode = mode;
    } else {
        // Specific deviceId selected
        videoConstraints.deviceId = { exact: mode };
    }
    return { video: videoConstraints };
}

function isFrontCamera() {
    const s = getSettings();
    return (s.camera_facing || 'environment') === 'user';
}

async function enumerateCameras() {
    try {
        // Need a temporary stream to get device labels
        const tempStream = await navigator.mediaDevices.getUserMedia({ video: true });
        const devices = await navigator.mediaDevices.enumerateDevices();
        tempStream.getTracks().forEach(t => t.stop());
        return devices.filter(d => d.kind === 'videoinput');
    } catch(e) {
        return [];
    }
}

// ===== SETTINGS / CONFIG =====
let _settingsCache = null;
let _settingsDirty = false;

function getSettings() {
    if (!_settingsCache) {
        try {
            _settingsCache = JSON.parse(localStorage.getItem('dispensa_settings') || '{}');
        } catch(e) { _settingsCache = {}; }
    }
    const s = _settingsCache;
    // Build recipe_prefs array from individual booleans
    s.recipe_prefs = [];
    if (s.pref_veloce) s.recipe_prefs.push('veloce');
    if (s.pref_pocafame) s.recipe_prefs.push('pocafame');
    if (s.pref_scadenze) s.recipe_prefs.push('scadenze');
    if (s.pref_healthy) s.recipe_prefs.push('salutare');
    if (s.pref_opened) s.recipe_prefs.push('opened');
    if (s.pref_zerowaste) s.recipe_prefs.push('zerowaste');
    s.dietary_restrictions = s.dietary || '';
    return s;
}

function saveSettingsToStorage(settings) {
    _settingsCache = settings;
    localStorage.setItem('dispensa_settings', JSON.stringify(settings));
    // Persist to DB
    _settingsDirty = true;
    _debouncedSyncSettings();
}

const _debouncedSyncSettings = debounce(function() {
    if (!_settingsDirty) return;
    _settingsDirty = false;
    const s = getSettings();
    // Don't sync secrets or device-specific settings to shared DB
    const shared = {
        default_persons: s.default_persons,
        pref_veloce: s.pref_veloce,
        pref_pocafame: s.pref_pocafame,
        pref_scadenze: s.pref_scadenze,
        pref_healthy: s.pref_healthy,
        pref_opened: s.pref_opened,
        pref_zerowaste: s.pref_zerowaste,
        dietary: s.dietary,
        appliances: s.appliances,
        spesa_provider: s.spesa_provider,
        spesa_ai_prompt: s.spesa_ai_prompt,
        spesa_email: s.spesa_email,
        spesa_password: s.spesa_password,
        spesa_logged_in: s.spesa_logged_in,
        spesa_user: s.spesa_user,
        spesa_data: s.spesa_data,
        spesa_token: s.spesa_token
    };
    api('app_settings_save', {}, 'POST', { settings: { user_prefs: shared } }).catch(() => {});
}, 1000);

function debounce(fn, ms) {
    let t; return function(...args) { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

async function syncSettingsFromDB() {
    try {
        const res = await api('app_settings_get');
        if (res.success && res.settings) {
            if (res.settings.user_prefs) {
                const db = res.settings.user_prefs;
                const s = getSettings();
                // Merge DB settings into local (DB wins for shared prefs)
                for (const key of ['default_persons','pref_veloce','pref_pocafame','pref_scadenze',
                    'pref_healthy','pref_opened','pref_zerowaste','dietary','appliances',
                    'spesa_provider','spesa_ai_prompt','spesa_email','spesa_password',
                    'spesa_logged_in','spesa_user','spesa_data','spesa_token']) {
                    if (db[key] !== undefined) s[key] = db[key];
                }
                _settingsCache = s;
                localStorage.setItem('dispensa_settings', JSON.stringify(s));
            }
            if (res.settings.review_confirmed) {
                _reviewConfirmedCache = res.settings.review_confirmed;
            }
        }
    } catch(e) { /* offline, use local */ }
}

async function loadSettingsUI() {
    const s = getSettings();
    document.getElementById('setting-gemini-key').value = s.gemini_key || '';
    document.getElementById('setting-bring-email').value = s.bring_email || '';
    document.getElementById('setting-bring-password').value = s.bring_password || '';
    document.getElementById('setting-default-persons').value = s.default_persons || 1;
    document.getElementById('setting-pref-veloce').checked = !!s.pref_veloce;
    document.getElementById('setting-pref-pocafame').checked = !!s.pref_pocafame;
    document.getElementById('setting-pref-scadenze').checked = !!s.pref_scadenze;
    document.getElementById('setting-pref-healthy').checked = !!s.pref_healthy;
    document.getElementById('setting-pref-opened').checked = !!s.pref_opened;
    document.getElementById('setting-pref-zerowaste').checked = !!s.pref_zerowaste;
    document.getElementById('setting-dietary').value = s.dietary || '';
    // Camera
    const cameraSelect = document.getElementById('setting-camera-facing');
    if (cameraSelect) cameraSelect.value = s.camera_facing || 'environment';
    loadCameraDevices();
    renderAppliances(s.appliances || []);
    loadSpesaSettings();
    const mealPlanEnabled = s.meal_plan_enabled !== false;
    const mpEnabledEl = document.getElementById('setting-meal-plan-enabled');
    if (mpEnabledEl) mpEnabledEl.checked = mealPlanEnabled;
    const mpConfigSection = document.getElementById('meal-plan-config-section');
    if (mpConfigSection) mpConfigSection.style.display = mealPlanEnabled ? '' : 'none';
    const mpLegendCard = document.getElementById('meal-plan-legend-card');
    if (mpLegendCard) mpLegendCard.style.display = mealPlanEnabled ? '' : 'none';
    renderMealPlanEditor();
    // Render legend
    const legend = document.querySelector('.mplan-legend');
    if (legend) {
        legend.innerHTML = MEAL_PLAN_TYPES.map(t =>
            `<span class="mplan-badge" style="opacity:0.85">${t.icon} ${t.label}</span>`
        ).join('');
    }
    // TTS settings — init defaults on first load
    if (!s._tts_initialized) {
        s.tts_url = s.tts_url || 'http://192.168.1.133:8123/api/events/noemi_speak';
        s.tts_token = s.tts_token || 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiI2OGQ3Njk1N2E0MjY0ZTBjOWQ4YjczZDY4ZDVmMWJlZCIsImlhdCI6MTc2MDI1NjIxNSwiZXhwIjoyMDc1NjE2MjE1fQ.X5UyMMPd7wTA6Gh11Nzg7Ox-enlDDom_lJIAJruUtcE';
        s.tts_payload_key = s.tts_payload_key || 'message';
        s.tts_method = s.tts_method || 'POST';
        s.tts_auth_type = s.tts_auth_type || 'bearer';
        s.tts_content_type = s.tts_content_type || 'application/json';
        s.tts_enabled = s.tts_enabled !== undefined ? s.tts_enabled : true;
        s._tts_initialized = true;
        saveSettingsToStorage(s);
    }
    const ttsEnabledEl = document.getElementById('setting-tts-enabled');
    if (ttsEnabledEl) ttsEnabledEl.checked = s.tts_enabled === true;
    const ttsUrlEl = document.getElementById('setting-tts-url');
    if (ttsUrlEl) ttsUrlEl.value = s.tts_url || '';
    const ttsMethEl = document.getElementById('setting-tts-method');
    if (ttsMethEl) ttsMethEl.value = s.tts_method || 'POST';
    const ttsAuthTypeEl = document.getElementById('setting-tts-auth-type');
    if (ttsAuthTypeEl) { ttsAuthTypeEl.value = s.tts_auth_type || 'bearer'; onTtsAuthTypeChange(ttsAuthTypeEl.value); }
    const ttsTokenEl = document.getElementById('setting-tts-token');
    if (ttsTokenEl) ttsTokenEl.value = s.tts_token || '';
    const ttsAuthHdrNameEl = document.getElementById('setting-tts-auth-header-name');
    if (ttsAuthHdrNameEl) ttsAuthHdrNameEl.value = s.tts_auth_header_name || '';
    const ttsAuthHdrValEl = document.getElementById('setting-tts-auth-header-value');
    if (ttsAuthHdrValEl) ttsAuthHdrValEl.value = s.tts_auth_header_value || '';
    const ttsCtEl = document.getElementById('setting-tts-content-type');
    if (ttsCtEl) ttsCtEl.value = s.tts_content_type || 'application/json';
    const ttsPayloadKeyEl = document.getElementById('setting-tts-payload-key');
    if (ttsPayloadKeyEl) ttsPayloadKeyEl.value = s.tts_payload_key || 'message';
    const ttsExtraEl = document.getElementById('setting-tts-extra-fields');
    if (ttsExtraEl) ttsExtraEl.value = s.tts_extra_fields || '';
    
    // Load server-side settings if not already set locally
    try {
        const serverSettings = await api('get_settings');
        if (!s.gemini_key && serverSettings.gemini_key) {
            document.getElementById('setting-gemini-key').value = serverSettings.gemini_key;
        }
        if (!s.bring_email && serverSettings.bring_email) {
            document.getElementById('setting-bring-email').value = serverSettings.bring_email;
        }
    } catch(e) { /* ignore */ }
}

function renderAppliances(appliances) {
    const container = document.getElementById('appliances-list');
    if (!appliances || appliances.length === 0) {
        container.innerHTML = '<p style="color:var(--text-muted);font-size:0.85rem;padding:8px 0">Nessun elettrodomestico aggiunto</p>';
        return;
    }
    container.innerHTML = appliances.map((a, i) => `
        <div class="appliance-item">
            <span>🔌 ${escapeHtml(a)}</span>
            <button class="appliance-remove" onclick="removeAppliance(${i})" title="Rimuovi">✕</button>
        </div>
    `).join('');
}

async function loadCameraDevices() {
    const select = document.getElementById('setting-camera-facing');
    if (!select) return;
    const s = getSettings();
    const current = s.camera_facing || 'environment';
    // Remove old device-specific options (keep first 2: environment, user)
    while (select.options.length > 2) select.remove(2);
    const cameras = await enumerateCameras();
    cameras.forEach(cam => {
        const opt = document.createElement('option');
        opt.value = cam.deviceId;
        opt.textContent = cam.label || `Camera ${cam.deviceId.slice(0, 8)}…`;
        select.appendChild(opt);
    });
    select.value = current;
}

function addAppliance() {
    const input = document.getElementById('new-appliance-input');
    const name = (input.value || '').trim();
    if (!name) return;
    const s = getSettings();
    if (!s.appliances) s.appliances = [];
    if (s.appliances.some(a => a.toLowerCase() === name.toLowerCase())) {
        showToast('Elettrodomestico già presente', 'error');
        return;
    }
    s.appliances.push(name);
    saveSettingsToStorage(s);
    renderAppliances(s.appliances);
    input.value = '';
    showToast('Elettrodomestico aggiunto', 'success');
}

function addApplianceQuick(name) {
    const s = getSettings();
    if (!s.appliances) s.appliances = [];
    if (s.appliances.some(a => a.toLowerCase() === name.toLowerCase())) {
        showToast('Già presente', 'error');
        return;
    }
    s.appliances.push(name);
    saveSettingsToStorage(s);
    renderAppliances(s.appliances);
    showToast(`${name} aggiunto`, 'success');
}

function removeAppliance(idx) {
    const s = getSettings();
    if (!s.appliances) return;
    s.appliances.splice(idx, 1);
    saveSettingsToStorage(s);
    renderAppliances(s.appliances);
}

async function saveSettings() {
    const s = getSettings();
    s.gemini_key = document.getElementById('setting-gemini-key').value.trim();
    s.bring_email = document.getElementById('setting-bring-email').value.trim();
    s.bring_password = document.getElementById('setting-bring-password').value.trim();
    s.default_persons = parseInt(document.getElementById('setting-default-persons').value) || 1;
    s.pref_veloce = document.getElementById('setting-pref-veloce').checked;
    s.pref_pocafame = document.getElementById('setting-pref-pocafame').checked;
    s.pref_scadenze = document.getElementById('setting-pref-scadenze').checked;
    s.pref_healthy = document.getElementById('setting-pref-healthy').checked;
    s.pref_opened = document.getElementById('setting-pref-opened').checked;
    s.pref_zerowaste = document.getElementById('setting-pref-zerowaste').checked;
    s.dietary = document.getElementById('setting-dietary').value.trim();
    // Camera
    s.camera_facing = document.getElementById('setting-camera-facing').value;
    // Meal plan enabled toggle
    const mpEnabledEl = document.getElementById('setting-meal-plan-enabled');
    if (mpEnabledEl) s.meal_plan_enabled = mpEnabledEl.checked;
    // TTS settings
    const ttsEnabledEl = document.getElementById('setting-tts-enabled');
    if (ttsEnabledEl) s.tts_enabled = ttsEnabledEl.checked;
    const ttsUrlEl2 = document.getElementById('setting-tts-url');
    if (ttsUrlEl2) s.tts_url = ttsUrlEl2.value.trim();
    const ttsMethEl2 = document.getElementById('setting-tts-method');
    if (ttsMethEl2) s.tts_method = ttsMethEl2.value;
    const ttsAuthTypeEl2 = document.getElementById('setting-tts-auth-type');
    if (ttsAuthTypeEl2) s.tts_auth_type = ttsAuthTypeEl2.value;
    const ttsTokenEl2 = document.getElementById('setting-tts-token');
    if (ttsTokenEl2) s.tts_token = ttsTokenEl2.value.trim();
    const ttsAuthHdrNameEl2 = document.getElementById('setting-tts-auth-header-name');
    if (ttsAuthHdrNameEl2) s.tts_auth_header_name = ttsAuthHdrNameEl2.value.trim();
    const ttsAuthHdrValEl2 = document.getElementById('setting-tts-auth-header-value');
    if (ttsAuthHdrValEl2) s.tts_auth_header_value = ttsAuthHdrValEl2.value.trim();
    const ttsCtEl2 = document.getElementById('setting-tts-content-type');
    if (ttsCtEl2) s.tts_content_type = ttsCtEl2.value;
    const ttsPayloadKeyEl2 = document.getElementById('setting-tts-payload-key');
    if (ttsPayloadKeyEl2) s.tts_payload_key = ttsPayloadKeyEl2.value.trim() || 'message';
    const ttsExtraEl2 = document.getElementById('setting-tts-extra-fields');
    if (ttsExtraEl2) s.tts_extra_fields = ttsExtraEl2.value.trim();
    // Save spesa AI prompt if the field exists
    const spesaPromptEl = document.getElementById('setting-spesa-ai-prompt');
    if (spesaPromptEl) s.spesa_ai_prompt = spesaPromptEl.value.trim();
    saveSettingsToStorage(s);
    
    // Also save to server .env
    try {
        const result = await api('save_settings', {}, 'POST', {
            gemini_key: s.gemini_key,
            bring_email: s.bring_email,
            bring_password: s.bring_password
        });
        const statusEl = document.getElementById('settings-status');
        if (result.success) {
            statusEl.className = 'settings-status success';
            statusEl.textContent = '✅ Configurazione salvata!';
        } else {
            statusEl.className = 'settings-status error';
            statusEl.textContent = '⚠️ Salvato localmente, errore server: ' + (result.error || '');
        }
        statusEl.style.display = 'block';
        setTimeout(() => statusEl.style.display = 'none', 4000);
    } catch(e) {
        const statusEl = document.getElementById('settings-status');
        statusEl.className = 'settings-status success';
        statusEl.textContent = '✅ Configurazione salvata localmente';
        statusEl.style.display = 'block';
        setTimeout(() => statusEl.style.display = 'none', 4000);
    }
}

function switchSettingsTab(btn, tabId) {
    document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}

function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    input.type = input.type === 'password' ? 'text' : 'password';
}

// ===== API HELPER =====
async function api(action, params = {}, method = 'GET', body = null) {
    let url = `${API_BASE}?action=${action}`;
    if (method === 'GET') {
        Object.entries(params).forEach(([k, v]) => {
            url += `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`;
        });
    }
    const opts = { method };
    if (body) {
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    if (!res.ok) {
        remoteLog('API_ERROR', `${action} HTTP ${res.status}`);
    }
    const data = await res.json();
    if (data && data.error) {
        remoteLog('API_FAIL', `${action}: ${data.error}`);
    }
    return data;
}

// ===== PAGE NAVIGATION =====
// Track current page for auto-refresh
let _currentPageId = 'dashboard';
let _currentPageParam = null;

// Refresh current page data without full navigation
function refreshCurrentPage() {
    switch(_currentPageId) {
        case 'dashboard': loadDashboard(); break;
        case 'inventory': loadInventory(); break;
        case 'shopping': loadShoppingList(); break;
        case 'products': loadAllProducts(); break;
    }
}

function showPage(pageId, param = null) {
    _currentPageId = pageId;
    _currentPageParam = param;
    // Hide all pages
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    // Show target page
    const page = document.getElementById(`page-${pageId}`);
    if (page) page.classList.add('active');

    // Clear search inputs when navigating away
    const invSearch = document.getElementById('inventory-search');
    if (invSearch) invSearch.value = '';
    const prodSearch = document.getElementById('products-search');
    if (prodSearch) prodSearch.value = '';
    
    // Update nav
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    const navBtn = document.querySelector(`.nav-btn[data-page="${pageId}"]`);
    if (navBtn) navBtn.classList.add('active');
    
    // Page-specific init
    switch(pageId) {
        case 'dashboard': loadDashboard(); break;
        case 'inventory':
            if (param !== null) {
                currentLocation = param;
                filterLocation(param);
            }
            loadInventory();
            break;
        case 'scan': initScanner(); clearQuickNameResults(); updateSpesaBanner(); break;
        case 'products': loadAllProducts(); break;
        case 'shopping': loadShoppingList(); break;
        case 'recipe': loadRecipeArchive(); break;
        case 'log': loadLog(); break;
        case 'ai': initAICamera(); break;
        case 'settings': loadSettingsUI(); break;
        case 'chat': initChat(); break;
    }
    
    // Stop scanner when leaving scan page
    if (pageId !== 'scan' && pageId !== 'ai') {
        stopScanner();
    }
    
    // Scroll to top
    window.scrollTo(0, 0);
}

// ===== DASHBOARD =====
async function loadDashboard() {
    try {
        const [summaryData, statsData] = await Promise.all([
            api('inventory_summary'),
            api('stats')
        ]);
        
        // Update stat cards
        const summary = summaryData.summary || [];
        let total = 0;
        ['dispensa', 'frigo', 'freezer'].forEach(loc => {
            const s = summary.find(x => x.location === loc);
            const count = s ? s.product_count : 0;
            document.getElementById(`stat-${loc}`).textContent = count;
            total += count;
        });
        // Add non-standard locations
        summary.forEach(s => {
            if (!['dispensa', 'frigo', 'freezer'].includes(s.location)) {
                total += s.product_count;
            }
        });
        // Load shopping list count from Bring!
        loadShoppingCount();
        
        // Quick recipe button - show when there are expiring products
        const recipeBar = document.getElementById('quick-recipe-bar');
        if (statsData.expiring_soon && statsData.expiring_soon.length > 0) {
            recipeBar.style.display = 'block';
        } else {
            recipeBar.style.display = 'none';
        }
        
        // Expiring items
        const expiringSection = document.getElementById('alert-expiring');
        const expiringList = document.getElementById('expiring-list');
        if (statsData.expiring_soon && statsData.expiring_soon.length > 0) {
            expiringSection.style.display = 'block';
            expiringList.innerHTML = statsData.expiring_soon.map(item => {
                const days = daysUntilExpiry(item.expiry_date);
                let badgeText, badgeClass;
                if (days === 0) { badgeText = 'OGGI'; badgeClass = 'today'; }
                else if (days === 1) { badgeText = 'Domani'; badgeClass = 'expiring'; }
                else if (days <= 7) { badgeText = `${days} giorni`; badgeClass = 'expiring'; }
                else if (days <= 30) { badgeText = `${days}g`; badgeClass = 'expiring-soon'; }
                else { const m = Math.round(days/30); badgeText = m <= 1 ? `${days}g` : `~${m} mesi`; badgeClass = 'expiring-later'; }
                const qtyDisplay = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
                return `
                <div class="alert-item alert-item-clickable" onclick="showAlertItemDetail(${item.id}, ${item.product_id})">
                    <div class="alert-item-info">
                        <span class="alert-item-name">${escapeHtml(item.name)}</span>
                        ${item.brand ? `<span class="alert-item-brand">${escapeHtml(item.brand)}</span>` : ''}
                    </div>
                    <div class="alert-item-badges">
                        <span class="alert-item-qty">📦 ${qtyDisplay}</span>
                        <span class="alert-item-badge ${badgeClass}">${badgeText}</span>
                    </div>
                </div>`;
            }).join('');
        } else {
            expiringSection.style.display = 'none';
        }
        
        // Expired items
        const expiredSection = document.getElementById('alert-expired');
        const expiredList = document.getElementById('expired-list');
        if (statsData.expired && statsData.expired.length > 0) {
            expiredSection.style.display = 'block';
            expiredList.innerHTML = statsData.expired.map(item => {
                const days = Math.abs(daysUntilExpiry(item.expiry_date));
                let daysText;
                if (days === 0) daysText = 'Oggi';
                else if (days === 1) daysText = 'Da ieri';
                else daysText = `Da ${days}g`;
                const safety = getExpiredSafety(item, days);
                const locIcon = item.location === 'freezer' ? '❄️' : item.location === 'frigo' ? '🧊' : '';
                const qtyDisplayExp = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
                return `
                <div class="alert-item expired-item alert-item-clickable" onclick="showAlertItemDetail(${item.id}, ${item.product_id})">
                    <div class="alert-item-info">
                        <span class="alert-item-name">${locIcon ? locIcon + ' ' : ''}${escapeHtml(item.name)}</span>
                        ${item.brand ? `<span class="alert-item-brand">${escapeHtml(item.brand)}</span>` : ''}
                        <span class="alert-item-qty">📦 ${qtyDisplayExp}</span>
                    </div>
                    <div class="alert-item-badges">
                        <span class="alert-item-badge expired">${daysText}</span>
                        <span class="safety-badge safety-${safety.level}" title="${safety.tip}">${safety.icon} ${safety.label}</span>
                    </div>
                </div>`;
            }).join('');
        } else {
            expiredSection.style.display = 'none';
        }
        
        // Review suspicious quantities
        loadReviewItems();

        // Waste vs consumption chart
        const wasteSection = document.getElementById('waste-chart-section');
        const used30 = statsData.used_30d || 0;
        const wasted30 = statsData.wasted_30d || 0;
        const total30 = used30 + wasted30;
        if (total30 > 0) {
            wasteSection.style.display = 'block';
            const usedPct = Math.round((used30 / total30) * 100);
            const wastedPct = 100 - usedPct;
            document.getElementById('waste-chart-bar').innerHTML = `
                <div class="waste-bar-used" style="width:${usedPct}%"></div>
                <div class="waste-bar-wasted" style="width:${wastedPct}%"></div>
            `;
            document.getElementById('waste-chart-legend').innerHTML = `
                <span class="waste-legend-item"><span class="waste-legend-dot used"></span> Consumati: ${used30} (${usedPct}%)</span>
                <span class="waste-legend-item"><span class="waste-legend-dot wasted"></span> Buttati: ${wasted30} (${wastedPct}%)</span>
            `;
        } else {
            wasteSection.style.display = 'none';
        }

        // Opened (partially used products with known package capacity)
        const openedSection = document.getElementById('alert-opened');
        const openedList = document.getElementById('opened-list');
        if (statsData.opened && statsData.opened.length > 0) {
            // Sort by remaining fraction ascending (least remaining first)
            statsData.opened.sort((a, b) => {
                const fA = openedFraction(a), fB = openedFraction(b);
                return fA - fB;
            });
            openedSection.style.display = 'block';
            openedList.innerHTML = statsData.opened.map(item => {
                const locInfo = LOCATIONS[item.location] || { icon: '📦', label: item.location };
                const qty = parseFloat(item.quantity);
                const pkgSize = parseFloat(item.default_quantity);
                const unitLabels = { 'ml': 'ml', 'g': 'g', 'pz': 'pz' };
                let qtyText = '';

                if (item.unit === 'conf') {
                    const pkgUnit = item.package_unit;
                    const pkgLabel = unitLabels[pkgUnit] || pkgUnit;
                    const wholeConf = Math.floor(qty + 0.001);
                    const frac = Math.round((qty - wholeConf) * 1000) / 1000;
                    const remainderAmt = frac * pkgSize;
                    const remainderText = formatSubRemainder(remainderAmt, pkgUnit);
                    if (wholeConf > 0) {
                        qtyText = `${wholeConf} conf (da ${pkgSize}${pkgLabel}) + ${remainderText}`;
                    } else {
                        qtyText = remainderText;
                    }
                } else {
                    const unitLabel = unitLabels[item.unit] || item.unit || '';
                    const wholePackages = Math.floor(qty / pkgSize + 0.001);
                    const remainder = Math.round((qty - wholePackages * pkgSize) * 100) / 100;
                    if (wholePackages > 0 && remainder > 0.01) {
                        qtyText = `${wholePackages} × ${pkgSize}${unitLabel} + ${Math.round(remainder)}${unitLabel} rimasti`;
                    } else if (remainder > 0.01) {
                        qtyText = `${Math.round(remainder)}${unitLabel} / ${pkgSize}${unitLabel}`;
                    } else {
                        qtyText = `${qty}${unitLabel}`;
                    }
                }
                return `
                <div class="alert-item alert-item-clickable" onclick="showAlertItemDetail(${item.id}, ${item.product_id})">
                    <div class="alert-item-info">
                        <span class="alert-item-name">${escapeHtml(item.name)}</span>
                        ${item.brand ? `<span class="alert-item-brand">${escapeHtml(item.brand)}</span>` : ''}
                    </div>
                    <div class="alert-item-badges">
                        <span class="alert-item-qty">${locInfo.icon} ${locInfo.label}</span>
                        <span class="alert-item-badge opened">${qtyText}</span>
                    </div>
                </div>`;
            }).join('');
        } else {
            openedSection.style.display = 'none';
        }
        
    } catch (err) {
        console.error('Dashboard load error:', err);
    }
}

function openedFraction(item) {
    const qty = parseFloat(item.quantity);
    const pkgSize = parseFloat(item.default_quantity);
    if (item.unit === 'conf') {
        return qty - Math.floor(qty + 0.001);
    }
    return (qty - Math.floor(qty / pkgSize + 0.001) * pkgSize) / pkgSize;
}

function quickRecipeSuggestion() {
    // Navigate to chat and auto-send a prompt about expiring products
    showPage('chat');
    setTimeout(() => {
        document.getElementById('chat-input').value = 'Suggeriscimi una ricetta veloce PER UNA PERSONA usando i prodotti che scadono prima! Ignora i prodotti in freezer (hanno scadenze molto lunghe), concentrati su frigo e dispensa.';
        sendChatMessage();
    }, 500);
}

// === SUSPICIOUS QUANTITY REVIEW ===
const QTY_THRESHOLDS = {
    'pz':   { min: 0.3,  max: 50 },
    'conf': { min: 0.3,  max: 50 },
    'g':    { min: 3,    max: 10000 },
    'ml':   { min: 3,    max: 10000 },
};

function isSuspiciousQty(qty, unit) {
    const n = parseFloat(qty);
    if (isNaN(n) || n <= 0) return false;
    const t = QTY_THRESHOLDS[unit] || QTY_THRESHOLDS['pz'];
    return n < t.min || n > t.max;
}

function isSuspiciousDefaultQty(defaultQty, unit, packageUnit) {
    const n = parseFloat(defaultQty);
    if (!n || n <= 0) return false;
    // For conf products, default_quantity is in package_unit (g, ml, etc.)
    const checkUnit = (unit === 'conf' && packageUnit) ? packageUnit : unit;
    const t = QTY_THRESHOLDS[checkUnit] || QTY_THRESHOLDS['pz'];
    return n > t.max;
}

function getReviewConfirmed() {
    return _reviewConfirmedCache || {};
}
let _reviewConfirmedCache = {};

function setReviewConfirmed(inventoryId) {
    const c = getReviewConfirmed();
    c[inventoryId] = Date.now();
    _reviewConfirmedCache = c;
    // Persist to shared DB
    api('app_settings_save', {}, 'POST', { settings: { review_confirmed: c } }).catch(() => {});
}

async function loadReviewItems() {
    const section = document.getElementById('alert-review');
    const list = document.getElementById('review-list');
    try {
        const data = await api('inventory_list');
        const items = data.inventory || [];
        const confirmed = getReviewConfirmed();
        
        const suspicious = items.filter(item => {
            if (confirmed[item.id]) return false;
            return isSuspiciousQty(item.quantity, item.unit) || isSuspiciousDefaultQty(item.default_quantity, item.unit, item.package_unit);
        });
        
        if (suspicious.length === 0) {
            section.style.display = 'none';
            return;
        }
        
        section.style.display = 'block';
        list.innerHTML = suspicious.map(item => {
            const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
            const qtyDisplay = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
            const locInfo = LOCATIONS[item.location] || { icon: '📦', label: item.location };
            const t = QTY_THRESHOLDS[item.unit] || QTY_THRESHOLDS['pz'];
            const suspQty = isSuspiciousQty(item.quantity, item.unit);
            const suspDq = isSuspiciousDefaultQty(item.default_quantity, item.unit, item.package_unit);
            let warning;
            if (suspDq && !suspQty) warning = '📦 Conf. sospetta';
            else if (parseFloat(item.quantity) < t.min) warning = '⬇️ Troppo poco';
            else warning = '⬆️ Troppo';
            
            return `
            <div class="review-item" id="review-item-${item.id}">
                <div class="review-item-info">
                    <span class="review-item-icon">${item.image_url ? `<img src="${escapeHtml(item.image_url)}" alt="">` : catIcon}</span>
                    <div class="review-item-text">
                        <div class="review-item-name">${escapeHtml(item.name)}</div>
                        <div class="review-item-meta">${locInfo.icon} ${locInfo.label} · <span class="review-warn">${warning}</span></div>
                    </div>
                </div>
                <div class="review-item-qty">
                    <span class="review-qty-value">${qtyDisplay}</span>
                </div>
                <div class="review-item-actions">
                    <button class="btn-review btn-review-ok" onclick="confirmReviewItem(${item.id})" title="È corretto">✓</button>
                    <button class="btn-review btn-review-edit" onclick="editReviewItem(${item.id}, ${item.product_id})" title="Modifica">✏️</button>
                </div>
            </div>`;
        }).join('');
    } catch(e) {
        section.style.display = 'none';
    }
}

function confirmReviewItem(inventoryId) {
    setReviewConfirmed(inventoryId);
    const el = document.getElementById(`review-item-${inventoryId}`);
    if (el) {
        el.style.transition = 'opacity 0.3s, transform 0.3s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(60px)';
        setTimeout(() => {
            el.remove();
            // Hide section if empty
            const list = document.getElementById('review-list');
            if (!list.children.length) {
                document.getElementById('alert-review').style.display = 'none';
            }
        }, 300);
    }
    showToast('✓ Quantità confermata', 'success');
}

function editReviewItem(inventoryId, productId) {
    api('inventory_list').then(data => {
        currentInventory = data.inventory || [];
        showItemDetail(inventoryId, productId);
    });
}

// Group items by local category and render with category headers
function renderGroupedByCategory(items, compact = false) {
    const catGroups = {};
    items.forEach(item => {
        const localCat = mapToLocalCategory(item.category, item.name);
        if (!catGroups[localCat]) catGroups[localCat] = [];
        catGroups[localCat].push(item);
    });
    
    // Sort categories: use CATEGORY_ICONS key order
    const catOrder = Object.keys(CATEGORY_ICONS);
    const sortedCats = Object.keys(catGroups).sort((a, b) => {
        const ia = catOrder.indexOf(a);
        const ib = catOrder.indexOf(b);
        return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
    });
    
    let html = '';
    for (const cat of sortedCats) {
        const catItems = catGroups[cat];
        const label = CATEGORY_LABELS[cat] || '📦 Altro';
        html += `<div class="cat-group-header">${label} <span class="cat-group-count">${catItems.length}</span></div>`;
        html += catItems.map(item => compact ? renderDashItem(item) : renderInventoryItem(item)).join('');
    }
    return html;
}

function renderDashItem(item) {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
    const days = daysUntilExpiry(item.expiry_date);
    const isExpired = days < 0;
    const isExpiring = !isExpired && days <= 7;
    const parts = formatQuantityParts(item.quantity, item.unit, item.default_quantity, item.package_unit);
    
    let expiryLabel = '';
    if (item.expiry_date) {
        if (days < 0) expiryLabel = `⚠️ Scaduto da ${Math.abs(days)}g`;
        else if (days === 0) expiryLabel = '⚠️ Scade oggi!';
        else if (days === 1) expiryLabel = '⏰ Scade domani';
        else if (days <= 7) expiryLabel = `⏰ ${days} giorni`;
        else expiryLabel = formatDate(item.expiry_date);
    }
    
    return `
    <div class="inventory-item compact-item" onclick="dashItemTap(${item.id}, ${item.product_id})">
        <div class="inv-image">
            ${item.image_url ? `<img src="${escapeHtml(item.image_url)}" alt="" onerror="this.parentElement.innerHTML='${catIcon}'">` : catIcon}
        </div>
        <div class="inv-info">
            <div class="inv-name">${escapeHtml(item.name)}</div>
            ${item.brand ? `<div class="inv-brand">${escapeHtml(item.brand)}</div>` : ''}
        </div>
        <div class="inv-qty-right">
            <span class="inv-qty-value">${parts.mainQty} <small>${parts.unitLabel}</small></span>
            ${parts.packageDetail ? `<span class="inv-qty-pkg-detail">${parts.packageDetail}</span>` : ''}
            ${expiryLabel ? `<span class="inv-expiry-small ${isExpired ? 'expired' : isExpiring ? 'expiring' : ''}">${expiryLabel}</span>` : ''}
        </div>
    </div>`;
}

function dashItemTap(inventoryId, productId) {
    // Load full inventory so modal works
    api('inventory_list').then(data => {
        currentInventory = data.inventory || [];
        showItemDetail(inventoryId, productId);
    });
}

function showAlertItemDetail(inventoryId, productId) {
    // Load full inventory so modal works (same pattern as dashItemTap)
    api('inventory_list').then(data => {
        currentInventory = data.inventory || [];
        showItemDetail(inventoryId, productId);
    });
}

function formatSubRemainder(amt, pkgUnit) {
    const uL = { 'g': 'g', 'ml': 'ml' };
    if (pkgUnit === 'ml' || pkgUnit === 'g') return `${Math.round(amt)}${uL[pkgUnit] || pkgUnit}`;
    return `${Math.round(amt * 10) / 10}${uL[pkgUnit] || pkgUnit}`;
}

function formatQuantity(qty, unit, defaultQty, packageUnit) {
    if (!qty && qty !== 0) return '';
    const n = parseFloat(qty);
    const unitLabels = { 'pz': 'pz', 'g': 'g', 'ml': 'ml', 'conf': 'conf' };
    const label = unitLabels[unit] || unit || 'pz';

    // Special handling for conf with partial packages
    if (unit === 'conf' && packageUnit && defaultQty > 0) {
        const pkgLabel = unitLabels[packageUnit] || packageUnit;
        const wholeConf = Math.floor(n + 0.001);
        const fractionalConf = Math.round((n - wholeConf) * 1000) / 1000;

        if (fractionalConf < 0.01) {
            return `${wholeConf} conf <span class="conf-size-info">(da ${defaultQty}${pkgLabel})</span>`;
        }
        const remainderText = formatSubRemainder(fractionalConf * defaultQty, packageUnit);
        if (wholeConf > 0) {
            return `${wholeConf} conf <span class="conf-size-info">(da ${defaultQty}${pkgLabel})</span> + ${remainderText}`;
        }
        return remainderText;
    }

    let result;
    if (n === Math.floor(n)) result = `${Math.floor(n)} ${label}`;
    else if (unit === 'pz') result = `${Math.round(n)} ${label}`;
    else result = `${n.toFixed(1)} ${label}`;
    return result;
}

// Structured quantity display for inventory cards.
// Returns { mainQty: '10', unitLabel: 'conf', packageDetail: 'da 36g', fraction: '¼' }
function formatQuantityParts(qty, unit, defaultQty, packageUnit) {
    const n = parseFloat(qty) || 0;
    const unitLabels = { 'pz': 'pz', 'g': 'g', 'ml': 'ml', 'conf': 'conf' };
    const label = unitLabels[unit] || unit || 'pz';

    // Special handling for conf with partial packages
    if (unit === 'conf' && packageUnit && defaultQty > 0) {
        const pkgLabel = unitLabels[packageUnit] || packageUnit;
        const wholeConf = Math.floor(n + 0.001);
        const fractionalConf = Math.round((n - wholeConf) * 1000) / 1000;

        if (fractionalConf < 0.01) {
            return { mainQty: `${wholeConf}`, unitLabel: 'conf', packageDetail: `da ${defaultQty}${pkgLabel}`, fraction: '' };
        }
        const remainderText = formatSubRemainder(fractionalConf * defaultQty, packageUnit);
        if (wholeConf > 0) {
            return { mainQty: `${wholeConf}`, unitLabel: 'conf', packageDetail: `da ${defaultQty}${pkgLabel}`, fraction: `+ ${remainderText}` };
        }
        return { mainQty: remainderText, unitLabel: '', packageDetail: '', fraction: '' };
    }

    let mainQty;
    if (n === Math.floor(n)) mainQty = `${Math.floor(n)}`;
    else if (unit === 'pz') mainQty = `${Math.round(n)}`;
    else mainQty = `${n.toFixed(1)}`;
    
    let packageDetail = '';
    let fraction = '';
    if (unit !== 'conf' && defaultQty && defaultQty > 1) {
        const d = parseFloat(defaultQty);
        const ratio = n / d;
        const remainder = ratio - Math.floor(ratio);
        if (remainder >= 0.1 && remainder <= 0.9) {
            if (remainder < 0.38) fraction = '¼';
            else if (remainder < 0.62) fraction = '½';
            else fraction = '¾';
        }
    }
    
    return { mainQty, unitLabel: label, packageDetail, fraction };
}

// Show package fraction: only ¼, ½, ¾ when there's a partial package.
// Returns '' if quantity maps to whole packages or fraction is not meaningful.
function formatPackageFraction(qty, defaultQty) {
    if (!defaultQty || defaultQty <= 0) return '';
    const n = parseFloat(qty);
    const d = parseFloat(defaultQty);
    if (isNaN(n) || isNaN(d) || d <= 0 || d === 1) return '';
    
    const ratio = n / d;
    const remainder = ratio - Math.floor(ratio);
    
    // Only show if there IS a fractional part
    if (remainder < 0.1 || remainder > 0.9) return '';
    
    let frac = '';
    if (remainder < 0.38) frac = '¼';
    else if (remainder < 0.62) frac = '½';
    else frac = '¾';
    
    return `<span class="pkg-fraction">${frac}</span>`;
}

// ===== INVENTORY =====
async function loadInventory() {
    try {
        const data = await api('inventory_list', currentLocation ? { location: currentLocation } : {});
        currentInventory = data.inventory || [];
        renderInventory(currentInventory);
    } catch (err) {
        console.error('Inventory load error:', err);
    }
}

function renderInventoryItem(item) {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
    const locInfo = LOCATIONS[item.location] || { icon: '📦', label: item.location };
    const days = daysUntilExpiry(item.expiry_date);
    const isExpired = days < 0;
    const isExpiring = !isExpired && days <= 7;
    const parts = formatQuantityParts(item.quantity, item.unit, item.default_quantity, item.package_unit);
    
    let expiryBadge = '';
    if (item.expiry_date) {
        let expiryText;
        if (isExpired) expiryText = `⚠️ Scaduto da ${Math.abs(days)}g`;
        else if (days === 0) expiryText = '⚠️ Scade oggi!';
        else if (days === 1) expiryText = '⏰ Domani';
        else if (days <= 7) expiryText = `⏰ ${days} giorni`;
        else expiryText = formatDate(item.expiry_date);
        expiryBadge = `<span class="inv-badge ${isExpired ? 'badge-expired' : isExpiring ? 'badge-expiry' : ''}">${expiryText}</span>`;
    }
    
    const vacuumBadge = item.vacuum_sealed ? '<span class="vacuum-badge">🫙 Sotto vuoto</span>' : '';
    const openedBadge = item.opened_at ? '<span class="opened-badge">📭 Aperto</span>' : '';
    
    return `
    <div class="inventory-item" onclick="showItemDetail(${item.id}, ${item.product_id})">
        <div class="inv-image">
            ${item.image_url ? `<img src="${escapeHtml(item.image_url)}" alt="" onerror="this.parentElement.innerHTML='${catIcon}'">` : catIcon}
        </div>
        <div class="inv-info">
            <div class="inv-name">${escapeHtml(item.name)}</div>
            ${item.brand ? `<div class="inv-brand">${escapeHtml(item.brand)}</div>` : ''}
            <div class="inv-meta">
                <span class="inv-badge badge-location">${locInfo.icon} ${locInfo.label}</span>
                ${expiryBadge}
                ${openedBadge}
                ${vacuumBadge}
            </div>
        </div>
        <div class="inv-qty-col">
            <span class="inv-qty-number">${parts.mainQty}</span>
            <span class="inv-qty-unit">${parts.unitLabel}${parts.packageDetail ? ` <span class="inv-qty-pkg">${parts.packageDetail}</span>` : ''}</span>
            ${parts.fraction ? `<span class="inv-qty-frac">${parts.fraction}</span>` : ''}
        </div>
    </div>`;
}

function renderInventory(items) {
    const container = document.getElementById('inventory-list');
    if (items.length === 0) {
        container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📭</div><p>Nessun prodotto qui.<br>Scansiona un prodotto per aggiungerlo!</p></div>';
        return;
    }
    container.innerHTML = renderGroupedByCategory(items, false);
}

function filterLocation(loc) {
    currentLocation = loc;
    document.querySelectorAll('.location-tabs .tab').forEach(t => {
        t.classList.toggle('active', t.dataset.loc === loc);
    });
    loadInventory();
}

function filterInventory() {
    const q = document.getElementById('inventory-search').value.toLowerCase();
    if (!q) {
        renderInventory(currentInventory);
        return;
    }
    const filtered = currentInventory.filter(i =>
        i.name.toLowerCase().includes(q) ||
        (i.brand && i.brand.toLowerCase().includes(q)) ||
        (i.barcode && i.barcode.includes(q))
    );
    renderInventory(filtered);
}

// ===== ITEM DETAIL MODAL =====
function showItemDetail(inventoryId, productId) {
    const item = currentInventory.find(i => i.id === inventoryId);
    if (!item) return;
    
    const locInfo = LOCATIONS[item.location] || { icon: '📦', label: item.location };
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
    
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${escapeHtml(item.name)}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="product-preview-small" style="margin-bottom:12px">
            ${item.image_url ?
                `<img src="${escapeHtml(item.image_url)}" alt="" style="width:60px;height:60px;border-radius:10px;object-fit:cover">` :
                `<span style="font-size:2.5rem">${catIcon}</span>`
            }
            <div class="product-preview-info">
                <h3>${escapeHtml(item.name)}</h3>
                <p>${item.brand ? escapeHtml(item.brand) : ''}</p>
            </div>
        </div>
        <div class="modal-detail">
            <div class="modal-detail-row">
                <span class="modal-detail-label">📍 Posizione</span>
                <span class="modal-detail-value">${locInfo.icon} ${locInfo.label}</span>
            </div>
            <div class="modal-detail-row">
                <span class="modal-detail-label">📦 Quantità</span>
                <span class="modal-detail-value">${formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit)}</span>
            </div>
            ${item.expiry_date ? `
            <div class="modal-detail-row">
                <span class="modal-detail-label">📅 Scadenza</span>
                <span class="modal-detail-value">${formatDate(item.expiry_date)}</span>
            </div>` : ''}
            ${item.vacuum_sealed ? `
            <div class="modal-detail-row">
                <span class="modal-detail-label">🫙 Conservazione</span>
                <span class="modal-detail-value">Sotto vuoto</span>
            </div>` : ''}
            ${item.opened_at ? `
            <div class="modal-detail-row">
                <span class="modal-detail-label">📭 Stato</span>
                <span class="modal-detail-value">Aperto dal ${formatDateTime(item.opened_at)}</span>
            </div>` : ''}
            ${item.barcode ? `
            <div class="modal-detail-row">
                <span class="modal-detail-label">🔖 Barcode</span>
                <span class="modal-detail-value">${item.barcode}</span>
            </div>` : ''}
            <div class="modal-detail-row">
                <span class="modal-detail-label">📅 Aggiunto</span>
                <span class="modal-detail-value">${formatDateTime(item.added_at)}</span>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-danger flex-1" onclick="quickUse(${item.product_id}, '${item.location}')">📤 Usa</button>
            <button class="btn btn-primary flex-1" onclick="editInventoryItem(${inventoryId})">✏️ Modifica</button>
            <button class="btn btn-secondary" onclick="deleteInventoryItem(${inventoryId})" style="padding:12px">🗑️</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

function closeModal() {
    document.getElementById('modal-overlay').style.display = 'none';
}

async function quickUse(productId, location) {
    closeModal();
    showLoading(true);
    try {
        currentProduct = { id: productId };
        // Get product info
        const data = await api('product_get', { id: productId });
        if (data.product) {
            currentProduct = data.product;
            // Extract weight_info from notes if available
            if (!currentProduct.weight_info && currentProduct.notes) {
                const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
                if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
            }
        }
        document.getElementById('use-location').value = location;
        // Mark active location button
        document.querySelectorAll('#page-use .loc-btn').forEach(b => b.classList.remove('active'));
        const locBtns = document.querySelectorAll('#page-use .loc-btn');
        locBtns.forEach(b => {
            if (b.textContent.toLowerCase().includes(location)) b.classList.add('active');
        });
        
        renderUsePreview();
        loadUseInventoryInfo();
        showLoading(false);
        showPage('use');
    } catch (err) {
        showLoading(false);
        console.error('quickUse error:', err);
        showToast('Errore nel caricamento del prodotto', 'error');
    }
}

async function deleteInventoryItem(id) {
    if (confirm('Vuoi davvero rimuovere questo prodotto dall\'inventario?')) {
        await api('inventory_delete', {}, 'POST', { id });
        closeModal();
        showToast('Prodotto rimosso', 'success');
        refreshCurrentPage();
    }
}

function recalcEditExpiry(locInputId, vacuumInputId, expiryInputId) {
    const product = window._editingProduct;
    if (!product) return;
    const loc = document.getElementById(locInputId)?.value || '';
    const isVacuum = document.getElementById(vacuumInputId)?.checked;
    // Use opened shelf life if item is already opened
    let days = product._isOpened
        ? estimateOpenedExpiryDays(product, loc)
        : estimateExpiryDays(product, loc);
    if (isVacuum) days = getVacuumExpiryDays(days);
    const newDate = addDays(days);
    const expiryInput = document.getElementById(expiryInputId);
    if (expiryInput) expiryInput.value = newDate;
}

function editInventoryItem(id) {
    const item = currentInventory.find(i => i.id === id);
    if (!item) {
        closeModal();
        showToast('Prodotto non trovato', 'error');
        return;
    }
    
    const isConf = (item.unit || 'pz') === 'conf';
    const confSizeVal = (isConf && item.default_quantity > 0) ? item.default_quantity : '';
    const confUnitVal = (isConf && item.package_unit) ? item.package_unit : 'g';
    
    window._editingProduct = { name: item.name, category: item.category || '', _isOpened: !!item.opened_at };
    
    // Rebuild modal content for editing (don't close and reopen - just replace content)
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>Modifica ${escapeHtml(item.name)}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form class="form" onsubmit="submitEditInventory(event, ${id}, ${item.product_id})">
            <div class="form-group">
                <label>📦 Quantità</label>
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="adjustQty('edit-qty', -1)">−</button>
                    <input type="number" id="edit-qty" value="${item.quantity}" min="0" step="any" class="qty-input">
                    <button type="button" class="qty-btn" onclick="adjustQty('edit-qty', 1)">+</button>
                </div>
            </div>
            <div class="form-group">
                <label>📏 Unità di misura</label>
                <select id="edit-unit" class="form-input" onchange="onEditUnitChange()">
                    ${['pz','g','ml','conf'].map(u => `<option value="${u}" ${(item.unit||'pz') === u ? 'selected' : ''}>${u === 'pz' ? 'pz (pezzi)' : u === 'g' ? 'g (grammi)' : u === 'ml' ? 'ml (millilitri)' : u === 'conf' ? 'conf (confezioni)' : u}</option>`).join('')}
                </select>
            </div>
            <div class="form-group" id="edit-conf-size-group" style="display:${isConf ? 'block' : 'none'}">
                <label>📦 Ogni confezione contiene:</label>
                <div class="conf-size-inputs">
                    <input type="number" id="edit-conf-size" class="form-input conf-size-input" min="1" step="any" value="${confSizeVal}" placeholder="es. 300">
                    <select id="edit-conf-unit" class="form-input conf-size-unit">
                        ${['g','ml'].map(u => `<option value="${u}" ${confUnitVal === u ? 'selected' : ''}>${u}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>📍 Posizione</label>
                <div class="location-selector">
                    ${Object.entries(LOCATIONS).map(([k, v]) => `
                        <button type="button" class="loc-btn ${item.location === k ? 'active' : ''}" 
                            onclick="this.parentElement.querySelectorAll('.loc-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');document.getElementById('edit-loc').value='${k}';recalcEditExpiry('edit-loc','edit-vacuum','edit-expiry')">${v.icon} ${v.label}</button>
                    `).join('')}
                </div>
                <input type="hidden" id="edit-loc" value="${item.location}">
            </div>
            <div class="form-group">
                <label>📅 Scadenza</label>
                <input type="date" id="edit-expiry" value="${item.expiry_date || ''}" class="form-input">
            </div>
            <div class="form-group">
                <label class="toggle-row">
                    <span>🫙 Sotto vuoto</span>
                    <span class="toggle-switch">
                        <input type="checkbox" id="edit-vacuum" ${item.vacuum_sealed ? 'checked' : ''} onchange="recalcEditExpiry('edit-loc','edit-vacuum','edit-expiry')">
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>
            <button type="submit" class="btn btn-large btn-primary full-width">💾 Salva</button>
        </form>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

function onEditUnitChange() {
    const unit = document.getElementById('edit-unit').value;
    const confGroup = document.getElementById('edit-conf-size-group');
    if (confGroup) confGroup.style.display = unit === 'conf' ? 'block' : 'none';
}

async function submitEditInventory(e, id, productId) {
    e.preventDefault();
    const qty = parseFloat(document.getElementById('edit-qty').value);
    const loc = document.getElementById('edit-loc').value;
    const expiry = document.getElementById('edit-expiry').value || null;
    const unit = document.getElementById('edit-unit').value;
    
    const payload = { id, quantity: qty, location: loc, expiry_date: expiry, unit, product_id: productId,
        vacuum_sealed: document.getElementById('edit-vacuum')?.checked ? 1 : 0 };
    
    // Add package info if conf
    if (unit === 'conf') {
        payload.package_unit = document.getElementById('edit-conf-unit')?.value || '';
        payload.package_size = parseFloat(document.getElementById('edit-conf-size')?.value) || 0;
    } else {
        // Clear package info if not conf
        payload.package_unit = '';
        payload.package_size = 0;
    }
    
    await api('inventory_update', {}, 'POST', payload);
    closeModal();
    showToast('Aggiornato!', 'success');
    refreshCurrentPage();
}

// ===== SCAN DEBUG LOG =====
let _scanDebugVisible = false;
let _scanLogBuffer = [];
let _scanLogTimer = null;

function scanLog(msg) {
    const el = document.getElementById('scan-debug-log');
    if (el) {
        const ts = new Date().toLocaleTimeString('it-IT', {hour:'2-digit',minute:'2-digit',second:'2-digit',fractionalSecondDigits:1});
        el.textContent += `[${ts}] ${msg}\n`;
        el.scrollTop = el.scrollHeight;
    }
    console.log('[ScanDebug]', msg);
    // Buffer for remote send
    _scanLogBuffer.push(msg);
    if (!_scanLogTimer) {
        _scanLogTimer = setTimeout(flushScanLog, 2000);
    }
}

function flushScanLog() {
    _scanLogTimer = null;
    if (_scanLogBuffer.length === 0) return;
    const msgs = _scanLogBuffer.splice(0).map(m => `[SCAN] ${m}`);
    _remoteLogBuffer.push(...msgs);
    if (!_remoteLogTimer) {
        _remoteLogTimer = setTimeout(flushRemoteLog, 2000);
    }
}

function toggleScanDebug() {
    const el = document.getElementById('scan-debug-log');
    if (!el) return;
    _scanDebugVisible = !_scanDebugVisible;
    el.style.display = _scanDebugVisible ? 'block' : 'none';
}

// ===== BARCODE SCANNER =====
let _useBarcodeDetector = ('BarcodeDetector' in window);

async function initScanner() {
    const video = document.getElementById('scanner-video');
    const viewport = document.getElementById('scanner-viewport');
    const logEl = document.getElementById('scan-debug-log');
    if (logEl) logEl.textContent = '';
    
    const constraints = getCameraConstraints();
    scanLog(`Camera mode: ${getSettings().camera_facing || 'environment'}`);
    scanLog(`BarcodeDetector: ${_useBarcodeDetector ? 'YES (native)' : 'NO (Quagga fallback)'}`);
    scanLog(`Constraints: ${JSON.stringify(constraints.video)}`);
    
    try {
        stopScanner();
        
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        const track = stream.getVideoTracks()[0];
        const caps = track.getSettings ? track.getSettings() : {};
        scanLog(`Stream OK — track: ${track.label}`);
        scanLog(`Resolution: ${caps.width||'?'}x${caps.height||'?'}, facing: ${caps.facingMode||'N/A'}`);
        
        scannerStream = stream;
        video.srcObject = stream;
        await video.play();
        scanLog(`Video playing — videoWidth: ${video.videoWidth}, videoHeight: ${video.videoHeight}`);
        
        if (_useBarcodeDetector) {
            startNativeScanner(video);
        } else {
            startQuaggaScanner(video);
        }
        
    } catch (err) {
        scanLog(`CAMERA ERROR: ${err.name}: ${err.message}`);
        console.error('Camera error:', err);
        document.getElementById('scan-result').style.display = 'block';
        document.getElementById('scan-result').innerHTML = `
            <p style="color: var(--danger)">⚠️ Impossibile accedere alla fotocamera.</p>
            <p style="font-size:0.85rem; color: var(--text-light); margin-top:8px">
                Assicurati di usare HTTPS e di aver concesso i permessi della fotocamera.<br>
                Puoi inserire il barcode manualmente o usare l'identificazione AI.
            </p>
        `;
    }
}

// ===== NATIVE BarcodeDetector SCANNER =====
async function startNativeScanner(videoEl) {
    if (quaggaRunning) return;
    
    const scannerLine = document.querySelector('.scanner-line');
    const detector = new BarcodeDetector({
        formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e']
    });
    
    let scanning = true;
    quaggaRunning = true;
    let frameCount = 0;
    let partialCount = 0;
    let lastDetected = '';
    let detectCount = 0;
    let detectionHistory = {};
    
    scanLog('Native BarcodeDetector started');
    
    function updateFeedback(state) {
        if (!scannerLine) return;
        scannerLine.classList.remove('scanning', 'detecting');
        if (state) scannerLine.classList.add(state);
    }
    
    async function scanFrame() {
        if (!scanning || !scannerStream) return;
        frameCount++;
        
        if (frameCount === 1) updateFeedback('scanning');
        
        try {
            const barcodes = await detector.detect(videoEl);
            
            if (barcodes.length > 0) {
                const code = barcodes[0].rawValue;
                const format = barcodes[0].format;
                partialCount++;
                scanLog(`Native detect #${partialCount} [f${frameCount}]: ${code} (${format})`);
                updateFeedback('detecting');
                
                if (!detectionHistory[code]) detectionHistory[code] = { count: 0 };
                detectionHistory[code].count++;
                
                if (code === lastDetected) {
                    detectCount++;
                } else {
                    lastDetected = code;
                    detectCount = 1;
                }
                
                if (detectCount >= 2 || detectionHistory[code].count >= 2) {
                    scanning = false;
                    quaggaRunning = false;
                    updateFeedback(null);
                    scanLog(`CONFIRMED: ${code} after ${frameCount} frames`);
                    onBarcodeDetected(code);
                    return;
                }
            } else {
                updateFeedback('scanning');
            }
        } catch (e) {
            scanLog(`Native detect error: ${e.message}`);
        }
        
        if (scanning) {
            if (frameCount % 30 === 0) {
                scanLog(`Native scanning... f${frameCount}, partials: ${partialCount}`);
            }
            requestAnimationFrame(scanFrame);
        }
    }
    
    requestAnimationFrame(scanFrame);
}

// ===== QUAGGA FALLBACK SCANNER =====
function startQuaggaScanner(videoEl) {
    if (quaggaRunning) return;
    
    const canvas = document.getElementById('scanner-canvas');
    const ctx = canvas.getContext('2d');
    const frontCam = isFrontCamera();
    const scannerLine = document.querySelector('.scanner-line');
    let frameCount = 0;
    let partialCount = 0;
    
    scanLog(`Quagga starting — frontCam: ${frontCam}`);
    
    let scanning = true;
    quaggaRunning = true;
    let lastDetected = '';
    let detectCount = 0;
    let detectionHistory = {};
    
    // Alternate between full frame and center-cropped for better detection
    let scanPass = 0; // 0=full, 1=center-crop, 2=full-enhanced, 3=center-enhanced
    
    function updateScannerFeedback(state) {
        if (!scannerLine) return;
        scannerLine.classList.remove('scanning', 'detecting');
        if (state) scannerLine.classList.add(state);
    }
    
    function getFrameDataUrl(pass) {
        const vw = videoEl.videoWidth;
        const vh = videoEl.videoHeight;
        
        if (pass % 2 === 0) {
            // Full frame
            canvas.width = vw;
            canvas.height = vh;
            ctx.drawImage(videoEl, 0, 0);
        } else {
            // Center crop: 60% of frame, focused on barcode area
            const cropW = Math.round(vw * 0.7);
            const cropH = Math.round(vh * 0.4);
            const sx = Math.round((vw - cropW) / 2);
            const sy = Math.round((vh - cropH) / 2);
            canvas.width = cropW;
            canvas.height = cropH;
            ctx.drawImage(videoEl, sx, sy, cropW, cropH, 0, 0, cropW, cropH);
        }
        
        // Apply enhancement on passes 2,3 or always for front cam
        if (frontCam || pass >= 2) {
            enhanceCanvasForBarcode(ctx, canvas.width, canvas.height);
        }
        
        return canvas.toDataURL('image/jpeg', 0.95);
    }
    
    function scanFrame() {
        if (!scanning || !scannerStream) return;
        frameCount++;
        scanPass = (scanPass + 1) % 4;
        
        const dataUrl = getFrameDataUrl(scanPass);
        
        if (frameCount === 1) {
            scanLog(`Frame #1 — video: ${videoEl.videoWidth}x${videoEl.videoHeight}`);
            updateScannerFeedback('scanning');
        }
        
        let callbackCalled = false;
        const safetyTimer = setTimeout(() => {
            if (!callbackCalled && scanning) {
                scanLog(`Quagga timeout on f${frameCount}, retrying...`);
                setTimeout(scanFrame, 100);
            }
        }, 5000);
        
        try {
            const imgSize = Math.max(canvas.width, canvas.height);
            Quagga.decodeSingle({
                src: dataUrl,
                numOfWorkers: 0,
                inputStream: { size: Math.min(imgSize, 800) },
                decoder: {
                    readers: [
                        'ean_reader',
                        'ean_8_reader',
                        'code_128_reader',
                        'code_39_reader',
                        'upc_reader',
                        'upc_e_reader'
                    ],
                    multiple: false
                },
                locate: true,
                locator: { patchSize: 'large', halfSample: false }
            }, function(result) {
                callbackCalled = true;
                clearTimeout(safetyTimer);
                if (result && result.codeResult) {
                    const code = result.codeResult.code;
                    const format = result.codeResult.format;
                    partialCount++;
                    const passName = ['full','crop','full+enh','crop+enh'][scanPass];
                    scanLog(`Partial #${partialCount} [f${frameCount} ${passName}]: ${code} (${format})`);
                    updateScannerFeedback('detecting');
                    
                    if (!detectionHistory[code]) detectionHistory[code] = { count: 0, lastFrame: 0 };
                    detectionHistory[code].count++;
                    detectionHistory[code].lastFrame = frameCount;
                    
                    if (code === lastDetected) {
                        detectCount++;
                    } else {
                        lastDetected = code;
                        detectCount = 1;
                    }
                    
                    const dominated = detectionHistory[code];
                    if (detectCount >= 2 || dominated.count >= 2) {
                        scanning = false;
                        quaggaRunning = false;
                        updateScannerFeedback(null);
                        scanLog(`CONFIRMED: ${code} after ${frameCount} frames (consec:${detectCount}, total:${dominated.count})`);
                        onBarcodeDetected(code);
                        return;
                    }
                } else {
                    updateScannerFeedback('scanning');
                }
                if (scanning) {
                    if (frameCount % 20 === 0) {
                        scanLog(`Scanning... f${frameCount}, partials: ${partialCount}, pass: ${scanPass}`);
                    }
                    setTimeout(scanFrame, 150);
                }
            });
        } catch (e) {
            callbackCalled = true;
            clearTimeout(safetyTimer);
            scanLog(`Quagga error: ${e.message}`);
            if (scanning) setTimeout(scanFrame, 500);
        }
    }
    
    setTimeout(scanFrame, 500);
}

// Enhance low-quality camera frames for better barcode recognition
function enhanceCanvasForBarcode(ctx, w, h) {
    const imageData = ctx.getImageData(0, 0, w, h);
    const d = imageData.data;
    // Convert to high-contrast grayscale
    for (let i = 0; i < d.length; i += 4) {
        // Luminance
        let gray = 0.299 * d[i] + 0.587 * d[i+1] + 0.114 * d[i+2];
        // Increase contrast
        gray = ((gray - 128) * 1.5) + 128;
        gray = gray < 0 ? 0 : gray > 255 ? 255 : gray;
        // Threshold to make bars more distinct
        gray = gray < 140 ? 0 : 255;
        d[i] = d[i+1] = d[i+2] = gray;
    }
    ctx.putImageData(imageData, 0, 0);
}

function stopScanner() {
    quaggaRunning = false;
    _scanZoomLevel = 1;
    if (scannerStream) {
        scannerStream.getTracks().forEach(t => t.stop());
        scannerStream = null;
    }
    const video = document.getElementById('scanner-video');
    if (video) video.srcObject = null;
    const zoomBtn = document.getElementById('scan-zoom-btn');
    if (zoomBtn) zoomBtn.textContent = 'x1';
    
    // Also stop AI camera
    if (aiStream) {
        aiStream.getTracks().forEach(t => t.stop());
        aiStream = null;
    }
    const aiVideo = document.getElementById('ai-video');
    if (aiVideo) aiVideo.srcObject = null;
}

async function onBarcodeDetected(barcode) {
    showLoading(true);
    
    // Vibrate if available
    if (navigator.vibrate) navigator.vibrate(100);
    
    try {
        // First check local DB
        const localResult = await api('search_barcode', { barcode });
        if (localResult.found) {
            currentProduct = localResult.product;
            // If product was saved with 'pz' but has weight info in notes, fix defaults
            if (currentProduct.unit === 'pz' && currentProduct.default_quantity <= 1 && currentProduct.notes) {
                const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
                if (pesoMatch) {
                    const weightStr = pesoMatch[1].trim();
                    const detected = detectUnitAndQuantity(weightStr);
                    if (detected.unit !== 'pz') {
                        currentProduct.unit = detected.unit;
                        currentProduct.default_quantity = detected.quantity;
                        currentProduct.weight_info = weightStr;
                        if (detected.packageUnit) currentProduct.package_unit = detected.packageUnit;
                        if (detected.confCount) currentProduct._confCount = detected.confCount;
                        // Update product in DB for future scans
                        api('product_save', {}, 'POST', {
                            id: currentProduct.id,
                            barcode: currentProduct.barcode,
                            name: currentProduct.name,
                            brand: currentProduct.brand || '',
                            category: currentProduct.category || '',
                            image_url: currentProduct.image_url || '',
                            unit: detected.unit,
                            default_quantity: detected.quantity,
                            package_unit: detected.packageUnit || '',
                            notes: currentProduct.notes,
                        });
                    }
                }
            }
            // Extract weight_info from notes if available (stored as "Peso: 500 g · ...")
            if (!currentProduct.weight_info && currentProduct.notes) {
                const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
                if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
            }
            // Detect confCount from weight_info for multipack pre-fill
            if (currentProduct.weight_info && currentProduct.unit === 'conf' && !currentProduct._confCount) {
                const detected = detectUnitAndQuantity(currentProduct.weight_info);
                if (detected.confCount) currentProduct._confCount = detected.confCount;
            }
            showLoading(false);
            stopScanner();
            showProductAction();
            return;
        }
        
        // Lookup in external DB
        const lookupResult = await api('lookup_barcode', { barcode });
        if (lookupResult.found && lookupResult.product) {
            const p = lookupResult.product;
            // Detect unit and quantity from quantity_info
            const detected = detectUnitAndQuantity(p.quantity_info);
            
            // Build rich notes with all available info
            const notesParts = [];
            if (p.quantity_info) notesParts.push(`Peso: ${p.quantity_info}`);
            if (p.nutriscore) notesParts.push(`Nutriscore: ${p.nutriscore.toUpperCase()}`);
            if (p.nova_group) notesParts.push(`NOVA: ${p.nova_group}`);
            if (p.ecoscore) notesParts.push(`Ecoscore: ${p.ecoscore.toUpperCase()}`);
            if (p.origin) notesParts.push(`Origine: ${p.origin}`);
            if (p.labels) notesParts.push(`Etichette: ${p.labels}`);
            
            // Save to local DB
            const saveResult = await api('product_save', {}, 'POST', {
                barcode: barcode,
                name: p.name || 'Prodotto sconosciuto',
                brand: p.brand || '',
                category: p.category || '',
                image_url: p.image_url || '',
                unit: detected.unit,
                default_quantity: detected.quantity,
                package_unit: detected.packageUnit || '',
                notes: notesParts.join(' · '),
            });
            
            if (saveResult.id) {
                currentProduct = {
                    id: saveResult.id,
                    barcode: barcode,
                    name: p.name || 'Prodotto sconosciuto',
                    brand: p.brand || '',
                    category: p.category || '',
                    image_url: p.image_url || '',
                    unit: detected.unit,
                    default_quantity: detected.quantity,
                    package_unit: detected.packageUnit || '',
                    _confCount: detected.confCount || 0,
                    weight_info: p.quantity_info || '',
                    nutriscore: p.nutriscore || '',
                    ingredients: p.ingredients || '',
                    allergens: p.allergens || '',
                    conservation: p.conservation || '',
                    origin: p.origin || '',
                    nova_group: p.nova_group || '',
                    ecoscore: p.ecoscore || '',
                    labels: p.labels || '',
                    stores: p.stores || '',
                };
                showLoading(false);
                stopScanner();
                showProductAction();
                return;
            }
        }
        
        // Not found - ask user to add manually
        showLoading(false);
        stopScanner();
        showToast('Prodotto non trovato. Inseriscilo manualmente.', 'error');
        startManualEntry(barcode);
        
    } catch (err) {
        showLoading(false);
        console.error('Barcode lookup error:', err);
        showToast('Errore nella ricerca. Riprova.', 'error');
    }
}

function submitManualBarcode() {
    const input = document.getElementById('manual-barcode-input');
    const barcode = (input.value || '').trim();
    if (!barcode) {
        showToast('Inserisci un codice a barre', 'error');
        input.focus();
        return;
    }
    if (!/^\d{4,14}$/.test(barcode)) {
        showToast('Il codice a barre deve contenere solo numeri (4-14 cifre)', 'error');
        input.focus();
        return;
    }
    stopScanner();
    onBarcodeDetected(barcode);
}

// ===== QUICK NAME ENTRY (for loose/unpackaged products) =====
async function submitQuickName() {
    const input = document.getElementById('quick-product-name');
    const name = (input.value || '').trim();
    if (!name || name.length < 2) {
        showToast('Scrivi almeno 2 caratteri', 'error');
        input.focus();
        return;
    }
    
    stopScanner();
    showLoading(true);
    
    try {
        // Search local products DB
        const localData = await api('products_search', { q: name });
        const localProducts = (localData.products || []).slice(0, 5);
        
        showLoading(false);
        
        if (localProducts.length > 0) {
            // Show results to pick from + option to create new
            showQuickNameResults(name, localProducts);
        } else {
            // No local results — create new product directly
            await createQuickProduct(name);
        }
    } catch (err) {
        showLoading(false);
        console.error('Quick name search error:', err);
        showToast('Errore nella ricerca', 'error');
    }
}

function showQuickNameResults(searchName, products) {
    const container = document.querySelector('.quick-name-entry');
    
    // Remove any previous results
    const oldResults = container.querySelector('.quick-name-results');
    if (oldResults) oldResults.remove();
    
    const resultsDiv = document.createElement('div');
    resultsDiv.className = 'quick-name-results';
    
    // Existing products
    products.forEach(p => {
        const catIcon = CATEGORY_ICONS[mapToLocalCategory(p.category, p.name)] || '📦';
        const item = document.createElement('div');
        item.className = 'quick-name-result-item';
        item.innerHTML = `
            <span class="qnr-icon">${catIcon}</span>
            <div class="qnr-info">
                <div class="qnr-name">${escapeHtml(p.name)}</div>
                <div class="qnr-detail">${p.brand ? escapeHtml(p.brand) + ' · ' : ''}${p.barcode ? '📊 ' + p.barcode : 'Senza barcode'}</div>
            </div>
        `;
        item.onclick = () => selectQuickProduct(p);
        resultsDiv.appendChild(item);
    });
    
    // "Create new" button
    const newItem = document.createElement('div');
    newItem.className = 'quick-name-result-item qnr-new';
    newItem.innerHTML = `
        <span class="qnr-icon">➕</span>
        <div class="qnr-info">
            <div class="qnr-name">Crea "${escapeHtml(searchName)}"</div>
            <div class="qnr-detail">Nuovo prodotto senza barcode</div>
        </div>
    `;
    newItem.onclick = () => createQuickProduct(searchName);
    resultsDiv.appendChild(newItem);
    
    container.appendChild(resultsDiv);
}

function selectQuickProduct(product) {
    currentProduct = {
        id: product.id,
        barcode: product.barcode || '',
        name: product.name,
        brand: product.brand || '',
        category: product.category || '',
        image_url: product.image_url || '',
        unit: product.unit || 'pz',
        default_quantity: product.default_quantity || 1,
    };
    // Extract weight_info from notes if available
    if (product.notes) {
        const pesoMatch = product.notes.match(/Peso:\s*([^·]+)/);
        if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
    }
    clearQuickNameResults();
    // Clear the search input
    const qInput = document.getElementById('quick-product-name');
    if (qInput) qInput.value = '';
    showProductAction();
}

async function createQuickProduct(name) {
    showLoading(true);
    
    // Auto-detect category from name
    const category = guessCategoryFromName(name);
    
    try {
        const result = await api('product_save', {}, 'POST', {
            name: name,
            brand: '',
            category: category,
            unit: 'pz',
            default_quantity: 1,
        });
        
        if (result.success || result.id) {
            currentProduct = {
                id: result.id,
                name: name,
                brand: '',
                category: category,
                unit: 'pz',
                default_quantity: 1,
            };
            showLoading(false);
            clearQuickNameResults();
            showToast('Prodotto creato!', 'success');
            showProductAction();
        } else {
            showLoading(false);
            showToast(result.error || 'Errore nel salvataggio', 'error');
        }
    } catch (err) {
        showLoading(false);
        console.error('Quick product creation error:', err);
        showToast('Errore di connessione', 'error');
    }
}

function clearQuickNameResults() {
    const container = document.querySelector('.quick-name-entry');
    if (container) {
        const results = container.querySelector('.quick-name-results');
        if (results) results.remove();
    }
    const input = document.getElementById('quick-product-name');
    if (input) input.value = '';
}

function startManualEntry(barcode = '') {
    stopScanner();
    // Reset form
    document.getElementById('pf-id').value = '';
    document.getElementById('pf-name').value = '';
    document.getElementById('pf-brand').value = '';
    document.getElementById('pf-category').value = '';
    document.getElementById('pf-unit').value = 'pz';
    document.getElementById('pf-defqty').value = '1';
    document.getElementById('pf-notes').value = '';
    document.getElementById('pf-barcode').value = barcode || '';
    document.getElementById('pf-image').value = '';
    document.getElementById('pf-image-preview').style.display = 'none';
    document.getElementById('product-form-title').textContent = 'Nuovo Prodotto';
    const pfAiRow = document.getElementById('pf-ai-fill-row');
    if (pfAiRow) pfAiRow.style.display = 'block';

    // Show barcode hint when no barcode was passed
    _updateBarcodeHint();
    document.getElementById('pf-barcode').addEventListener('input', _updateBarcodeHint);
    
    // Remove datalist/autocomplete suggestions for new products (they cause confusion)
    document.getElementById('pf-name').removeAttribute('list');
    document.getElementById('pf-brand').removeAttribute('list');
    
    // Reset conf-size-row visibility
    const pfConfRow = document.getElementById('pf-conf-size-row');
    if (pfConfRow) pfConfRow.style.display = 'none';
    document.getElementById('pf-conf-size').value = '';
    document.getElementById('pf-conf-unit').value = 'g';
    
    // Reset manual-edit tracking flags
    document.getElementById('pf-category').dataset.manuallySet = 'false';
    document.getElementById('pf-defqty').dataset.manuallySet = 'false';
    
    // Track if user manually changes the quantity field
    const qtyInput = document.getElementById('pf-defqty');
    qtyInput.removeEventListener('input', markQtyManuallySet);
    qtyInput.addEventListener('input', markQtyManuallySet);
    
    // Auto-detect name → category when typing
    const nameInput = document.getElementById('pf-name');
    nameInput.removeEventListener('input', autoDetectCategory);
    nameInput.addEventListener('input', autoDetectCategory);
    
    showPage('product-form');
}

function markQtyManuallySet() {
    document.getElementById('pf-defqty').dataset.manuallySet = 'true';
}

function autoDetectCategory() {
    const name = document.getElementById('pf-name').value.toLowerCase();
    if (name.length < 3) return;
    
    const catSelect = document.getElementById('pf-category');
    // Don't override if user already manually selected something
    if (catSelect.dataset.manuallySet === 'true') return;
    
    // Keywords → category mapping
    const keyword2cat = {
        'latte': 'latticini', 'yogurt': 'latticini', 'formaggio': 'latticini', 'mozzarella': 'latticini',
        'burro': 'latticini', 'panna': 'latticini', 'ricotta': 'latticini', 'mascarpone': 'latticini',
        'gorgonzola': 'latticini', 'parmigiano': 'latticini', 'grana': 'latticini', 'burrata': 'latticini',
        'stracchino': 'latticini', 'uova': 'latticini',
        'pollo': 'carne', 'manzo': 'carne', 'maiale': 'carne', 'vitello': 'carne', 'tacchino': 'carne',
        'prosciutto': 'carne', 'salame': 'carne', 'bresaola': 'carne', 'mortadella': 'carne',
        'wurstel': 'carne', 'macinato': 'carne', 'speck': 'carne',
        'salmone': 'pesce', 'tonno': 'pesce', 'sgombro': 'pesce', 'pesce': 'pesce', 'merluzzo': 'pesce',
        'mela': 'frutta', 'mele': 'frutta', 'banana': 'frutta', 'arancia': 'frutta', 'pera': 'frutta',
        'fragola': 'frutta', 'uva': 'frutta', 'kiwi': 'frutta', 'limone': 'frutta',
        'insalata': 'verdura', 'pomodor': 'verdura', 'zucchin': 'verdura', 'patat': 'verdura',
        'cipoll': 'verdura', 'carota': 'verdura', 'spinaci': 'verdura', 'rucola': 'verdura',
        'peperoni': 'verdura', 'melanzane': 'verdura', 'broccoli': 'verdura',
        'pasta': 'pasta', 'spaghetti': 'pasta', 'penne': 'pasta', 'fusilli': 'pasta', 'riso': 'pasta',
        'farina': 'pasta', 'rigatoni': 'pasta', 'farfalle': 'pasta',
        'pane': 'pane', 'fette biscottate': 'pane', 'pancarrè': 'pane', 'pan carrè': 'pane',
        'grissini': 'pane', 'crackers': 'pane', 'cracker': 'pane',
        'surgelat': 'surgelati', 'findus': 'surgelati', 'gelato': 'surgelati',
        'acqua': 'bevande', 'succo': 'bevande', 'birra': 'bevande', 'vino': 'bevande',
        'coca cola': 'bevande', 'aranciata': 'bevande', 'tè': 'bevande', 'caffè': 'bevande',
        'olio': 'condimenti', 'aceto': 'condimenti', 'sale': 'condimenti', 'pepe': 'condimenti',
        'maionese': 'condimenti', 'ketchup': 'condimenti', 'senape': 'condimenti', 'zucchero': 'condimenti',
        'biscott': 'snack', 'cioccolat': 'snack', 'nutella': 'snack', 'merendine': 'snack',
        'patatine': 'snack', 'caramelle': 'snack',
        'pelati': 'conserve', 'passata': 'conserve', 'legumi': 'conserve', 'ceci': 'conserve',
        'fagioli': 'conserve', 'lenticchie': 'conserve', 'marmellata': 'conserve', 'miele': 'conserve',
        'cereali': 'cereali', 'muesli': 'cereali', 'fiocchi': 'cereali',
    };
    
    for (const [keyword, cat] of Object.entries(keyword2cat)) {
        if (name.includes(keyword)) {
            catSelect.value = cat;
            onCategoryChange(true);
            return;
        }
    }
}

function onCategoryChange(fromAutoDetect = false) {
    const cat = document.getElementById('pf-category').value;
    const unitSelect = document.getElementById('pf-unit');
    const qtyInput = document.getElementById('pf-defqty');
    
    // If user manually changed category via dropdown, don't auto-fill qty/unit
    if (!fromAutoDetect) {
        // Mark qty as "set" so future auto-detects won't overwrite either
        qtyInput.dataset.manuallySet = 'true';
        return;
    }
    
    // Auto-detect from name: suggest default unit/qty based on category
    // BUT only if user hasn't manually changed the quantity field
    const catDefaults = {
        'latticini': { unit: 'pz', qty: 1 },
        'carne': { unit: 'g', qty: 500 },
        'pesce': { unit: 'g', qty: 300 },
        'frutta': { unit: 'g', qty: 1000 },
        'verdura': { unit: 'g', qty: 500 },
        'pasta': { unit: 'g', qty: 500 },
        'pane': { unit: 'pz', qty: 1 },
        'surgelati': { unit: 'g', qty: 450 },
        'bevande': { unit: 'ml', qty: 1000 },
        'condimenti': { unit: 'pz', qty: 1 },
        'snack': { unit: 'g', qty: 250 },
        'conserve': { unit: 'g', qty: 400 },
        'cereali': { unit: 'g', qty: 500 },
        'igiene': { unit: 'pz', qty: 1 },
        'pulizia': { unit: 'pz', qty: 1 },
    };
    
    if (catDefaults[cat]) {
        // Only auto-fill unit/qty if user hasn't manually touched them
        if (qtyInput.dataset.manuallySet !== 'true') {
            unitSelect.value = catDefaults[cat].unit;
            qtyInput.value = catDefaults[cat].qty;
        }
    }
}

function onPfUnitChange() {
    const unit = document.getElementById('pf-unit').value;
    const confRow = document.getElementById('pf-conf-size-row');
    if (confRow) confRow.style.display = unit === 'conf' ? 'block' : 'none';
}

function _updateBarcodeHint() {
    const hint = document.getElementById('pf-barcode-hint');
    const val = (document.getElementById('pf-barcode')?.value || '').trim();
    if (hint) hint.style.display = val ? 'none' : 'block';
}

/**
 * Open a temporary camera modal to scan a barcode and fill the pf-barcode field.
 * Uses BarcodeDetector if available, otherwise shows manual-input fallback.
 */
async function scanBarcodeForForm() {
    const overlayEl = document.getElementById('modal-overlay');
    const contentEl = document.getElementById('modal-content');

    let stream = null;
    let scanning = true;

    const stopStream = () => {
        scanning = false;
        if (stream) stream.getTracks().forEach(t => t.stop());
        stream = null;
    };

    const closeScanner = () => {
        stopStream();
        overlayEl.style.display = 'none';
    };

    contentEl.innerHTML = `
        <div class="modal-header">
            <h3>🔖 Scansiona Barcode</h3>
            <button class="modal-close" onclick="document.getElementById('modal-overlay').style.display='none'">✕</button>
        </div>
        <div style="position:relative;width:100%;background:#000;border-radius:10px;overflow:hidden;aspect-ratio:4/3">
            <video id="pf-bc-video" autoplay playsinline muted style="width:100%;height:100%;object-fit:cover"></video>
            <div class="scanner-line scanning" style="position:absolute;left:0;right:0;top:50%;transform:translateY(-50%);height:2px;background:rgba(59,130,246,0.8)"></div>
        </div>
        <p style="text-align:center;margin-top:12px;color:var(--text-muted);font-size:0.88rem">Inquadra il codice a barre del prodotto</p>
        <div style="margin-top:10px;text-align:center">
            <input type="text" id="pf-bc-manual" class="form-input" placeholder="O inserisci manualmente..." inputmode="numeric" style="max-width:260px;display:inline-block">
            <button class="btn btn-primary" style="margin-top:8px;width:100%" onclick="
                const v = document.getElementById('pf-bc-manual').value.trim();
                if(v){ document.getElementById('pf-barcode').value=v; _updateBarcodeHint(); document.getElementById('modal-overlay').style.display='none'; }
            ">✅ Usa questo codice</button>
        </div>
    `;
    overlayEl.style.display = 'flex';

    // Attach close handler (clicking backdrop)
    overlayEl.onclick = (e) => { if (e.target === overlayEl) { stopStream(); overlayEl.style.display = 'none'; overlayEl.onclick = null; } };

    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        const video = document.getElementById('pf-bc-video');
        video.srcObject = stream;
        await video.play();

        if (!('BarcodeDetector' in window)) {
            // No native API — just let user type manually
            return;
        }

        const detector = new BarcodeDetector({ formats: ['ean_13','ean_8','code_128','code_39','upc_a','upc_e'] });
        const detectionHistory = {};

        const scanFrame = async () => {
            if (!scanning || !stream) return;
            try {
                const barcodes = await detector.detect(video);
                if (barcodes.length > 0) {
                    const code = barcodes[0].rawValue;
                    detectionHistory[code] = (detectionHistory[code] || 0) + 1;
                    if (detectionHistory[code] >= 2) {
                        scanning = false;
                        stopStream();
                        overlayEl.style.display = 'none';
                        overlayEl.onclick = null;
                        document.getElementById('pf-barcode').value = code;
                        _updateBarcodeHint();
                        if (navigator.vibrate) navigator.vibrate(80);
                        showToast(`🔖 Barcode acquisito: ${code}`, 'success');
                        return;
                    }
                }
            } catch (_) {}
            if (scanning) requestAnimationFrame(scanFrame);
        };
        requestAnimationFrame(scanFrame);

    } catch (err) {
        // Camera not available — user can still type manually
        const videoEl = document.getElementById('pf-bc-video');
        if (videoEl) videoEl.style.display = 'none';
    }
}

async function submitProduct(e) {
    e.preventDefault();
    showLoading(true);
    
    const pfUnit = document.getElementById('pf-unit').value;
    const productData = {
        id: document.getElementById('pf-id').value || null,
        name: document.getElementById('pf-name').value,
        brand: document.getElementById('pf-brand').value,
        category: document.getElementById('pf-category').value,
        unit: pfUnit,
        default_quantity: pfUnit === 'conf' ? (parseFloat(document.getElementById('pf-conf-size')?.value) || 1) : (parseFloat(document.getElementById('pf-defqty').value) || 1),
        package_unit: pfUnit === 'conf' ? (document.getElementById('pf-conf-unit')?.value || '') : '',
        notes: document.getElementById('pf-notes').value,
        barcode: document.getElementById('pf-barcode').value || null,
        image_url: document.getElementById('pf-image').value || '',
    };
    
    try {
        const result = await api('product_save', {}, 'POST', productData);
        if (result.success) {
            currentProduct = { ...productData, id: result.id };
            showLoading(false);
            showToast('Prodotto salvato!', 'success');
            showProductAction();
        } else {
            showLoading(false);
            showToast(result.error || 'Errore nel salvataggio', 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast('Errore di connessione', 'error');
    }
}

// ===== PRODUCT ACTION (IN/OUT) =====
function showProductAction() {
    if (!currentProduct) return;
    
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(currentProduct.category, currentProduct.name)] || '📦';
    const nutriscoreColors = { a: '#1e8f4e', b: '#60ac0e', c: '#eeae0e', d: '#ff6f1e', e: '#e63e11' };
    
    let detailsHtml = '';
    
    // Weight / quantity info
    if (currentProduct.weight_info) {
        detailsHtml += `<div class="product-detail-tag">⚖️ ${escapeHtml(currentProduct.weight_info)}</div>`;
    }
    
    // Nutriscore badge
    if (currentProduct.nutriscore) {
        const ns = currentProduct.nutriscore.toLowerCase();
        const nsColor = nutriscoreColors[ns] || '#999';
        detailsHtml += `<div class="product-detail-tag" style="background:${nsColor};color:#fff;font-weight:600">Nutri-Score ${ns.toUpperCase()}</div>`;
    }
    
    // NOVA group
    if (currentProduct.nova_group) {
        const novaLabels = { '1': 'Non trasformato', '2': 'Ingrediente culinario', '3': 'Trasformato', '4': 'Ultra-trasformato' };
        detailsHtml += `<div class="product-detail-tag">🏭 NOVA ${currentProduct.nova_group}${novaLabels[currentProduct.nova_group] ? ' - ' + novaLabels[currentProduct.nova_group] : ''}</div>`;
    }
    
    // Ecoscore
    if (currentProduct.ecoscore) {
        const es = currentProduct.ecoscore.toLowerCase();
        const esColor = nutriscoreColors[es] || '#999';
        detailsHtml += `<div class="product-detail-tag" style="background:${esColor};color:#fff;font-weight:600">🌍 Eco-Score ${es.toUpperCase()}</div>`;
    }
    
    // Origin
    if (currentProduct.origin) {
        detailsHtml += `<div class="product-detail-tag">📍 ${escapeHtml(currentProduct.origin)}</div>`;
    }
    
    // Labels (bio, DOP, etc.)
    if (currentProduct.labels) {
        detailsHtml += `<div class="product-detail-tag">🏷️ ${escapeHtml(currentProduct.labels)}</div>`;
    }
    
    // Allergens
    let allergensHtml = '';
    if (currentProduct.allergens) {
        allergensHtml = `<div class="product-allergens">⚠️ <strong>Allergeni:</strong> ${escapeHtml(currentProduct.allergens)}</div>`;
    }
    
    // Ingredients (collapsible)
    let ingredientsHtml = '';
    if (currentProduct.ingredients) {
        ingredientsHtml = `
            <details class="product-ingredients">
                <summary>📋 Ingredienti</summary>
                <p>${escapeHtml(currentProduct.ingredients)}</p>
            </details>
        `;
    }
    
    // Conservation
    let conservationHtml = '';
    if (currentProduct.conservation) {
        conservationHtml = `<div class="product-conservation">🧊 ${escapeHtml(currentProduct.conservation)}</div>`;
    }
    
    // LARGER product preview
    document.getElementById('action-product-preview').innerHTML = `
        ${currentProduct.image_url ?
            `<img src="${escapeHtml(currentProduct.image_url)}" alt="">` :
            `<span class="product-preview-emoji">${catIcon}</span>`
        }
        <div class="product-preview-info">
            <h3>${escapeHtml(currentProduct.name)}</h3>
            <p>${currentProduct.brand ? `<strong>${escapeHtml(currentProduct.brand)}</strong>` : ''}</p>
            ${currentProduct.weight_info ? `<p style="font-size:0.85rem;color:var(--text-light)">⚖️ ${escapeHtml(currentProduct.weight_info)}</p>` : ''}
            ${currentProduct.barcode ? `<p style="font-size:0.75rem;color:var(--text-muted)">📊 ${currentProduct.barcode}</p>` : ''}
        </div>
        <button type="button" class="btn-edit-inline" onclick="toggleActionEdit()" title="Modifica nome/marca">✏️</button>
    `;
    
    // Check if product needs editing (unknown name, missing info)
    const isUnknown = !currentProduct.name || 
        /sconosciuto|unknown|^$/i.test(currentProduct.name.trim()) ||
        currentProduct.name.trim().length < 2;
    
    // Edit product info section
    let editInfoEl = document.getElementById('action-edit-info');
    if (!editInfoEl) {
        editInfoEl = document.createElement('div');
        editInfoEl.id = 'action-edit-info';
        const preview = document.getElementById('action-product-preview');
        preview.parentElement.insertBefore(editInfoEl, preview.nextSibling);
    }
    
    // Always build the edit form, but only show it auto-opened for unknown products
    const categoryOptions = Object.entries(CATEGORY_LABELS).map(([key, label]) => 
        `<option value="${key}" ${mapToLocalCategory(currentProduct.category, currentProduct.name) === key ? 'selected' : ''}>${label}</option>`
    ).join('');
    
    editInfoEl.innerHTML = `
        <div class="edit-unknown-card ${isUnknown ? 'highlight' : ''}">
            <h4>${isUnknown ? '⚠️ Prodotto non riconosciuto' : '✏️ Modifica informazioni'}</h4>
            ${isUnknown ? '<p class="edit-unknown-hint">Inserisci il nome e le informazioni del prodotto</p>' : ''}
            <div class="edit-unknown-form">
                <div class="form-group">
                    <label>🏷️ Nome prodotto</label>
                    <input type="text" id="edit-action-name" class="form-input" value="${escapeHtml(isUnknown ? '' : currentProduct.name)}" placeholder="Es: Latte intero, Pasta penne..." required>
                </div>
                <div class="form-group">
                    <label>🏪 Marca</label>
                    <input type="text" id="edit-action-brand" class="form-input" value="${escapeHtml(currentProduct.brand || '')}" placeholder="Es: Barilla, Mulino Bianco...">
                </div>
                <div class="form-group">
                    <label>📂 Categoria</label>
                    <select id="edit-action-category" class="form-input">
                        <option value="">-- Seleziona --</option>
                        ${categoryOptions}
                    </select>
                </div>
                <button type="button" class="btn btn-primary full-width" onclick="saveEditedProductInfo()">💾 Salva informazioni</button>
            </div>
        </div>
    `;
    editInfoEl.style.display = isUnknown ? 'block' : 'none';
    if (isUnknown) {
        setTimeout(() => document.getElementById('edit-action-name')?.focus(), 100);
    }
    
    // Show extra product info section below preview
    let extraInfoEl = document.getElementById('action-product-details');
    if (!extraInfoEl) {
        const container = document.getElementById('action-product-preview').parentElement;
        extraInfoEl = document.createElement('div');
        extraInfoEl.id = 'action-product-details';
        const actionBtns = document.getElementById('action-buttons-container');
        actionBtns.parentElement.insertBefore(extraInfoEl, actionBtns);
    }
    
    if (detailsHtml || allergensHtml || ingredientsHtml || conservationHtml) {
        extraInfoEl.innerHTML = `
            <div class="product-details-card">
                ${detailsHtml ? `<div class="product-detail-tags">${detailsHtml}</div>` : ''}
                ${allergensHtml}
                ${ingredientsHtml}
                ${conservationHtml}
            </div>
        `;
        extraInfoEl.style.display = 'block';
    } else {
        extraInfoEl.style.display = 'none';
        extraInfoEl.innerHTML = '';
    }
    
    // === CHECK INVENTORY FOR THIS PRODUCT ===
    checkInventoryForProduct(currentProduct.id).then(inventoryItems => {
        _actionInventoryItems = inventoryItems;
        const statusBar = document.getElementById('action-inventory-status');
        const btnsContainer = document.getElementById('action-buttons-container');
        
        if (inventoryItems.length > 0) {
            // Product IS in inventory - show status and 3 buttons
            statusBar.style.display = 'block';
            let totalQty = 0;
            const unit = inventoryItems[0].unit || 'pz';
            const defQty = inventoryItems[0].default_quantity || 0;
            const pkgUnit = inventoryItems[0].package_unit || '';
            const invHtml = inventoryItems.map(inv => {
                const locInfo = LOCATIONS[inv.location] || { icon: '📦', label: inv.location };
                const qtyStr = formatQuantity(inv.quantity, inv.unit, inv.default_quantity, inv.package_unit);
                const pkgF = formatPackageFraction(inv.quantity, inv.default_quantity);
                totalQty += parseFloat(inv.quantity);
                let expiryStr = '';
                if (inv.expiry_date) {
                    const d = daysUntilExpiry(inv.expiry_date);
                    if (d < 0) expiryStr = ` · ⚠️ Scaduto da ${Math.abs(d)}g`;
                    else if (d <= 3) expiryStr = ` · 🔴 Scade tra ${d}g`;
                    else if (d <= 7) expiryStr = ` · 🟡 Scade tra ${d}g`;
                    else expiryStr = ` · 📅 ${formatDate(inv.expiry_date)}`;
                }
                const vacuumIcon = inv.vacuum_sealed ? ' 🫙' : '';
                return `<div class="inv-status-item inv-status-item-clickable" onclick="editActionInventoryItem(${inv.id})"><span>${locInfo.icon} ${locInfo.label}${vacuumIcon}${expiryStr}</span><span class="inv-status-qty">${qtyStr}${pkgF ? ' ' + pkgF : ''} ✏️</span></div>`;
            }).join('');
            
            const totalStr = formatQuantity(totalQty, unit, defQty, pkgUnit);
            const totalFrac = formatPackageFraction(totalQty, defQty);
            
            statusBar.innerHTML = `
                <div class="inv-status-header">
                    <span class="inv-status-title">📦 Ce l'hai già!</span>
                    <div class="inv-status-total-col">
                        <span class="inv-status-total">${totalStr}</span>
                        ${totalFrac ? `<span class="inv-status-total-frac">${totalFrac}</span>` : ''}
                    </div>
                </div>
                <div class="inv-status-items">${invHtml}</div>
                <p style="font-size:0.75rem;color:var(--text-muted);text-align:center;margin:4px 0 0">Tocca una riga per modificare</p>
            `;
            
            btnsContainer.className = 'action-buttons-4col';
            btnsContainer.innerHTML = `
                <button class="btn btn-huge btn-success" onclick="showAddForm()">
                    <span class="btn-icon">📥</span>
                    <span class="btn-text">AGGIUNGI<br><small>altra quantità</small></span>
                </button>
                <button class="btn btn-huge btn-danger" onclick="showUseForm()">
                    <span class="btn-icon">📤</span>
                    <span class="btn-text">USA<br><small>quanto ne hai usato</small></span>
                </button>
                <button class="btn btn-huge btn-throw" onclick="showThrowForm()">
                    <span class="btn-icon">🗑️</span>
                    <span class="btn-text">BUTTA<br><small>butta il prodotto</small></span>
                </button>
                <button class="btn btn-huge btn-edit" onclick="editProductFromAction()">
                    <span class="btn-icon">✏️</span>
                    <span class="btn-text">MODIFICA<br><small>modifica info</small></span>
                </button>
            `;
        } else {
            // Product NOT in inventory - show only AGGIUNGI
            statusBar.style.display = 'none';
            btnsContainer.className = 'action-buttons';
            btnsContainer.innerHTML = `
                <button class="btn btn-huge btn-success" onclick="showAddForm()" style="flex:1">
                    <span class="btn-icon">📥</span>
                    <span class="btn-text">AGGIUNGI<br><small>in dispensa/frigo</small></span>
                </button>
            `;
        }
    });
    
    // Update back button: go back to shopping if came from shopping list scan
    const backBtn = document.getElementById('action-back-btn');
    if (backBtn) backBtn.onclick = _spesaScanTarget ? () => { _spesaScanTarget = null; showPage('shopping'); } : () => showPage('scan');

    // Show "shopping target" banner if we came from the shopping list
    const banner = document.getElementById('shopping-scan-target-banner');
    if (banner && _spesaScanTarget) {
        const targetName = _spesaScanTarget.name;
        banner.style.display = 'block';
        banner.innerHTML = `
            <div class="shopping-scan-target-info">
                <span class="stb-label">🛒 Stai cercando</span>
                <span class="stb-name">${escapeHtml(targetName)}</span>
            </div>
            <div class="shopping-scan-target-actions">
                <button class="btn btn-success stb-btn" onclick="confirmShoppingItemFound()">✅ Trovato! Rimuovi dalla lista</button>
                <button class="btn btn-secondary stb-btn" onclick="_spesaScanTarget=null; document.getElementById('shopping-scan-target-banner').style.display='none'; document.getElementById('action-back-btn').onclick=()=>showPage('scan')">✕ Annulla</button>
            </div>
        `;
    } else if (banner) {
        banner.style.display = 'none';
    }

    showPage('action');
}

// Check if product exists in inventory
async function checkInventoryForProduct(productId) {
    try {
        const data = await api('inventory_list');
        return (data.inventory || []).filter(i => i.product_id == productId);
    } catch(e) {
        return [];
    }
}

// === EDIT PRODUCT FROM ACTION PAGE ===
function editProductFromAction() {
    if (!currentProduct) return;
    // Pre-fill the product form with current product data
    document.getElementById('pf-id').value = currentProduct.id || '';
    document.getElementById('pf-name').value = currentProduct.name || '';
    document.getElementById('pf-brand').value = currentProduct.brand || '';
    document.getElementById('pf-barcode').value = currentProduct.barcode || '';
    document.getElementById('pf-image').value = '';
    document.getElementById('pf-notes').value = currentProduct.notes || '';
    document.getElementById('pf-unit').value = currentProduct.unit || 'pz';
    document.getElementById('pf-defqty').value = currentProduct.default_quantity || 1;
    document.getElementById('product-form-title').textContent = 'Modifica Prodotto';
    const pfAiRow = document.getElementById('pf-ai-fill-row');
    if (pfAiRow) pfAiRow.style.display = 'none';
    // Keep barcode hint hidden in edit mode
    const pfBcHint = document.getElementById('pf-barcode-hint');
    if (pfBcHint) pfBcHint.style.display = 'none';

    // Restore datalist for editing (was removed for new products)
    document.getElementById('pf-name').setAttribute('list', 'common-products');
    document.getElementById('pf-brand').setAttribute('list', 'common-brands');

    // Set category
    const cat = mapToLocalCategory(currentProduct.category, currentProduct.name);
    document.getElementById('pf-category').value = cat;
    document.getElementById('pf-category').dataset.manuallySet = 'true';
    document.getElementById('pf-defqty').dataset.manuallySet = 'true';

    // Image preview - not shown in edit mode
    const preview = document.getElementById('pf-image-preview');
    preview.style.display = 'none';

    // Conf size row
    const pfConfRow = document.getElementById('pf-conf-size-row');
    if (currentProduct.unit === 'conf' && pfConfRow) {
        pfConfRow.style.display = 'block';
        document.getElementById('pf-conf-size').value = currentProduct.default_quantity || '';
        document.getElementById('pf-conf-unit').value = currentProduct.package_unit || 'g';
    } else if (pfConfRow) {
        pfConfRow.style.display = 'none';
    }

    showPage('product-form');
}

// === EDIT INVENTORY ITEM FROM ACTION PAGE ===
function editActionInventoryItem(inventoryId) {
    const item = _actionInventoryItems.find(i => i.id === inventoryId);
    if (!item) return;
    
    const isConf = (item.unit || 'pz') === 'conf';
    const confSizeVal = (isConf && item.default_quantity > 0) ? item.default_quantity : '';
    const confUnitVal = (isConf && item.package_unit) ? item.package_unit : 'g';
    
    window._editingProduct = { name: item.name || currentProduct.name, category: item.category || currentProduct.category || '' };
    
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>Modifica ${escapeHtml(item.name || currentProduct.name)}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form class="form" onsubmit="submitActionEditInventory(event, ${inventoryId}, ${item.product_id})">
            <div class="form-group">
                <label>📦 Quantità</label>
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="adjustQty('action-edit-qty', -1)">−</button>
                    <input type="number" id="action-edit-qty" value="${item.quantity}" min="0" step="any" class="qty-input">
                    <button type="button" class="qty-btn" onclick="adjustQty('action-edit-qty', 1)">+</button>
                </div>
            </div>
            <div class="form-group">
                <label>📏 Unità di misura</label>
                <select id="action-edit-unit" class="form-input" onchange="onActionEditUnitChange()">
                    ${['pz','g','ml','conf'].map(u => `<option value="${u}" ${(item.unit||'pz') === u ? 'selected' : ''}>${u === 'pz' ? 'pz (pezzi)' : u === 'g' ? 'g (grammi)' : u === 'ml' ? 'ml (millilitri)' : u === 'conf' ? 'conf (confezioni)' : u}</option>`).join('')}
                </select>
            </div>
            <div class="form-group" id="action-edit-conf-group" style="display:${isConf ? 'block' : 'none'}">
                <label>📦 Ogni confezione contiene:</label>
                <div class="conf-size-inputs">
                    <input type="number" id="action-edit-conf-size" class="form-input conf-size-input" min="1" step="any" value="${confSizeVal}" placeholder="es. 300">
                    <select id="action-edit-conf-unit" class="form-input conf-size-unit">
                        ${['g','ml'].map(u => `<option value="${u}" ${confUnitVal === u ? 'selected' : ''}>${u}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>📍 Posizione</label>
                <div class="location-selector">
                    ${Object.entries(LOCATIONS).map(([k, v]) => `
                        <button type="button" class="loc-btn ${item.location === k ? 'active' : ''}" 
                            onclick="this.parentElement.querySelectorAll('.loc-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');document.getElementById('action-edit-loc').value='${k}';recalcEditExpiry('action-edit-loc','action-edit-vacuum','action-edit-expiry')">${v.icon} ${v.label}</button>
                    `).join('')}
                </div>
                <input type="hidden" id="action-edit-loc" value="${item.location}">
            </div>
            <div class="form-group">
                <label>📅 Scadenza</label>
                <input type="date" id="action-edit-expiry" value="${item.expiry_date || ''}" class="form-input">
            </div>
            <div class="form-group">
                <label class="toggle-row">
                    <span>🫙 Sotto vuoto</span>
                    <span class="toggle-switch">
                        <input type="checkbox" id="action-edit-vacuum" ${item.vacuum_sealed ? 'checked' : ''} onchange="recalcEditExpiry('action-edit-loc','action-edit-vacuum','action-edit-expiry')">
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>
            <div class="modal-actions" style="margin-top:12px">
                <button type="submit" class="btn btn-large btn-primary flex-1">💾 Salva</button>
                <button type="button" class="btn btn-secondary" onclick="deleteActionInventoryItem(${inventoryId})" style="padding:12px">🗑️</button>
            </div>
        </form>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

function onActionEditUnitChange() {
    const unit = document.getElementById('action-edit-unit').value;
    const confGroup = document.getElementById('action-edit-conf-group');
    if (confGroup) confGroup.style.display = unit === 'conf' ? 'block' : 'none';
}

async function submitActionEditInventory(e, id, productId) {
    e.preventDefault();
    const qty = parseFloat(document.getElementById('action-edit-qty').value);
    const loc = document.getElementById('action-edit-loc').value;
    const expiry = document.getElementById('action-edit-expiry').value || null;
    const unit = document.getElementById('action-edit-unit').value;
    
    const payload = { id, quantity: qty, location: loc, expiry_date: expiry, unit, product_id: productId,
        vacuum_sealed: document.getElementById('action-edit-vacuum')?.checked ? 1 : 0 };
    
    if (unit === 'conf') {
        payload.package_unit = document.getElementById('action-edit-conf-unit')?.value || '';
        payload.package_size = parseFloat(document.getElementById('action-edit-conf-size')?.value) || 0;
    } else {
        payload.package_unit = '';
        payload.package_size = 0;
    }
    
    await api('inventory_update', {}, 'POST', payload);
    closeModal();
    showToast('Aggiornato!', 'success');
    showProductAction(); // Refresh the action page
}

async function deleteActionInventoryItem(id) {
    if (confirm('Vuoi davvero rimuovere questo prodotto dall\'inventario?')) {
        await api('inventory_delete', {}, 'POST', { id });
        closeModal();
        showToast('Prodotto rimosso', 'success');
        showProductAction(); // Refresh the action page
    }
}

// === THROW AWAY FORM ===
function showThrowForm() {
    // Open a modal to ask how much to throw away
    api('inventory_list').then(data => {
        const items = (data.inventory || []).filter(i => i.product_id == currentProduct.id);
        if (items.length === 0) {
            showToast('Prodotto non nell\'inventario', 'error');
            return;
        }
        
        const totalQty = items.reduce((sum, i) => sum + parseFloat(i.quantity), 0);
        const unit = items[0].unit || 'pz';
        const defQty = items[0].default_quantity || 0;
        const pkgUnit = items[0].package_unit || '';
        const qtyDisplay = formatQuantity(totalQty, unit, defQty, pkgUnit);
        
        let locOptionsHtml = items.map(inv => {
            const locInfo = LOCATIONS[inv.location] || { icon: '📦', label: inv.location };
            return `<div class="inv-status-item"><span>${locInfo.icon} ${locInfo.label}</span><span class="inv-status-qty">${formatQuantity(inv.quantity, inv.unit, inv.default_quantity, inv.package_unit)}</span></div>`;
        }).join('');
        
        document.getElementById('modal-content').innerHTML = `
            <div class="modal-header">
                <h3>🗑️ Butta Prodotto</h3>
                <button class="modal-close" onclick="closeModal()">✕</button>
            </div>
            <div class="product-preview-small" style="margin-bottom:12px">
                ${currentProduct.image_url ?
                    `<img src="${escapeHtml(currentProduct.image_url)}" alt="" style="width:50px;height:50px;border-radius:10px;object-fit:cover">` :
                    `<span style="font-size:2rem">${CATEGORY_ICONS[mapToLocalCategory(currentProduct.category, currentProduct.name)] || '📦'}</span>`
                }
                <div class="product-preview-info">
                    <h3>${escapeHtml(currentProduct.name)}</h3>
                    <p>Disponibile: <strong>${qtyDisplay}</strong></p>
                </div>
            </div>
            <div class="inventory-status-bar" style="margin-bottom:16px">
                <div class="inv-status-items">${locOptionsHtml}</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:10px">
                <button class="btn btn-large btn-danger full-width" onclick="throwAll()">
                    🗑️ Butta TUTTO (${qtyDisplay})
                </button>
                <div style="text-align:center;color:var(--text-muted);font-size:0.85rem">oppure specifica la quantità:</div>
                <div class="form-group">
                    <label>📍 Da dove?</label>
                    <div class="location-selector" id="throw-location-selector">
                        ${items.map((inv, idx) => {
                            const locInfo = LOCATIONS[inv.location] || { icon: '📦', label: inv.location };
                            return `<button type="button" class="loc-btn ${idx === 0 ? 'active' : ''}" onclick="selectThrowLocation(this, '${inv.location}')">${locInfo.icon} ${locInfo.label} (${formatQuantity(inv.quantity, inv.unit, inv.default_quantity, inv.package_unit)})</button>`;
                        }).join('')}
                    </div>
                    <input type="hidden" id="throw-location" value="${items[0].location}">
                </div>
                <div class="form-group">
                    <label>Quanto butti?</label>
                    <div class="qty-control">
                        <button type="button" class="qty-btn" onclick="adjustQty('throw-quantity', -1)">−</button>
                        <input type="number" id="throw-quantity" value="1" min="0.1" step="any" class="qty-input">
                        <button type="button" class="qty-btn" onclick="adjustQty('throw-quantity', 1)">+</button>
                    </div>
                </div>
                <button class="btn btn-large btn-warning full-width" onclick="throwPartial()">
                    🗑️ Butta questa quantità
                </button>
            </div>
        `;
        document.getElementById('modal-overlay').style.display = 'flex';
    });
}

function selectThrowLocation(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('throw-location').value = loc;
}

async function throwAll() {
    closeModal();
    showLoading(true);
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            use_all: true,
            location: '__all__',
            notes: 'Buttato'
        });
        showLoading(false);
        if (result.success) {
            showToast(`🗑️ ${currentProduct.name} buttato!`, 'success');
            showPage('dashboard');
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch(e) {
        showLoading(false);
        showToast('Errore di connessione', 'error');
    }
}

async function throwPartial() {
    const qty = parseFloat(document.getElementById('throw-quantity').value) || 1;
    const loc = document.getElementById('throw-location').value;
    closeModal();
    showLoading(true);
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: qty,
            location: loc,
            notes: 'Buttato'
        });
        showLoading(false);
        if (result.success) {
            showToast(`🗑️ Buttato ${qty} ${currentProduct.unit || 'pz'} di ${currentProduct.name}`, 'success');
            showPage('dashboard');
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch(e) {
        showLoading(false);
        showToast('Errore di connessione', 'error');
    }
}

function toggleActionEdit() {
    const el = document.getElementById('action-edit-info');
    if (!el) return;
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    if (el.style.display === 'block') {
        setTimeout(() => document.getElementById('edit-action-name')?.focus(), 100);
    }
}

async function saveEditedProductInfo() {
    const name = (document.getElementById('edit-action-name')?.value || '').trim();
    if (!name) {
        showToast('Inserisci il nome del prodotto', 'error');
        document.getElementById('edit-action-name')?.focus();
        return;
    }
    const brand = (document.getElementById('edit-action-brand')?.value || '').trim();
    const category = document.getElementById('edit-action-category')?.value || '';
    
    showLoading(true);
    try {
        const result = await api('product_save', {}, 'POST', {
            id: currentProduct.id,
            barcode: currentProduct.barcode || null,
            name: name,
            brand: brand,
            category: category || currentProduct.category || '',
            image_url: currentProduct.image_url || '',
            unit: currentProduct.unit || 'pz',
            default_quantity: currentProduct.default_quantity || 1,
            notes: currentProduct.notes || '',
        });
        showLoading(false);
        if (result.success) {
            // Update current product in memory
            currentProduct.name = name;
            currentProduct.brand = brand;
            if (category) currentProduct.category = category;
            showToast('✅ Prodotto aggiornato!', 'success');
            // Refresh the action page with updated data
            showProductAction();
        } else {
            showToast(result.error || 'Errore nel salvataggio', 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast('Errore di connessione', 'error');
    }
}

// ===== ADD TO INVENTORY =====
function showAddForm() {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(currentProduct.category, currentProduct.name)] || '📦';
    document.getElementById('add-product-preview').innerHTML = `
        ${currentProduct.image_url ?
            `<img src="${escapeHtml(currentProduct.image_url)}" alt="">` :
            `<span style="font-size:2rem">${catIcon}</span>`
        }
        <div class="product-preview-info">
            <h3>${escapeHtml(currentProduct.name)}</h3>
            <p>${currentProduct.brand ? escapeHtml(currentProduct.brand) : ''}</p>
            ${currentProduct.weight_info ? `<p style="font-size:0.8rem;color:var(--text-light)">${escapeHtml(currentProduct.weight_info)}</p>` : ''}
        </div>
    `;
    
    // Set unit selector
    const unit = currentProduct.unit || 'pz';
    const unitSelect = document.getElementById('add-unit');
    unitSelect.value = unit;
    
    document.getElementById('add-quantity').value = unit === 'conf' ? (currentProduct._confCount || currentProduct.last_qty || 1) : (currentProduct.default_quantity || 1);
    document.getElementById('add-quantity').dataset.manuallySet = 'false';
    
    // Show/hide conf size row and pre-fill
    const confRow = document.getElementById('add-conf-size-row');
    if (confRow) {
        confRow.style.display = unit === 'conf' ? 'block' : 'none';
        if (unit === 'conf' && currentProduct.package_unit && currentProduct.default_quantity > 0) {
            document.getElementById('add-conf-size').value = currentProduct.default_quantity;
            document.getElementById('add-conf-unit').value = currentProduct.package_unit;
        } else if (unit === 'conf' && ['g', 'ml', 'kg', 'l'].includes(currentProduct.unit) && currentProduct.default_quantity > 0) {
            // Product was defined in weight/volume — that quantity IS the package size
            document.getElementById('add-conf-size').value = currentProduct.default_quantity;
            document.getElementById('add-conf-unit').value = currentProduct.unit;
        } else if (unit === 'conf') {
            document.getElementById('add-conf-size').value = '';
            document.getElementById('add-conf-unit').value = 'g';
        }
    }
    
    // Track manual edits to quantity in add form
    const addQtyInput = document.getElementById('add-quantity');
    addQtyInput.removeEventListener('input', markAddQtyManuallySet);
    addQtyInput.addEventListener('input', markAddQtyManuallySet);
    
    // Show weight info if product has it
    const weightInfoEl = document.getElementById('add-weight-info');
    if (currentProduct.weight_info) {
        weightInfoEl.textContent = `📦 Confezione: ${currentProduct.weight_info}`;
        weightInfoEl.style.display = 'block';
    } else {
        weightInfoEl.style.display = 'none';
    }
    
    // Set qty step based on selected unit
    updateAddQtyStep();
    
    // Auto-detect location
    const autoLoc = guessLocation(currentProduct);
    document.getElementById('add-location').value = autoLoc;
    
    // Highlight correct location button
    document.querySelectorAll('#page-add .loc-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('#page-add .loc-btn').forEach(b => {
        const btnText = b.textContent.toLowerCase();
        if (btnText.includes(autoLoc)) b.classList.add('active');
    });
    
    // Show the purchase-type selector  
    const expirySection = document.getElementById('add-expiry-section');
    const estimatedDays = estimateExpiryDays(currentProduct, autoLoc);
    const estimatedDate = addDays(estimatedDays);
    const estimateLabel = formatEstimatedExpiry(estimatedDays);
    
    let expirySuffix = autoLoc === 'freezer' ? ' (freezer)' : '';
    
    // Reset vacuum sealed toggle
    const vacuumCb = document.getElementById('add-vacuum-sealed');
    if (vacuumCb) {
        vacuumCb.checked = false;
        document.getElementById('add-vacuum-hint').style.display = 'none';
    }
    // Reset historical expiry for this product; will be fetched async
    window._historyExpiryDays = null;
    window._historyExpiryCount = 0;
    // Reset extra batches from previous add
    window._addExtraBatches = [];
    // Store base expiry for vacuum recalculation
    window._addBaseExpiryDays = estimatedDays;
    
    expirySection.innerHTML = `
        <label>🛒 Questo prodotto è...</label>
        <div class="purchase-type-selector">
            <button type="button" class="purchase-type-btn active" onclick="selectPurchaseType(this, 'new')">
                🆕 Appena comprato
            </button>
            <button type="button" class="purchase-type-btn" onclick="selectPurchaseType(this, 'existing')">
                📦 Ce l'avevo già
            </button>
        </div>
        <div id="expiry-detail" class="expiry-detail">
            <div class="expiry-estimate">
                <span class="expiry-estimate-label">Scadenza stimata: <strong>${estimateLabel}${expirySuffix}</strong></span>
                <span class="expiry-estimate-date">${formatDate(estimatedDate)}</span>
            </div>
            <div class="expiry-input-row">
                <input type="date" id="add-expiry" class="form-input" value="${estimatedDate}">
                <button type="button" class="btn btn-accent btn-scan-expiry" onclick="scanExpiryWithAI()" title="Scansiona data scadenza">📷</button>
            </div>
            <p class="form-hint">📝 Puoi modificare la data o scansionarla con la fotocamera</p>
        </div>
        <div id="multi-batch-section" style="display:${unit === 'conf' ? 'block' : 'none'}">
            <div id="multi-batch-container"></div>
            <button type="button" class="btn btn-outline btn-small full-width" style="margin-top:8px" onclick="addExpiryBatch()">
                📦 + Lotto con scadenza diversa
            </button>
        </div>
    `;
    
    showPage('add');
    // After rendering, fetch history-based expiry prediction
    if (currentProduct && currentProduct.id) {
        _fetchExpiryHistoryAndUpdate(currentProduct.id);
    }
}

function toggleVacuumSealed() {
    const cb = document.getElementById('add-vacuum-sealed');
    if (cb) cb.checked = !cb.checked;
    onVacuumSealedChange();
}

function onVacuumSealedChange() {
    const hint = document.getElementById('add-vacuum-hint');
    if (hint) hint.style.display = document.getElementById('add-vacuum-sealed')?.checked ? 'block' : 'none';
    recalculateAddExpiry();
}

function recalculateAddExpiry() {
    if (!currentProduct) return;
    const loc = document.getElementById('add-location')?.value || '';
    const isVacuum = document.getElementById('add-vacuum-sealed')?.checked;
    
    const baseDays = window._historyExpiryDays ?? estimateExpiryDays(currentProduct, loc);
    let days = isVacuum ? getVacuumExpiryDays(baseDays) : baseDays;
    
    window._addBaseExpiryDays = baseDays;
    
    const newDate = addDays(days);
    const newLabel = formatEstimatedExpiry(days);
    
    let suffix = '';
    if (window._historyExpiryDays) suffix = ' (da storico)';
    else if (loc === 'freezer' && isVacuum) suffix = ' (freezer + sotto vuoto)';
    else if (loc === 'freezer') suffix = ' (freezer)';
    else if (isVacuum) suffix = ' (sotto vuoto)';
    
    const expiryInput = document.getElementById('add-expiry');
    const estimateEl = document.querySelector('.expiry-estimate-label');
    const dateEl = document.querySelector('.expiry-estimate-date');
    if (expiryInput) expiryInput.value = newDate;
    if (estimateEl) estimateEl.innerHTML = `Scadenza stimata: <strong>${newLabel}${suffix}</strong>`;
    if (dateEl) dateEl.textContent = formatDate(newDate);
}

async function _fetchExpiryHistoryAndUpdate(productId) {
    try {
        const res = await fetch(`api/index.php?action=expiry_history&product_id=${encodeURIComponent(productId)}`);
        const data = await res.json();
        if (data.avg_days && data.avg_days > 0 && data.count >= 1) {
            window._historyExpiryDays = data.avg_days;
            window._historyExpiryCount = data.count;
            // Update the displayed date and label
            const loc = document.getElementById('add-location')?.value || '';
            const isVacuum = document.getElementById('add-vacuum-sealed')?.checked;
            let days = isVacuum ? getVacuumExpiryDays(data.avg_days) : data.avg_days;
            const newDate = addDays(days);
            const newLabel = formatEstimatedExpiry(days);
            const suffix = ` <span class="history-badge" title="Media da ${data.count} insertiment${data.count === 1 ? 'o' : 'i'} precedent${data.count === 1 ? 'e' : 'i'}">📊 storico</span>`;
            const expiryInput = document.getElementById('add-expiry');
            const estimateEl = document.querySelector('.expiry-estimate-label');
            const dateEl = document.querySelector('.expiry-estimate-date');
            if (expiryInput) expiryInput.value = newDate;
            if (estimateEl) estimateEl.innerHTML = `Scadenza stimata: <strong>${newLabel}${suffix}</strong>`;
            if (dateEl) dateEl.textContent = formatDate(newDate);
            window._addBaseExpiryDays = data.avg_days;
        }
    } catch (e) {
        // silently fall back to rule-based estimate
    }
}

function getVacuumExpiryDays(baseDays) {
    // Vacuum sealing extends shelf life significantly
    if (baseDays <= 7) return Math.round(baseDays * 3);       // very fresh: 3x (e.g., 3→9, 7→21)
    if (baseDays <= 14) return Math.round(baseDays * 3);       // fresh cheese/dairy: 3x (10→30)
    if (baseDays <= 30) return Math.round(baseDays * 2.5);     // short: 2.5x (e.g., 21→52)
    if (baseDays <= 90) return Math.round(baseDays * 2.5);     // medium (cheese ~60d): 2.5x (60→150)
    return Math.round(baseDays * 1.5);                         // long-lasting: 1.5x
}

function onAddUnitChange() {
    updateAddQtyStep();
    const unit = document.getElementById('add-unit').value;
    const qtyInput = document.getElementById('add-quantity');
    
    // Show/hide conf size row
    const confRow = document.getElementById('add-conf-size-row');
    if (confRow) {
        const isConf = unit === 'conf';
        confRow.style.display = isConf ? 'block' : 'none';
        // Pre-fill from currentProduct if available
        if (isConf && currentProduct) {
            const sizeInput = document.getElementById('add-conf-size');
            const unitSelect = document.getElementById('add-conf-unit');
            if (currentProduct.package_unit && currentProduct.default_quantity > 1) {
                sizeInput.value = currentProduct.default_quantity;
                unitSelect.value = currentProduct.package_unit;
            } else if (['g', 'ml', 'kg', 'l'].includes(currentProduct.unit) && currentProduct.default_quantity > 0) {
                // Product was defined in weight/volume — that quantity IS the package size
                sizeInput.value = currentProduct.default_quantity;
                unitSelect.value = currentProduct.unit;
            } else {
                sizeInput.value = '';
                unitSelect.value = 'g';
            }
        }
        // Scroll into view so the user sees the new field
        if (isConf) setTimeout(() => confRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 100);
    }

    // Show/hide multi-batch section (only for conf unit)
    const mbSection = document.getElementById('multi-batch-section');
    if (mbSection) mbSection.style.display = unit === 'conf' ? 'block' : 'none';
    
    // If switching units, suggest a sensible quantity
    // BUT only if the user hasn't manually changed the quantity in this form
    if (qtyInput.dataset.manuallySet === 'true') return; // User already edited qty, don't overwrite
    
    const currentQty = parseFloat(qtyInput.value) || 1;
    
    // Convert between related units if logical
    if (unit === 'g' && currentQty <= 10) qtyInput.value = currentProduct.weight_info ? parseFloat(currentProduct.weight_info) || 250 : 250;
    if (unit === 'ml' && currentQty <= 10) qtyInput.value = 500;
    if (unit === 'pz' && currentQty > 100) qtyInput.value = 1;
    if (unit === 'conf' && currentQty > 10) qtyInput.value = 1;
}

function updateAddQtyStep() {
    const qtyInput = document.getElementById('add-quantity');
    const unit = document.getElementById('add-unit').value;
    qtyInput.step = 'any';
    if (unit === 'g' || unit === 'ml') {
        qtyInput.min = '1';
    } else {
        qtyInput.min = '1';
    }
}

function markAddQtyManuallySet() {
    document.getElementById('add-quantity').dataset.manuallySet = 'true';
}

function adjustAddQty(delta) {
    const qtyInput = document.getElementById('add-quantity');
    qtyInput.dataset.manuallySet = 'true'; // +/- buttons count as manual edit
    const unit = document.getElementById('add-unit').value;
    let val = parseFloat(qtyInput.value) || 0;
    let step;
    if (unit === 'g' || unit === 'ml') {
        step = val < 50 ? 1 : (val < 500 ? 10 : 50);
    } else {
        step = 1;
    }
    val = Math.max(parseFloat(qtyInput.min) || 0.1, val + delta * step);
    // Round nicely
    if (step >= 1) val = Math.round(val);
    else val = Math.round(val * 10) / 10;
    qtyInput.value = val;
}

function selectPurchaseType(btn, type) {
    btn.parentElement.querySelectorAll('.purchase-type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // Reset extra batches when switching purchase type
    window._addExtraBatches = [];
    const mbContainer = document.getElementById('multi-batch-container');
    if (mbContainer) mbContainer.innerHTML = '';

    const detailDiv = document.getElementById('expiry-detail');
    
    // Save current quantity before switching, so we can preserve it
    const currentQty = document.getElementById('add-quantity').value;
    
    if (type === 'new') {
        // Recalculate fresh expiry based on current location/vacuum
        const loc = document.getElementById('add-location')?.value || '';
        const isVacuum = document.getElementById('add-vacuum-sealed')?.checked;
        const baseDays = window._historyExpiryDays ?? estimateExpiryDays(currentProduct, loc);
        let days = isVacuum ? getVacuumExpiryDays(baseDays) : baseDays;
        const estimatedDate = addDays(days);
        const estimateLabel = formatEstimatedExpiry(days);
        let suffix = '';
        if (window._historyExpiryDays) suffix = ` <span class="history-badge" title="Media da ${window._historyExpiryCount} inserimento/i precedente/i">📊 storico</span>`;
        else if (loc === 'freezer' && isVacuum) suffix = ' (freezer + sotto vuoto)';
        else if (loc === 'freezer') suffix = ' (freezer)';
        else if (isVacuum) suffix = ' (sotto vuoto)';
        
        detailDiv.innerHTML = `
            <div class="expiry-estimate">
                <span class="expiry-estimate-label">Scadenza stimata: <strong>${estimateLabel}${suffix}</strong></span>
                <span class="expiry-estimate-date">${formatDate(estimatedDate)}</span>
            </div>
            <div class="expiry-input-row">
                <input type="date" id="add-expiry" class="form-input" value="${estimatedDate}">
                <button type="button" class="btn btn-accent btn-scan-expiry" onclick="scanExpiryWithAI()" title="Scansiona data scadenza">📷</button>
            </div>
            <p class="form-hint">📝 Puoi modificare la data o scansionarla con la fotocamera</p>
        `;
        // Restore quantity - switching purchase type should NOT change it
        document.getElementById('add-quantity').value = currentQty;
        // Show multi-batch section only in "new" mode (and only for conf unit)
        const mbSection = document.getElementById('multi-batch-section');
        if (mbSection) mbSection.style.display = (document.getElementById('add-unit')?.value === 'conf') ? 'block' : 'none';
    } else {
        detailDiv.innerHTML = `
            <div class="form-group">
                <label>📅 Quando scade?</label>
                <div class="expiry-input-row">
                    <input type="date" id="add-expiry" class="form-input" value="">
                    <button type="button" class="btn btn-accent btn-scan-expiry" onclick="scanExpiryWithAI()" title="Scansiona data scadenza">📷</button>
                </div>
                <p class="form-hint">Inserisci la data di scadenza o scansionala</p>
            </div>
            <div class="form-group">
                <label>📦 Quantità rimasta</label>
                <p class="form-hint" style="margin-bottom:6px">Quanto è rimasto approssimativamente?</p>
                <div class="remaining-options">
                    <button type="button" class="remaining-btn" onclick="setRemainingPct(1)">🟢 Pieno</button>
                    <button type="button" class="remaining-btn" onclick="setRemainingPct(0.75)">🟡 ¾</button>
                    <button type="button" class="remaining-btn" onclick="setRemainingPct(0.5)">🟠 Metà</button>
                    <button type="button" class="remaining-btn" onclick="setRemainingPct(0.25)">🔴 ¼</button>
                </div>
            </div>
        `;
        // DON'T auto-set remaining percentage - keep the quantity the user already entered
        // Hide multi-batch section in "existing" mode
        const mbSection = document.getElementById('multi-batch-section');
        if (mbSection) mbSection.style.display = 'none';
    }
}

function setRemainingPct(pct) {
    document.querySelectorAll('.remaining-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');
    const baseQty = currentProduct.default_quantity || 1;
    const unit = currentProduct.unit || 'pz';
    let adjustedQty;
    if (unit === 'pz' || unit === 'conf') {
        adjustedQty = Math.max(1, Math.round(baseQty * pct));
    } else {
        adjustedQty = Math.round(baseQty * pct * 10) / 10;
    }
    document.getElementById('add-quantity').value = adjustedQty;
}

// ===== MULTI-EXPIRY BATCHES (for conf products with different expiry dates) =====
window._addExtraBatches = [];

function addExpiryBatch() {
    const loc = document.getElementById('add-location')?.value || '';
    const baseDays = window._historyExpiryDays ?? estimateExpiryDays(currentProduct, loc);
    const estimatedDate = addDays(baseDays);
    window._addExtraBatches.push({ qty: 1, expiry: estimatedDate });
    _rebuildMultiBatchUI();
}

function removeExpiryBatch(i) {
    window._addExtraBatches.splice(i, 1);
    _rebuildMultiBatchUI();
}

function adjustBatchQty(i, delta) {
    window._addExtraBatches[i].qty = Math.max(1, (window._addExtraBatches[i].qty || 1) + delta);
    _rebuildMultiBatchUI();
}

function _rebuildMultiBatchUI() {
    const container = document.getElementById('multi-batch-container');
    if (!container) return;
    if (window._addExtraBatches.length === 0) {
        container.innerHTML = '';
        return;
    }
    container.innerHTML = window._addExtraBatches.map((b, i) => `
        <div class="multi-batch-row">
            <div class="multi-batch-qty">
                <button type="button" class="qty-btn" onclick="adjustBatchQty(${i}, -1)">−</button>
                <input type="number" class="qty-input" value="${b.qty}" min="1" step="1" style="width:60px"
                    onchange="window._addExtraBatches[${i}].qty = parseInt(this.value)||1">
                <button type="button" class="qty-btn" onclick="adjustBatchQty(${i}, 1)">+</button>
                <span class="multi-batch-unit">conf</span>
            </div>
            <input type="date" class="form-input multi-batch-date" value="${b.expiry}"
                onchange="window._addExtraBatches[${i}].expiry = this.value">
            <button type="button" class="btn-icon-sm" onclick="removeExpiryBatch(${i})" title="Rimuovi">✕</button>
        </div>
    `).join('');
}

function selectLocation(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('add-location').value = loc;
    recalculateAddExpiry();
}

async function submitAdd(e) {
    e.preventDefault();
    showLoading(true);
    
    try {
        const selectedUnit = document.getElementById('add-unit').value;
        const productUnit = currentProduct.unit || 'pz';
        
        // Validate conf fields
        if (selectedUnit === 'conf') {
            const confSize = parseFloat(document.getElementById('add-conf-size')?.value);
            if (!confSize || confSize <= 0) {
                showLoading(false);
                showToast('Specifica il contenuto di ogni confezione', 'error');
                document.getElementById('add-conf-size')?.focus();
                return;
            }
        }
        
        const result = await api('inventory_add', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: parseFloat(document.getElementById('add-quantity').value) || 1,
            location: document.getElementById('add-location').value,
            expiry_date: document.getElementById('add-expiry').value || null,
            unit: selectedUnit !== productUnit ? selectedUnit : null,
            package_unit: selectedUnit === 'conf' ? (document.getElementById('add-conf-unit')?.value || null) : null,
            package_size: selectedUnit === 'conf' ? (parseFloat(document.getElementById('add-conf-size')?.value) || null) : null,
            vacuum_sealed: document.getElementById('add-vacuum-sealed')?.checked ? 1 : 0,
        });
        
        showLoading(false);
        if (result.success) {
            // Build quantity info for toast
            let qtyInfo = '';
            if (result.total_qty) {
                const u = result.unit || 'pz';
                const unitLabels = { 'pz': 'pz', 'g': 'g', 'ml': 'ml', 'conf': 'conf' };
                const uLabel = unitLabels[u] || u;
                if (u === 'conf' && result.package_unit && result.default_quantity > 0) {
                    const pkgLabel = unitLabels[result.package_unit] || result.package_unit;
                    qtyInfo = ` (totale: ${result.total_qty} ${uLabel} da ${result.default_quantity}${pkgLabel})`;
                } else {
                    qtyInfo = ` (totale: ${result.total_qty} ${uLabel})`;
                }
            }
            showToast(`✅ ${currentProduct.name} aggiunto!${qtyInfo}`, 'success');
            if (result.removed_from_bring) {
                setTimeout(() => showToast('🛒 Rimosso dalla lista della spesa', 'info'), 1500);
            } else if (shoppingItems.length > 0 && shoppingListUUID) {
                // PHP matching may have missed the item (custom name / no catalog match) —
                // try a client-side fuzzy remove using the already-loaded shoppingItems
                const match = _findSimilarItem(currentProduct.name, shoppingItems);
                if (match) {
                    api('bring_remove', {}, 'POST', {
                        name: match.name,
                        rawName: match.rawName || '',
                        listUUID: shoppingListUUID
                    }).then(r => {
                        if (r && r.success) {
                            shoppingItems = shoppingItems.filter(i => i !== match);
                            setTimeout(() => showToast('🛒 Rimosso dalla lista della spesa', 'info'), 1500);
                        }
                    }).catch(() => {});
                }
            }
            if (!spesaModeAfterAdd()) showPage('dashboard');

            // Submit extra batches (different expiry dates) in the background, silently
            if ((window._addExtraBatches || []).length > 0) {
                const loc = document.getElementById('add-location')?.value || result.location || 'dispensa';
                const selectedUnit = document.getElementById('add-unit').value;
                const productUnit = currentProduct.unit || 'pz';
                const confUnit = document.getElementById('add-conf-unit')?.value || null;
                const confSize = parseFloat(document.getElementById('add-conf-size')?.value) || null;
                for (const batch of window._addExtraBatches) {
                    if (!batch.qty || batch.qty <= 0) continue;
                    api('inventory_add', {}, 'POST', {
                        product_id: currentProduct.id,
                        quantity: batch.qty,
                        location: loc,
                        expiry_date: batch.expiry || null,
                        unit: selectedUnit !== productUnit ? selectedUnit : null,
                        package_unit: selectedUnit === 'conf' ? confUnit : null,
                        package_size: selectedUnit === 'conf' ? confSize : null,
                    }).catch(() => {});
                }
                window._addExtraBatches = [];
            }
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast('Errore di connessione', 'error');
    }
}

// ===== USE FROM INVENTORY =====
function showUseForm() {
    renderUsePreview();
    _useConfMode = null; // reset
    document.getElementById('use-quantity').value = 1;
    document.getElementById('use-location').value = 'dispensa';
    document.getElementById('use-unit-switch').style.display = 'none';

    
    // Reset location buttons
    document.querySelectorAll('#page-use .loc-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('#page-use .loc-btn').classList.add('active');
    
    loadUseInventoryInfo();
    showPage('use');
}

function renderUsePreview() {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(currentProduct?.category, currentProduct?.name)] || '📦';
    document.getElementById('use-product-preview').innerHTML = `
        ${currentProduct?.image_url ?
            `<img src="${escapeHtml(currentProduct.image_url)}" alt="">` :
            `<span style="font-size:2rem">${catIcon}</span>`
        }
        <div class="product-preview-info">
            <h3>${escapeHtml(currentProduct?.name || '')}</h3>
            <p>${currentProduct?.brand ? escapeHtml(currentProduct.brand) : ''}</p>
        </div>
    `;
}

// Conf-mode tracking for USE form
let _useConfMode = null; // null = normal, { packageSize, packageUnit, totalSub, unit } = conf mode active
let _useNormalUnit = 'pz'; // unit when not in conf mode

async function loadUseInventoryInfo() {
    try {
        const data = await api('inventory_list');
        const items = (data.inventory || []).filter(i => i.product_id == currentProduct.id);
        const infoEl = document.getElementById('use-inventory-info');
        const unitSwitch = document.getElementById('use-unit-switch');
        
        if (items.length === 0) {
            infoEl.innerHTML = '⚠️ Prodotto non presente nell\'inventario.';
            unitSwitch.style.display = 'none';
            _useConfMode = null;
            return;
        }

        // Auto-select the location with an opened package first (use from opened before sealed)
        const openedItem = items.find(i => {
            const q = parseFloat(i.quantity);
            const dq = parseFloat(i.default_quantity) || 0;
            if (i.unit === 'conf' && dq > 0) return q !== Math.floor(q);
            if (dq > 0) return Math.abs(q - Math.round(q / dq) * dq) > dq * 0.02;
            return false;
        });
        const firstLoc = openedItem ? openedItem.location : items[0].location;
        document.getElementById('use-location').value = firstLoc;
        
        // Build location buttons only for locations where the product exists
        const productLocations = [...new Set(items.map(i => i.location))];
        const locSelector = document.getElementById('use-location-selector');
        locSelector.innerHTML = productLocations.map(loc => {
            const locInfo = LOCATIONS[loc] || { icon: '📦', label: loc };
            const locItems = items.filter(i => i.location === loc);
            const locQty = locItems.reduce((s, i) => s + parseFloat(i.quantity), 0);
            const u = locItems[0].unit || 'pz';
            const qtyLabel = formatQuantity(locQty, u, locItems[0].default_quantity, locItems[0].package_unit);
            return `<button type="button" class="loc-btn ${loc === firstLoc ? 'active' : ''}" onclick="selectUseLocation(this, '${loc}')">${locInfo.icon} ${locInfo.label} (${qtyLabel})</button>`;
        }).join('');


        const unit = items[0].unit || 'pz';
        const pkgSize = parseFloat(items[0].default_quantity) || 0;
        const pkgUnit = items[0].package_unit || '';
        const isConf = unit === 'conf' && pkgSize > 0 && pkgUnit;

        if (isConf) {
            // --- CONF MODE: show sub-unit controls ---
            const totalConf = items.reduce((s, i) => s + parseFloat(i.quantity), 0);
            const totalSub = totalConf * pkgSize;
            const unitLabels = { 'ml': 'ml', 'g': 'g', 'pz': 'pz' };
            const subLabel = unitLabels[pkgUnit] || pkgUnit;

            _useConfMode = { packageSize: pkgSize, packageUnit: pkgUnit, totalSub, totalConf, subLabel };

            // Show inventory info with sub-unit total
            infoEl.innerHTML = '<strong>📦 Disponibile:</strong> ' + items.map(i => {
                const loc = LOCATIONS[i.location] || { icon: '📦', label: i.location };
                const confQty = parseFloat(i.quantity);
                const subQty = Math.round(confQty * pkgSize);
                const confDisplay = confQty === Math.floor(confQty) ? Math.floor(confQty) : confQty.toFixed(1);
                return `${loc.icon} ${loc.label}: ${confDisplay} conf (${subQty}${subLabel})`;
            }).join(' · ');

            // Show unit switch
            unitSwitch.style.display = 'flex';
            document.getElementById('use-unit-sub').textContent = subLabel;
            
            // Default to sub-unit mode
            switchUseUnit('sub');
        } else {
            // --- NORMAL MODE ---
            _useConfMode = null;
            _useNormalUnit = unit;
            unitSwitch.style.display = 'none';
            
            infoEl.innerHTML = '<strong>📦 Disponibile:</strong> ' + items.map(i => {
                const loc = LOCATIONS[i.location] || { icon: '📦', label: i.location };
                return `${loc.icon} ${loc.label}: ${i.quantity} ${i.unit}`;
            }).join(' · ');
            
            const qtyInput = document.getElementById('use-quantity');
            qtyInput.value = 1;
            qtyInput.step = 'any';
            qtyInput.min = (unit === 'g' || unit === 'ml') ? '1' : '1';
            document.getElementById('use-partial-hint').textContent = 'Oppure specifica la quantità usata:';
        }
    } catch(e) {
        console.error(e);
    }
}

function switchUseUnit(mode) {
    const subBtn = document.getElementById('use-unit-sub');
    const confBtn = document.getElementById('use-unit-conf');
    const qtyInput = document.getElementById('use-quantity');
    const hint = document.getElementById('use-partial-hint');

    if (mode === 'sub') {
        subBtn.classList.add('active');
        confBtn.classList.remove('active');
        _useConfMode._activeUnit = 'sub';
        const step = getSubUnitStep(_useConfMode.packageUnit);
        qtyInput.value = step;
        qtyInput.step = step;
        qtyInput.min = step;
        hint.textContent = `Quantità in ${_useConfMode.subLabel} (totale: ${Math.round(_useConfMode.totalSub)}${_useConfMode.subLabel})`;
    } else {
        confBtn.classList.add('active');
        subBtn.classList.remove('active');
        _useConfMode._activeUnit = 'conf';
        qtyInput.value = 1;
        qtyInput.step = 0.5;
        qtyInput.min = 0.5;
        hint.textContent = `Confezioni da ${_useConfMode.packageSize}${_useConfMode.subLabel} (hai ${_useConfMode.totalConf.toFixed(1)} conf)`;
    }
}

function getSubUnitStep(pkgUnit) {
    switch (pkgUnit) {
        case 'ml': return 50;
        case 'g': return 10;
        default: return 1;
    }
}

function adjustUseQty(direction) {
    const input = document.getElementById('use-quantity');
    let val = parseFloat(input.value) || 0;
    let step;
    if (_useConfMode && _useConfMode._activeUnit === 'sub') {
        step = getSubUnitStep(_useConfMode.packageUnit);
    } else if (_useConfMode && _useConfMode._activeUnit === 'conf') {
        step = 0.5;
    } else {
        // Unit-aware step for normal mode
        const u = _useNormalUnit || 'pz';
        if (u === 'g' || u === 'ml') {
            step = val < 50 ? 1 : (val < 500 ? 10 : 50);
        } else {
            step = 1;
        }
    }
    val = Math.max(step, val + direction * step);
    input.value = Math.round(val * 1000) / 1000;
}

function selectUseLocation(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('use-location').value = loc;
}

// ===== LOW STOCK → BRING! PROMPT =====
function isLowStock(totalRemaining, unit, defaultQty) {
    if (totalRemaining <= 0) return true; // fully depleted → definitely needs restocking
    if (unit === 'pz') return totalRemaining <= 2;
    if (unit === 'conf') return totalRemaining <= 1;
    // Weight/volume: use percentage of default_qty or fixed threshold
    if (defaultQty > 0) return totalRemaining <= defaultQty * 0.25;
    // Fallback fixed thresholds
    if (unit === 'g' || unit === 'ml') return totalRemaining <= 100;
    return false;
}

/**
 * Return the significant tokens of a product name for similarity matching.
 * Strips stopwords and short tokens.
 */
function _nameTokens(name) {
    const stop = new Set(['di','del','della','dei','degli','delle','da','in','con','per','su','a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli','allo']);
    return (name || '').toLowerCase()
        .replace(/[^a-z\u00c0-\u024f\s]/gi, ' ')
        .split(/\s+/)
        .filter(t => t.length > 2 && !stop.has(t));
}

/**
 * Check whether `name` matches any item in `list` (array of {name}).
 * Returns the matching item or null.
 * A match = at least one significant token in common.
 * NOTE: intentionally loose — use _matchBringToSmart for display/urgency matching.
 */
function _findSimilarItem(name, list) {
    const tokens = _nameTokens(name);
    if (tokens.length === 0) return null;
    return (list || []).find(item => {
        const iTokens = _nameTokens(item.name || '');
        return tokens.some(t => iTokens.includes(t));
    }) || null;
}

/**
 * Strict matching: find the smart item that corresponds to a Bring item by name.
 * Rules (in order):
 *  1. Exact case-insensitive match.
 *  2. First significant token of both names must be identical
 *     ("Latte" → "Latte Parzialmente Scremato" ✓; "Frutta" ≠ "Muesli Frutta Secca" ✗).
 *  3. For multi-token Bring names: all Bring tokens appear in the smart item tokens.
 * This avoids false positives when a generic word ("frutta", "noci") appears as a
 * secondary word inside an unrelated long product name.
 */
function _matchBringToSmart(bringName, smartItems) {
    const bLower = bringName.toLowerCase();
    const exact = smartItems.find(sd => sd.name.toLowerCase() === bLower);
    if (exact) return exact;
    const bTokens = _nameTokens(bringName);
    if (bTokens.length === 0) return null;
    const bFirst = bTokens[0];
    // Rule 2: first token match
    const firstMatch = smartItems.find(sd => {
        const sdTokens = _nameTokens(sd.name);
        return sdTokens.length > 0 && sdTokens[0] === bFirst;
    });
    if (firstMatch) return firstMatch;
    // Rule 3: multi-token full subset
    if (bTokens.length >= 2) {
        const allMatch = smartItems.find(sd => {
            const sdTokens = _nameTokens(sd.name);
            return bTokens.every(t => sdTokens.includes(t));
        });
        if (allMatch) return allMatch;
    }
    return null;
}

function showLowStockBringPrompt(result, afterCallback) {
    const name = result.product_name || currentProduct?.name || '';
    const unit = result.product_unit || currentProduct?.unit || 'pz';
    const defaultQty = result.product_default_qty || parseFloat(currentProduct?.default_quantity) || 0;
    const totalRemaining = result.total_remaining;
    
    if (!isLowStock(totalRemaining, unit, defaultQty)) {
        if (afterCallback) afterCallback();
        return;
    }
    
    // Format remaining for display
    let remainLabel = '';
    if (unit === 'conf' && result.product_package_unit) {
        const subTotal = Math.round(totalRemaining * defaultQty);
        remainLabel = `${subTotal}${result.product_package_unit}`;
    } else {
        const unitLabels = { pz: 'pz', g: 'g', ml: 'ml', conf: 'conf' };
        remainLabel = `${Number.isInteger(totalRemaining) ? totalRemaining : totalRemaining.toFixed(1)} ${unitLabels[unit] || unit}`;
    }

    // --- Deduplication check ---
    // 1. Already on Bring! list (shoppingItems)?
    const alreadyOnBring = _findSimilarItem(name, shoppingItems);
    if (alreadyOnBring) {
        // Already present (same or similar item). Just inform and continue.
        showToast(`🛒 "${escapeHtml(alreadyOnBring.name)}" già nella lista della spesa`, 'info');
        if (afterCallback) afterCallback();
        return;
    }

    // 2. In smart shopping predictions?
    const smartMatch = _findSimilarItem(name, smartShoppingItems);
    const smartUrgencyLabel = {
        critical: '🔴 Urgente', high: '🟠 Presto', medium: '🟡 Pianifica', low: '🟢 Previsione'
    };
    let smartNote = '';
    if (smartMatch) {
        const lbl = smartUrgencyLabel[smartMatch.urgency] || '';
        smartNote = `<div style="margin-bottom:12px;padding:8px 10px;background:rgba(249,115,22,0.1);border-radius:8px;border-left:3px solid #f97316;font-size:0.85rem">
            📊 La spesa intelligente prevede già <strong>${escapeHtml(smartMatch.name)}</strong>${lbl ? ` (${lbl})` : ''}.
        </div>`;
    }

    // Build specification from product name for Bring
    window._lowStockAfterCallback = afterCallback;
    window._lowStockSpec = name;
    
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>⚠️ Sta per finire!</h3>
            <button class="modal-close" onclick="closeLowStockPrompt()">✕</button>
        </div>
        <div style="padding:0 16px 16px">
            <p style="margin-bottom:12px"><strong>${escapeHtml(name)}</strong> sta per finire — rimangono solo <strong>${remainLabel}</strong>.</p>
            ${smartNote}
            <p style="margin-bottom:16px">Vuoi aggiungerlo alla lista della spesa?</p>
            <button type="button" class="btn btn-large btn-success full-width" onclick="addLowStockToBring('${escapeHtml(name).replace(/'/g, "\\'")}')">
                🛒 Sì, aggiungi a Bring!
            </button>
            <button type="button" class="btn btn-secondary full-width" style="margin-top:8px" onclick="closeLowStockPrompt()">
                No, per ora va bene
            </button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

async function addLowStockToBring(productName) {
    closeModal();
    try {
        const spec = window._lowStockSpec || '';
        window._lowStockSpec = null;
        const payload = { items: [{ name: productName, specification: spec }] };
        if (shoppingListUUID) payload.listUUID = shoppingListUUID;
        const data = await api('bring_add', {}, 'POST', payload);
        if (data.success && data.added > 0) {
            showToast('🛒 Aggiunto alla lista della spesa!', 'success');
        } else if (data.success && data.skipped > 0) {
            showToast('ℹ️ Già nella lista della spesa', 'info');
        }
    } catch (e) {
        showToast('Errore nell\'aggiunta a Bring!', 'error');
    }
    const cb = window._lowStockAfterCallback;
    window._lowStockAfterCallback = null;
    if (cb) cb();
}

function closeLowStockPrompt() {
    closeModal();
    const cb = window._lowStockAfterCallback;
    window._lowStockAfterCallback = null;
    if (cb) cb();
}

let _moveModalTimer = null;
let _moveModalRAF = null;

function clearMoveModalTimer() {
    if (_moveModalTimer) { clearTimeout(_moveModalTimer); _moveModalTimer = null; }
    if (_moveModalRAF) { cancelAnimationFrame(_moveModalRAF); _moveModalRAF = null; }
}

function startMoveModalCountdown(btnId, onExpire) {
    clearMoveModalTimer();
    const duration = 15000;
    const start = performance.now();
    const btn = document.getElementById(btnId);
    if (!btn) return;
    function tick() {
        const elapsed = performance.now() - start;
        const pct = Math.max(0, 100 - (elapsed / duration) * 100);
        btn.style.background = `linear-gradient(to right, rgba(45,80,22,0.2) ${pct}%, transparent ${pct}%)`;
        if (elapsed < duration) {
            _moveModalRAF = requestAnimationFrame(tick);
        }
    }
    _moveModalRAF = requestAnimationFrame(tick);
    _moveModalTimer = setTimeout(() => {
        clearMoveModalTimer();
        onExpire();
    }, duration);
}

function showMoveAfterUseModal(product, fromLoc, remaining, openedId) {
    const otherLocs = Object.entries(LOCATIONS).filter(([k]) => k !== fromLoc);
    const locButtons = otherLocs.map(([k, v]) =>
        `<button type="button" class="loc-btn" onclick="clearMoveModalTimer();confirmMoveAfterUse(${product.id}, '${fromLoc}', '${k}', ${openedId || 0})">${v.icon} ${v.label}</button>`
    ).join('');
    
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>📦 Spostare il resto?</h3>
            <button class="modal-close" onclick="clearMoveModalTimer();closeModal();showPage('dashboard')">✕</button>
        </div>
        <div style="padding:0 16px 16px">
            <p style="margin-bottom:12px">Vuoi spostare ${openedId ? 'la confezione aperta' : 'il resto'} di <strong>${escapeHtml(product.name)}</strong> in un'altra posizione?</p>
            <div class="location-selector">${locButtons}</div>
            <button type="button" id="btn-move-stay" class="btn btn-secondary full-width move-countdown-btn" style="margin-top:12px" onclick="clearMoveModalTimer();closeModal();showPage('dashboard')">No, resta in ${LOCATIONS[fromLoc]?.label || fromLoc}</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    startMoveModalCountdown('btn-move-stay', () => { closeModal(); showPage('dashboard'); });
}

async function confirmMoveAfterUse(productId, fromLoc, toLoc, openedId) {
    clearMoveModalTimer();
    closeModal();
    showLoading(true);
    try {
        if (openedId) {
            // Move only the specific opened row — use opened shelf life
            const product = { name: currentProduct?.name || '', category: currentProduct?.category || '' };
            let days = estimateOpenedExpiryDays(product, toLoc);
            await api('inventory_update', {}, 'POST', {
                id: openedId,
                location: toLoc,
                expiry_date: addDays(days),
                product_id: productId,
            });
            showToast(`📦 Confezione aperta spostata in ${LOCATIONS[toLoc]?.label || toLoc}`, 'success');
        } else {
            // Legacy: move whatever is at fromLoc
            const data = await api('inventory_list');
            const item = (data.inventory || []).find(i => i.product_id == productId && i.location === fromLoc && parseFloat(i.quantity) > 0);
            if (item) {
                const product = { name: item.name || '', category: item.category || '' };
                let days = estimateExpiryDays(product, toLoc);
                if (item.vacuum_sealed) days = getVacuumExpiryDays(days);
                await api('inventory_update', {}, 'POST', {
                    id: item.id,
                    location: toLoc,
                    expiry_date: addDays(days),
                    product_id: productId,
                });
                showToast(`📦 Spostato in ${LOCATIONS[toLoc]?.label || toLoc}`, 'success');
            }
        }
    } catch (e) {
        console.error('Move error:', e);
    }
    showLoading(false);
    showPage('dashboard');
}

async function submitUseAll() {
    showLoading(true);
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            use_all: true,
            location: '__all__',
        });
        showLoading(false);
        if (result.success) {
            showToast(`📤 ${currentProduct.name} terminato!`, 'success');
            if (result.added_to_bring) {
                setTimeout(() => showToast('🛒 Prodotto finito → aggiunto a Bring!', 'info'), 1500);
            }
            // Check low stock (product may exist at other locations)
            showLowStockBringPrompt(result, () => showPage('dashboard'));
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast('Errore di connessione', 'error');
    }
}

async function submitUse(e) {
    e.preventDefault();
    showLoading(true);
    try {
        let qty = parseFloat(document.getElementById('use-quantity').value) || 1;
        let displayQty = qty;
        let displayUnit = '';
        
        // Convert sub-unit to conf if needed
        if (_useConfMode && _useConfMode._activeUnit === 'sub') {
            displayUnit = _useConfMode.subLabel;
            qty = qty / _useConfMode.packageSize; // convert to conf
        } else if (_useConfMode && _useConfMode._activeUnit === 'conf') {
            displayUnit = 'conf';
        }
        
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: qty,
            location: document.getElementById('use-location').value,
        });
        showLoading(false);
        if (result.success) {
            const usedText = displayUnit ? `${displayQty}${displayUnit}` : displayQty;
            showToast(`📤 Usato ${usedText} di ${currentProduct.name}`, 'success');
            if (result.added_to_bring) {
                setTimeout(() => showToast('🛒 Prodotto finito → aggiunto a Bring!', 'info'), 1500);
            }
            // If there's remaining quantity, offer to move to another location
            const usedFrom = document.getElementById('use-location').value;
            const moveCallback = result.remaining > 0
                ? () => showMoveAfterUseModal(currentProduct, usedFrom, result.remaining, result.opened_id)
                : () => showPage('dashboard');
            // Check low stock → Bring! prompt
            showLowStockBringPrompt(result, moveCallback);
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast('Errore di connessione', 'error');
    }
}

// ===== AI IDENTIFICATION =====
async function captureForAI() {
    stopScanner();
    showPage('ai');
}

async function initAICamera() {
    const video = document.getElementById('ai-video');
    const captureDiv = document.getElementById('ai-capture');
    const previewDiv = document.getElementById('ai-preview');
    const captureBtn = document.getElementById('ai-capture-btn');
    const retakeBtn = document.getElementById('ai-retake-btn');
    const resultDiv = document.getElementById('ai-result');
    
    captureDiv.style.display = 'block';
    previewDiv.style.display = 'none';
    captureBtn.style.display = 'block';
    retakeBtn.style.display = 'none';
    resultDiv.style.display = 'none';
    
    try {
        if (aiStream) {
            aiStream.getTracks().forEach(t => t.stop());
        }
        aiStream = await navigator.mediaDevices.getUserMedia(getCameraConstraints());
        video.srcObject = aiStream;
        await video.play();
    } catch (err) {
        console.error('AI Camera error:', err);
        showToast('Impossibile accedere alla fotocamera', 'error');
    }
}

function takePhotoForAI() {
    const video = document.getElementById('ai-video');
    const canvas = document.getElementById('ai-canvas');
    const img = document.getElementById('ai-image');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);
    
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    img.src = dataUrl;
    
    // Stop camera
    if (aiStream) {
        aiStream.getTracks().forEach(t => t.stop());
        aiStream = null;
    }
    video.srcObject = null;
    
    document.getElementById('ai-capture').style.display = 'none';
    document.getElementById('ai-preview').style.display = 'block';
    document.getElementById('ai-capture-btn').style.display = 'none';
    document.getElementById('ai-retake-btn').style.display = 'block';

    // Immediately start analysis
    analyzeWithAI();
}

function retakePhotoAI() {
    document.getElementById('ai-result').style.display = 'none';
    initAICamera();
}

async function analyzeWithAI() {
    const resultDiv = document.getElementById('ai-result');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="text-align:center;padding:20px"><div class="loading-spinner" style="margin:0 auto 12px"></div><p>🤖 Identifico il prodotto...</p></div>';

    const canvas = document.getElementById('ai-canvas');
    const base64 = canvas.toDataURL('image/jpeg', 0.7).split(',')[1];

    try {
        const result = await api('gemini_identify', {}, 'POST', { image: base64 });

        if (!result.success) {
            if (result.error === 'no_api_key') {
                resultDiv.innerHTML = `<p style="color:var(--warning)">⚠️ Chiave API Gemini non configurata.<br><small>Aggiungi GEMINI_API_KEY nel file .env sul server.</small></p>`;
            } else {
                resultDiv.innerHTML = `<p style="color:var(--danger)">❌ ${escapeHtml(result.error || 'Errore nell\'identificazione')}</p>
                    <button class="btn btn-secondary full-width mt-2" onclick="retakePhotoAI()">🔄 Riprova</button>`;
            }
            return;
        }

        const id = result.identified;
        const matches = result.off_matches || [];

        let html = `<h4>🤖 Prodotto identificato</h4>`;
        html += `<div class="ai-identified-card">`;
        html += `<strong>${escapeHtml(id.name)}</strong>`;
        if (id.brand) html += ` <span style="color:var(--text-muted)">- ${escapeHtml(id.brand)}</span>`;
        if (id.description) html += `<p style="font-size:0.85rem;color:var(--text-light);margin:4px 0 0">${escapeHtml(id.description)}</p>`;
        html += `</div>`;

        if (matches.length > 0) {
            html += `<h4 style="margin-top:16px">📦 Prodotti corrispondenti</h4>`;
            html += `<div class="ai-matches-list">`;
            matches.forEach((m, idx) => {
                html += `<div class="ai-match-item" onclick="selectAIMatch(${idx})">`;
                if (m.image_url) {
                    html += `<img src="${m.image_url}" alt="" class="ai-match-img" onerror="this.style.display='none'">`;
                }
                html += `<div class="ai-match-info">`;
                html += `<strong>${escapeHtml(m.name)}</strong>`;
                if (m.brand) html += `<br><small>${escapeHtml(m.brand)}</small>`;
                if (m.quantity_info) html += `<br><small style="color:var(--text-muted)">${escapeHtml(m.quantity_info)}</small>`;
                html += `</div>`;
                html += `<span class="ai-match-barcode">${m.barcode}</span>`;
                html += `</div>`;
            });
            html += `</div>`;
        }

        // Option to save as-is without barcode
        html += `<div style="margin-top:16px; border-top: 1px solid var(--bg-light); padding-top: 12px">`;
        html += `<button class="btn btn-secondary full-width" onclick="saveAIProductDirect()">✏️ Salva senza barcode</button>`;
        html += `</div>`;

        resultDiv.innerHTML = html;

        // Store data for later use
        window._aiIdentified = id;
        window._aiMatches = matches;

    } catch (err) {
        console.error('AI identify error:', err);
        resultDiv.innerHTML = `<p style="color:var(--danger)">❌ Errore di connessione</p>
            <button class="btn btn-secondary full-width mt-2" onclick="retakePhotoAI()">🔄 Riprova</button>`;
    }
}

async function selectAIMatch(idx) {
    const match = window._aiMatches[idx];
    if (!match) return;

    showLoading(true);

    try {
        // Use the barcode to do a full lookup (gets all details)
        const localResult = await api('search_barcode', { barcode: match.barcode });
        if (localResult.found) {
            currentProduct = localResult.product;
            showLoading(false);
            showProductAction();
            return;
        }

        // Full lookup via OpenFoodFacts
        const lookupResult = await api('lookup_barcode', { barcode: match.barcode });
        if (lookupResult.found && lookupResult.product) {
            const p = lookupResult.product;
            const detected = detectUnitAndQuantity(p.quantity_info);

            const notesParts = [];
            if (p.quantity_info) notesParts.push(`Peso: ${p.quantity_info}`);
            if (p.nutriscore) notesParts.push(`Nutriscore: ${p.nutriscore.toUpperCase()}`);
            if (p.nova_group) notesParts.push(`NOVA: ${p.nova_group}`);
            if (p.ecoscore) notesParts.push(`Ecoscore: ${p.ecoscore.toUpperCase()}`);
            if (p.origin) notesParts.push(`Origine: ${p.origin}`);

            const saveResult = await api('product_save', {}, 'POST', {
                barcode: match.barcode,
                name: p.name || match.name,
                brand: p.brand || match.brand || '',
                category: p.category || '',
                image_url: p.image_url || match.image_url || '',
                unit: detected.unit,
                default_quantity: detected.quantity,
                notes: notesParts.join(' · '),
            });

            if (saveResult.id) {
                currentProduct = {
                    id: saveResult.id,
                    barcode: match.barcode,
                    name: p.name || match.name,
                    brand: p.brand || match.brand || '',
                    category: p.category || '',
                    image_url: p.image_url || match.image_url || '',
                    unit: detected.unit,
                    default_quantity: detected.quantity,
                    weight_info: p.quantity_info || '',
                };
                showLoading(false);
                showProductAction();
                return;
            }
        }

        // Fallback: save with basic info from match
        const saveResult = await api('product_save', {}, 'POST', {
            barcode: match.barcode,
            name: match.name,
            brand: match.brand || '',
            category: match.category || '',
            image_url: match.image_url || '',
            unit: 'pz',
            default_quantity: 1,
        });

        if (saveResult.id) {
            currentProduct = { id: saveResult.id, barcode: match.barcode, name: match.name, brand: match.brand || '', category: match.category || '', image_url: match.image_url || '', unit: 'pz', default_quantity: 1 };
            showLoading(false);
            showProductAction();
        } else {
            showLoading(false);
            showToast('Errore nel salvataggio', 'error');
        }
    } catch (err) {
        showLoading(false);
        console.error('AI match select error:', err);
        showToast('Errore di connessione', 'error');
    }
}

async function saveAIProductDirect() {
    const id = window._aiIdentified;
    if (!id) return;

    showLoading(true);
    try {
        const result = await api('product_save', {}, 'POST', {
            name: id.name,
            brand: id.brand || '',
            category: id.category || '',
            unit: 'pz',
            default_quantity: 1,
        });

        if (result.success || result.id) {
            currentProduct = { id: result.id, name: id.name, brand: id.brand || '', category: id.category || '', unit: 'pz', default_quantity: 1 };
            showLoading(false);
            showToast('Prodotto salvato!', 'success');
            showProductAction();
        } else {
            showLoading(false);
            showToast(result.error || 'Errore nel salvataggio', 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast('Errore di connessione', 'error');
    }
}

// ===== AI PHOTO FILL FOR PRODUCT FORM =====
let _pfAiStream = null;

async function captureForAIFormFill() {
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>📷 Identifica con AI</h3>
            <button class="modal-close" onclick="closePfAiScanner()">✕</button>
        </div>
        <div class="expiry-scanner">
            <div id="pfai-cam-container" style="position:relative;border-radius:10px;overflow:hidden;background:#000;aspect-ratio:4/3">
                <video id="pfai-video" autoplay playsinline style="width:100%;height:100%;object-fit:cover"></video>
                <canvas id="pfai-canvas" style="display:none"></canvas>
                <div style="position:absolute;inset:0;border:2px dashed rgba(255,255,255,0.4);border-radius:10px;pointer-events:none"></div>
            </div>
            <div id="pfai-preview-container" style="display:none;border-radius:10px;overflow:hidden;aspect-ratio:4/3">
                <img id="pfai-preview-img" src="" alt="" style="width:100%;height:100%;object-fit:cover">
            </div>
            <div id="pfai-status" style="display:none;text-align:center;padding:12px">
                <div class="loading-spinner" style="margin:0 auto 8px"></div>
                <p>🤖 Identifico il prodotto...</p>
            </div>
            <div id="pfai-result" style="display:none"></div>
            <p class="form-hint" style="text-align:center;margin:6px 0;font-size:0.8rem" id="pfai-hint">Inquadra l'etichetta del prodotto</p>
            <div style="display:flex;gap:8px;margin-top:8px">
                <button class="btn btn-large btn-accent" style="flex:1" id="pfai-capture-btn" onclick="pfAiCapture()">📸 Scatta</button>
                <button class="btn btn-large btn-secondary" style="flex:1;display:none" id="pfai-retake-btn" onclick="pfAiRetake()">🔄 Riscatta</button>
            </div>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';

    try {
        _pfAiStream = await navigator.mediaDevices.getUserMedia(getCameraConstraints());
        const video = document.getElementById('pfai-video');
        video.srcObject = _pfAiStream;
        await video.play();
    } catch (err) {
        document.getElementById('pfai-cam-container').innerHTML =
            `<p style="color:var(--danger);text-align:center;padding:20px">⚠️ Impossibile accedere alla fotocamera</p>`;
    }
}

function closePfAiScanner() {
    if (_pfAiStream) { _pfAiStream.getTracks().forEach(t => t.stop()); _pfAiStream = null; }
    closeModal();
}

function pfAiCapture() {
    const video = document.getElementById('pfai-video');
    const canvas = document.getElementById('pfai-canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('pfai-preview-img').src = dataUrl;

    if (_pfAiStream) { _pfAiStream.getTracks().forEach(t => t.stop()); _pfAiStream = null; }
    video.srcObject = null;

    document.getElementById('pfai-cam-container').style.display = 'none';
    document.getElementById('pfai-preview-container').style.display = 'block';
    document.getElementById('pfai-capture-btn').style.display = 'none';
    document.getElementById('pfai-retake-btn').style.display = 'inline-flex';
    document.getElementById('pfai-hint').style.display = 'none';

    _pfAiAnalyze(canvas.toDataURL('image/jpeg', 0.7).split(',')[1]);
}

function pfAiRetake() {
    document.getElementById('pfai-cam-container').style.display = 'block';
    document.getElementById('pfai-preview-container').style.display = 'none';
    document.getElementById('pfai-capture-btn').style.display = 'inline-flex';
    document.getElementById('pfai-retake-btn').style.display = 'none';
    document.getElementById('pfai-status').style.display = 'none';
    document.getElementById('pfai-result').style.display = 'none';
    document.getElementById('pfai-hint').style.display = 'block';

    navigator.mediaDevices.getUserMedia(getCameraConstraints()).then(stream => {
        _pfAiStream = stream;
        const video = document.getElementById('pfai-video');
        video.srcObject = stream;
        video.play();
    });
}

async function _pfAiAnalyze(base64) {
    const statusEl = document.getElementById('pfai-status');
    const resultEl = document.getElementById('pfai-result');
    statusEl.style.display = 'block';
    resultEl.style.display = 'none';

    try {
        const result = await api('gemini_identify', {}, 'POST', { image: base64 });

        statusEl.style.display = 'none';
        resultEl.style.display = 'block';

        if (!result.success) {
            resultEl.innerHTML = `<p style="color:var(--danger);text-align:center">❌ ${escapeHtml(result.error || 'Errore identificazione')}</p>
                <button class="btn btn-secondary full-width" onclick="pfAiRetake()">🔄 Riprova</button>`;
            return;
        }

        const id = result.identified;
        const matches = result.off_matches || [];

        let html = `<div class="ai-identified-card" style="margin-bottom:10px">
            <strong>${escapeHtml(id.name)}</strong>`;
        if (id.brand) html += ` <span style="color:var(--text-muted)">— ${escapeHtml(id.brand)}</span>`;
        if (id.description) html += `<p style="font-size:0.82rem;color:var(--text-light);margin:4px 0 0">${escapeHtml(id.description)}</p>`;
        html += `</div>`;

        if (matches.length > 0) {
            html += `<p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:6px">Seleziona la variante esatta o usa i dati AI:</p>`;
            html += `<div class="ai-matches-list" style="max-height:160px;overflow-y:auto;margin-bottom:10px">`;
            matches.forEach((m, idx) => {
                html += `<div class="ai-match-item" onclick="_pfAiFillFromMatch(${idx})">`;
                if (m.image_url) html += `<img src="${escapeHtml(m.image_url)}" alt="" class="ai-match-img" onerror="this.style.display='none'">`;
                html += `<div class="ai-match-info"><strong>${escapeHtml(m.name)}</strong>`;
                if (m.brand) html += `<br><small>${escapeHtml(m.brand)}</small>`;
                if (m.quantity_info) html += `<br><small style="color:var(--text-muted)">${escapeHtml(m.quantity_info)}</small>`;
                html += `</div><span class="ai-match-barcode">${escapeHtml(m.barcode)}</span></div>`;
            });
            html += `</div>`;
        }

        html += `<button class="btn btn-primary full-width" onclick="_pfAiFillFromAI()">✅ Usa dati AI${matches.length > 0 ? ' (senza barcode)' : ''}</button>`;
        resultEl.innerHTML = html;

        window._pfAiIdentified = id;
        window._pfAiMatches = matches;

    } catch (err) {
        statusEl.style.display = 'none';
        resultEl.style.display = 'block';
        resultEl.innerHTML = `<p style="color:var(--danger);text-align:center">❌ Errore di connessione</p>
            <button class="btn btn-secondary full-width" onclick="pfAiRetake()">🔄 Riprova</button>`;
    }
}

function _pfAiFillFields(name, brand, category, barcode, imageUrl, quantityInfo) {
    if (name) document.getElementById('pf-name').value = name;
    if (brand) document.getElementById('pf-brand').value = brand;
    if (category) {
        const cat = mapToLocalCategory(category, name || '');
        document.getElementById('pf-category').value = cat;
        document.getElementById('pf-category').dataset.manuallySet = 'true';
        onCategoryChange(true);
    }
    if (barcode) document.getElementById('pf-barcode').value = barcode;
    if (imageUrl) {
        document.getElementById('pf-image').value = imageUrl;
        const preview = document.getElementById('pf-image-preview');
        document.getElementById('pf-image-img').src = imageUrl;
        preview.style.display = 'block';
    }
    if (quantityInfo) {
        const detected = detectUnitAndQuantity(quantityInfo);
        document.getElementById('pf-unit').value = detected.unit;
        document.getElementById('pf-defqty').value = detected.quantity;
        document.getElementById('pf-defqty').dataset.manuallySet = 'true';
        onPfUnitChange();
    }
    // Trigger auto-detect for remaining empty fields
    if (name && !category) autoDetectCategory();
    closePfAiScanner();
    showToast('✅ Campi compilati dall\'AI', 'success');
}

function _pfAiFillFromAI() {
    const id = window._pfAiIdentified;
    if (!id) return;
    _pfAiFillFields(id.name, id.brand, id.category, '', '', '');
}

async function _pfAiFillFromMatch(idx) {
    const match = window._pfAiMatches[idx];
    if (!match) return;
    closePfAiScanner();
    showLoading(true);
    try {
        const lookupResult = await api('lookup_barcode', { barcode: match.barcode });
        if (lookupResult.found && lookupResult.product) {
            const p = lookupResult.product;
            _pfAiFillFields(p.name || match.name, p.brand || match.brand, p.category || '', match.barcode, p.image_url || match.image_url, p.quantity_info || '');
            showLoading(false);
            return;
        }
    } catch (e) {}
    showLoading(false);
    _pfAiFillFields(match.name, match.brand, match.category, match.barcode, match.image_url, '');
}

// ===== ALL PRODUCTS =====
async function loadAllProducts() {
    try {
        const data = await api('products_list');
        renderProductsList(data.products || []);
    } catch (err) {
        console.error(err);
    }
}

async function searchAllProducts() {
    const q = document.getElementById('products-search').value;
    if (q.length < 2) {
        loadAllProducts();
        return;
    }
    const data = await api('products_search', { q });
    renderProductsList(data.products || []);
}

function renderProductsList(products) {
    const container = document.getElementById('products-list');
    if (products.length === 0) {
        container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📦</div><p>Nessun prodotto nel database.<br>Scansiona un prodotto per iniziare!</p></div>';
        return;
    }
    container.innerHTML = products.map(p => {
        const catIcon = CATEGORY_ICONS[mapToLocalCategory(p.category, p.name)] || '📦';
        return `
        <div class="product-item" onclick="selectProductForAction(${p.id})">
            <div class="inv-image">
                ${p.image_url ? `<img src="${escapeHtml(p.image_url)}" alt="" onerror="this.parentElement.innerHTML='${catIcon}'">` : catIcon}
            </div>
            <div class="inv-info">
                <div class="inv-name">${escapeHtml(p.name)}</div>
                ${p.brand ? `<div class="inv-brand">${escapeHtml(p.brand)}</div>` : ''}
                <div class="inv-meta">
                    ${p.barcode ? `<span class="inv-badge" style="background:#f3f4f6;color:#374151">📊 ${p.barcode}</span>` : ''}
                    <span class="inv-badge" style="background:#f3f4f6;color:#374151">${catIcon} ${p.category || 'Non categorizzato'}</span>
                </div>
            </div>
        </div>`;
    }).join('');
}

async function selectProductForAction(productId) {
    showLoading(true);
    try {
        const data = await api('product_get', { id: productId });
        if (data.product) {
            currentProduct = data.product;
            showLoading(false);
            // Clear search inputs after selecting a product
            const psInput = document.getElementById('products-search');
            if (psInput) psInput.value = '';
            const invInput = document.getElementById('inventory-search');
            if (invInput) invInput.value = '';
            showProductAction();
        } else {
            showLoading(false);
            showToast('Prodotto non trovato', 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast('Errore', 'error');
    }
}

// ===== SHOPPING LIST (BRING! INTEGRATION) =====
let shoppingListUUID = '';
let shoppingItems = [];
let suggestionItems = [];
let shoppingPrices = {}; // { itemName: { product, searched: true } }
let _spesaScanTarget = null; // { name, rawName, idx } when tapping item to scan

// ===== SHOPPING TABS =====
function switchShoppingTab(tab) {
    document.querySelectorAll('.shopping-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel-shopping').forEach(p => p.classList.remove('active'));
    document.getElementById(`tab-${tab}`)?.classList.add('active');
    document.getElementById(`tab-panel-${tab}`)?.classList.add('active');
}

function updateShoppingTabCounts() {
    const acquistoCount = shoppingItems.length;
    const previsioneCount = smartShoppingItems.filter(i => !i.on_bring).length;
    const acqEl = document.getElementById('tab-count-acquisto');
    const prevEl = document.getElementById('tab-count-previsione');
    if (acqEl) acqEl.textContent = acquistoCount;
    if (prevEl) prevEl.textContent = previsioneCount;
    document.getElementById('shopping-tabs')?.style.setProperty('display', 'flex');
}

// ===== LOCAL SHOPPING TAGS =====
function getShoppingTags(itemName) {
    try {
        const tags = JSON.parse(localStorage.getItem('shopping_tags') || '{}');
        return tags[itemName.toLowerCase()] || [];
    } catch { return []; }
}

function toggleShoppingTag(itemIdx, tag) {
    const item = shoppingItems[itemIdx];
    if (!item) return;
    const key = item.name.toLowerCase();
    try {
        const tags = JSON.parse(localStorage.getItem('shopping_tags') || '{}');
        const existing = tags[key] || [];
        const pos = existing.indexOf(tag);
        if (pos >= 0) existing.splice(pos, 1);
        else existing.push(tag);
        if (existing.length) tags[key] = existing;
        else delete tags[key];
        localStorage.setItem('shopping_tags', JSON.stringify(tags));

        // Sync urgente/presto tag to Bring specification so it's visible in the Bring app
        if (tag === 'urgente' && shoppingListUUID) {
            const isNowUrgent = existing.includes('urgente');
            const newSpec = isNowUrgent ? '⚡ Urgente' : '';
            api('bring_add', {}, 'POST', {
                items: [{ name: item.name, specification: newSpec, update_spec: true }],
                listUUID: shoppingListUUID,
            }).catch(() => {});
            // Update local item spec for immediate re-render
            item.specification = newSpec;
        }

        renderShoppingItems();
    } catch (e) { console.error('toggleShoppingTag', e); }
}

// ===== SCAN FROM SHOPPING LIST =====
function openScanForItem(idx) {
    const item = shoppingItems[idx];
    if (!item) return;
    _spesaScanTarget = { name: item.name, rawName: item.rawName || '', idx };
    showPage('scan');
    showToast(`📷 Scansiona: ${item.name}`, 'info');
}

async function confirmShoppingItemFound() {
    if (!_spesaScanTarget) return;
    const { name, rawName } = _spesaScanTarget;
    _spesaScanTarget = null;
    document.getElementById('shopping-scan-target-banner').style.display = 'none';
    try {
        const r = await api('bring_remove', {}, 'POST', { name, rawName, listUUID: shoppingListUUID });
        if (r.success) {
            const idx = shoppingItems.findIndex(i => i.name.toLowerCase() === name.toLowerCase());
            if (idx >= 0) shoppingItems.splice(idx, 1);
            showToast(`✅ ${name} rimosso dalla lista!`, 'success');
            logOperation('bring_found', { name });
            loadShoppingCount();
        }
    } catch (e) { console.error('confirmShoppingItemFound', e); }
    showPage('shopping');
}

// ===== AUTO-ADD CRITICAL ITEMS TO BRING! =====

/** Build a Bring specification string that encodes urgency + optional brand. */
function _urgencyToSpec(urgency, brand) {
    const urgencyLabels = { critical: '⚡ Urgente', high: '🟠 Presto', medium: '', low: '' };
    const urgLabel = urgencyLabels[urgency] || '';
    if (urgLabel && brand) return `${urgLabel} · ${brand}`;
    if (urgLabel) return urgLabel;
    return brand || '';
}

// ===== BRING! PURCHASED BLOCKLIST =====
// When an item disappears from Bring (user bought it), we block auto-re-add for 4h.
const _BRING_PURCHASED_TTL = 4 * 60 * 60 * 1000; // 4 hours

function _getBringPurchasedBlocklist() {
    try {
        const raw = localStorage.getItem('_bringPurchasedBlocklist');
        const map = raw ? JSON.parse(raw) : {};
        const now = Date.now();
        // Prune expired entries
        let changed = false;
        for (const key of Object.keys(map)) {
            if (now - map[key] > _BRING_PURCHASED_TTL) { delete map[key]; changed = true; }
        }
        if (changed) localStorage.setItem('_bringPurchasedBlocklist', JSON.stringify(map));
        return map;
    } catch(e) { return {}; }
}

function _markBringPurchased(names) {
    const map = _getBringPurchasedBlocklist();
    const now = Date.now();
    for (const n of names) map[n.toLowerCase()] = now;
    localStorage.setItem('_bringPurchasedBlocklist', JSON.stringify(map));
}

function _isBringPurchased(name) {
    const map = _getBringPurchasedBlocklist();
    return Object.keys(map).some(k => _nameTokens(name)[0] === _nameTokens(k)[0] || k === name.toLowerCase());
}

async function autoAddCriticalItems() {
    // Time-based guard: run at most once every 10 minutes (not session-based, so new critical items get added promptly)
    const lastRun = parseInt(localStorage.getItem('_autoAddedCriticalTs') || '0');
    if (Date.now() - lastRun < 10 * 60 * 1000) return;
    localStorage.setItem('_autoAddedCriticalTs', String(Date.now()));
    const critical = smartShoppingItems.filter(i => i.urgency === 'critical' && !i.on_bring && !_isBringPurchased(i.name));
    if (critical.length === 0) return;
    const itemsToAdd = critical.map(i => ({ name: i.name, specification: _urgencyToSpec(i.urgency, i.brand) }));
    try {
        const result = await api('bring_add', {}, 'POST', { items: itemsToAdd, listUUID: shoppingListUUID });
        if (result.success && result.added > 0) {
            showToast(`🔴 ${result.added} prodott${result.added === 1 ? 'o urgente aggiunto' : 'i urgenti aggiunti'} automaticamente a Bring!`, 'success');
            logOperation('bring_auto_add', { added: itemsToAdd.map(i => i.name) });
            loadShoppingList();
        }
    } catch (e) { /* ignore */ }
}

/**
 * One-time cleanup: remove items from Bring! that were auto-added but the algorithm no
 * longer considers relevant.  CONSERVATIVE: only removes items that match a known product
 * in our inventory with current_qty > 0 AND that no longer appear in smart predictions.
 * Items not matching any DB product are left untouched (likely manually added by user).
 */
async function cleanupObsoleteBringItems() {
    if (sessionStorage.getItem('_bringCleanupDone')) return;
    sessionStorage.setItem('_bringCleanupDone', '1');
    if (!shoppingItems.length || !smartShoppingItems.length) return;

    // Build set of smart-flagged names (these should stay)
    const smartNames = new Set(smartShoppingItems.map(i => i.name.toLowerCase()));

    // Load all products from our DB to cross-reference
    let allProducts = [];
    try {
        const res = await api('products_list');
        allProducts = res.products || res.data || [];
    } catch (e) { return; }
    if (!allProducts.length) return;

    // Index our products by lowercase name
    const dbByName = {};
    for (const p of allProducts) {
        dbByName[p.name.toLowerCase()] = p;
    }

    const toRemove = [];
    for (const item of shoppingItems) {
        const nameLower = item.name.toLowerCase();
        // If smart shopping still flags it, keep it
        if (smartNames.has(nameLower)) continue;
        if (_findSimilarItem(item.name, smartShoppingItems)) continue;

        // Must match a known DB product — otherwise it's likely a manual addition
        const dbProduct = dbByName[nameLower] || _findSimilarItem(item.name, allProducts);
        if (!dbProduct) continue;

        // Only remove if we have stock (qty > 0) — if qty == 0 user may still need it
        if ((dbProduct.quantity || 0) <= 0) continue;

        toRemove.push(item);
    }

    if (toRemove.length === 0) return;

    let removed = 0;
    const removedNames = [];
    for (const item of toRemove) {
        try {
            const r = await api('bring_remove', {}, 'POST', {
                name: item.name,
                rawName: item.rawName || '',
                listUUID: shoppingListUUID
            });
            if (r.success) { removed++; removedNames.push(item.name); }
        } catch (e) { /* ignore individual failures */ }
    }

    if (removed > 0) {
        showToast(`🧹 ${removed} prodott${removed === 1 ? 'o con scorte sufficienti rimosso' : 'i con scorte sufficienti rimossi'} dalla lista`, 'info');
        logOperation('bring_cleanup', { removed: removedNames });
        loadShoppingList();
    }
}

/**
 * Log an app operation (not a food transaction) for auditing/debugging.
 * Stored in localStorage under '_opLog', capped at 200 entries.
 */
function logOperation(action, details) {
    try {
        const log = JSON.parse(localStorage.getItem('_opLog') || '[]');
        log.push({ ts: new Date().toISOString(), action, details });
        // Keep last 200 entries
        if (log.length > 200) log.splice(0, log.length - 200);
        localStorage.setItem('_opLog', JSON.stringify(log));
    } catch (e) { /* ignore */ }
}

const DEFAULT_SPESA_AI_PROMPT = `Sei un assistente per la spesa online. Ti viene dato il nome di un prodotto che l'utente vuole comprare e una lista di prodotti trovati nel catalogo del supermercato.

Regole di selezione:
- Scegli il prodotto che corrisponde ESATTAMENTE a quello richiesto (stessa categoria merceologica)
- Preferisci prodotti freschi/sfusi rispetto a trasformati (es. "Arance" = arance frutta, NON aranciata bevanda)
- Se c'è una descrizione (es. "a cubetti", "biologico"), trova il prodotto che include quella caratteristica
- Se ci sono più varianti valide, scegli quella con il miglior rapporto qualità/prezzo
- Preferisci formati standard per una famiglia
- NON scegliere mai un prodotto di categoria diversa (bevanda vs frutta, surgelato vs fresco, condimento vs ortaggio, ecc.)
- "Finocchio" = ortaggio fresco, NON semi di finocchio o tisana
- "Arance" = frutta fresca, NON aranciata o succo

Rispondi SOLO con il numero (indice 0-based) del prodotto migliore, oppure -1 se nessun prodotto è appropriato.`;

function saveShoppingPrices() {
    try {
        // Only save items that have been searched (not loading state)
        const toSave = {};
        for (const [k, v] of Object.entries(shoppingPrices)) {
            if (v.searched) toSave[k] = v;
        }
        // Persist to shared DB
        api('app_settings_save', {}, 'POST', { settings: { shopping_prices: toSave } }).catch(() => {});
    } catch (e) { /* ignore */ }
}

async function loadShoppingPrices() {
    try {
        const res = await api('app_settings_get');
        if (res.success && res.settings && res.settings.shopping_prices) {
            shoppingPrices = res.settings.shopping_prices;
        }
    } catch (e) { shoppingPrices = {}; }
}

// Build a better search query from item name + specification
function buildSearchQuery(item) {
    // Only use the item name for search - specification confuses the search engine
    // The AI on the backend will use the specification to pick the right product
    return item.name;
}

// Parse weight/quantity from specification (e.g. "200g" -> 0.2 kg, "500 ml" -> 0.5, "2 pz" -> 2 units)
function parseQtyFromSpec(spec) {
    if (!spec) return null;
    const s = spec.toLowerCase().trim();
    // Match weight/volume: 200g, 0.5kg, 500 g, 1,5 kg, 200 gr
    const m = s.match(/(\d+[.,]?\d*)\s*(g|gr|kg|ml|cl|l|lt)/i);
    if (m) {
        let val = parseFloat(m[1].replace(',', '.'));
        const unit = m[2].toLowerCase();
        if (unit === 'g' || unit === 'gr') return { kg: val / 1000, label: val + 'g', type: 'weight' };
        if (unit === 'kg') return { kg: val, label: (val * 1000) + 'g', type: 'weight' };
        if (unit === 'ml') return { kg: val / 1000, label: val + 'ml', type: 'weight' };
        if (unit === 'cl') return { kg: val / 100, label: val * 10 + 'ml', type: 'weight' };
        if (unit === 'l' || unit === 'lt') return { kg: val, label: (val * 1000) + 'ml', type: 'weight' };
    }
    // Match unit count: 2 pz, 3 pezzi, 5, 2x, ~5 pz
    const pzMatch = s.match(/~?(\d+)\s*(pz|pezzi|x|$)/i);
    if (pzMatch) {
        const count = parseInt(pzMatch[1]);
        if (count > 0 && count <= 50) return { count, label: count + ' pz', type: 'units' };
    }
    return null;
}

// Estimate price when product is sold per-kg/per-L or per-unit and user wants a certain quantity
function estimateItemPrice(product, spec) {
    if (!product.priceUm) return null;
    const umStr = String(product.priceUm);
    const pm = umStr.match(/(\d+[.,]?\d*)/);
    if (!pm) return null;
    const pricePerUnit = parseFloat(pm[1].replace(',', '.'));
    if (!pricePerUnit || pricePerUnit <= 0) return null;
    
    const qty = parseQtyFromSpec(spec);
    if (!qty) return null;
    
    if (qty.type === 'weight') {
        const estimated = pricePerUnit * qty.kg;
        if (estimated <= 0 || estimated > 500) return null;
        return { estimated: Math.round(estimated * 100) / 100, qtyLabel: qty.label };
    } else if (qty.type === 'units') {
        // For unit items: estimate per-item cost from the product price
        // If product is per-kg and we want N pieces, estimate ~200-300g per piece
        const avgWeightPerPiece = 0.25; // ~250g per piece (fruit/veg average)
        const estimated = pricePerUnit * avgWeightPerPiece * qty.count;
        if (estimated <= 0 || estimated > 500) return null;
        return { estimated: Math.round(estimated * 100) / 100, qtyLabel: qty.label };
    }
    return null;
}

// ===== SMART SHOPPING =====
let smartShoppingItems = [];
let smartShoppingFilter = 'all';
let _smartShoppingLastFetch = 0;      // timestamp of last successful fetch
let _bgShoppingInterval = null; // kept for compatibility, cron handles refresh server-side

/** Update dashboard badge from already-cached data */
function _updateSmartUrgencyBadge() {
    const urgentEl = document.getElementById('stat-urgent');
    if (!urgentEl) return;
    const urgent = smartShoppingItems.filter(i => i.urgency === 'critical' || i.urgency === 'high').length;
    if (urgent > 0) {
        urgentEl.textContent = `⚠ ${urgent}`;
        urgentEl.style.display = '';
    } else {
        urgentEl.style.display = 'none';
    }
}

/**
 * Sync the on_bring flag for every smartShoppingItem against the current shoppingItems list.
 * The server cache can be up to 10 min old so on_bring may be stale — this corrects it
 * client-side using strict first-token matching: a Bring item matches a smart item only when
 * the first significant token of the Bring item's name equals the first significant token of
 * the smart item's name (or exact name match). This avoids false positives like
 * "Frutta" (fresh fruit on Bring) matching "Muesli Frutta Secca" (a different product).
 */
function _syncOnBringFlags() {
    for (const si of smartShoppingItems) {
        const siLower = si.name.toLowerCase();
        const siFirst = _nameTokens(si.name)[0];
        si.on_bring = !!(
            shoppingItems.find(bi => bi.name.toLowerCase() === siLower) ||
            (siFirst && shoppingItems.find(bi => {
                const biFirst = _nameTokens(bi.name)[0];
                return biFirst === siFirst;
            }))
        );
    }
}

function _renderSmartLastUpdate() {
    const el = document.getElementById('smart-last-update');
    if (!el || !_smartShoppingLastFetch) return;
    const d = new Date(_smartShoppingLastFetch);
    el.textContent = `Aggiornato ${d.getHours().toString().padStart(2,'0')}:${d.getMinutes().toString().padStart(2,'0')}`;
}

function startBgShoppingRefresh() {
    // No-op: server-side cron handles refresh every 5 minutes.
    // The JS fetches pre-computed cache on demand (instant response).
}

async function loadSmartShopping() {
    try {
        const data = await api('smart_shopping');
        if (data.success && data.items && data.items.length > 0) {
            smartShoppingItems = data.items;
            _smartShoppingLastFetch = Date.now();
            renderSmartShopping();
            _renderSmartLastUpdate();
            _updateSmartUrgencyBadge();
            document.getElementById('smart-shopping-empty').style.display = 'none';
            document.getElementById('smart-shopping-content').style.display = 'block';
        } else {
            smartShoppingItems = [];
            _smartShoppingLastFetch = Date.now();
            document.getElementById('smart-shopping-empty').style.display = 'block';
            document.getElementById('smart-shopping-content').style.display = 'none';
        }
    } catch (e) {
        console.error('Smart shopping error:', e);
        smartShoppingItems = [];
    }
    updateShoppingTabCounts();
}

function filterSmart(filter) {
    smartShoppingFilter = filter;
    document.querySelectorAll('.smart-filter').forEach(b => b.classList.remove('active'));
    document.querySelector(`.smart-filter[data-filter="${filter}"]`)?.classList.add('active');
    renderSmartShopping();
}

function renderSmartShopping() {
    const container = document.getElementById('smart-items');
    const countEl = document.getElementById('smart-count');
    const actionsEl = document.getElementById('smart-actions');

    let items = smartShoppingItems;
    if (smartShoppingFilter !== 'all') {
        items = items.filter(i => i.urgency === smartShoppingFilter);
    }

    countEl.textContent = items.length;

    if (items.length === 0) {
        container.innerHTML = '<div class="empty-state" style="padding:16px"><p>Nessun prodotto in questa categoria</p></div>';
        actionsEl.style.display = 'none';
        return;
    }

    const urgencyConfig = {
        critical: { color: '#ef4444', bg: 'rgba(239,68,68,0.08)', icon: '🔴', label: 'Urgente' },
        high:     { color: '#f97316', bg: 'rgba(249,115,22,0.08)', icon: '🟠', label: 'Presto' },
        medium:   { color: '#eab308', bg: 'rgba(234,179,8,0.08)',  icon: '🟡', label: 'Pianifica' },
        low:      { color: '#22c55e', bg: 'rgba(34,197,94,0.08)',  icon: '🟢', label: 'Previsione' },
    };

    // Group by section
    const smartSectionMap = new Map();
    items.forEach(item => {
        const sec = getItemSection(item.name);
        if (!smartSectionMap.has(sec.key)) smartSectionMap.set(sec.key, { sec, items: [] });
        smartSectionMap.get(sec.key).items.push(item);
    });

    let smartHtml = '';
    for (const secDef of SHOPPING_SECTIONS) {
        const group = smartSectionMap.get(secDef.key);
        if (!group) continue;
        smartHtml += `<div class="shopping-section-divider"><span class="sec-icon">${secDef.icon}</span>${secDef.label}</div>`;
        for (const item of group.items) {
            smartHtml += renderSmartItem(item, items);
        }
    }
    container.innerHTML = smartHtml;

    // Show/hide add button based on checkable items
    const hasCheckable = items.some(i => !i.on_bring);
    actionsEl.style.display = hasCheckable ? 'block' : 'none';
}

function renderSmartItem(item) {
    const urgencyConfig = {
        critical: { color: '#ef4444', bg: 'rgba(239,68,68,0.08)', icon: '🔴', label: 'Urgente' },
        high:     { color: '#f97316', bg: 'rgba(249,115,22,0.08)', icon: '🟠', label: 'Presto' },
        medium:   { color: '#eab308', bg: 'rgba(234,179,8,0.08)',  icon: '🟡', label: 'Pianifica' },
        low:      { color: '#22c55e', bg: 'rgba(34,197,94,0.08)',  icon: '🟢', label: 'Previsione' },
    };
    const u = urgencyConfig[item.urgency] || urgencyConfig.low;
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
    const globalIdx = smartShoppingItems.indexOf(item);

        // Stock bar
        const pct = Math.min(100, Math.max(0, item.pct_left));
        const barColor = pct <= 15 ? '#ef4444' : pct <= 30 ? '#f97316' : pct <= 50 ? '#eab308' : '#22c55e';

        // Quantity display
        let qtyText = '';
        if (item.current_qty > 0) {
            qtyText = `${item.current_qty} ${item.unit}`;
            if (item.pct_left < 100) qtyText += ` (${pct}%)`;
        } else {
            qtyText = 'Esaurito';
        }

        // Usage frequency badge
        let freqBadge = '';
        if (item.use_count >= 8) freqBadge = '<span class="smart-freq-badge freq-high">📈 Uso frequente</span>';
        else if (item.use_count >= 4) freqBadge = '<span class="smart-freq-badge freq-med">📊 Uso regolare</span>';
        else if (item.use_count >= 2) freqBadge = '<span class="smart-freq-badge freq-low">📉 Uso occasionale</span>';

        // Days left prediction
        let predBadge = '';
        if (item.days_left <= 3 && item.days_left > 0 && item.current_qty > 0) {
            predBadge = `<span class="smart-pred-badge pred-urgent">⏳ ~${item.days_left}gg rimasti</span>`;
        } else if (item.days_left <= 7 && item.days_left > 0 && item.current_qty > 0) {
            predBadge = `<span class="smart-pred-badge pred-soon">⏳ ~${item.days_left}gg rimasti</span>`;
        }

        // Expiry badge
        let expiryBadge = '';
        if (item.days_to_expiry < 0 && item.current_qty > 0) {
            expiryBadge = `<span class="smart-pred-badge pred-urgent">⚠️ Scaduto</span>`;
        } else if (item.days_to_expiry <= 3 && item.days_to_expiry >= 0 && item.current_qty > 0) {
            expiryBadge = `<span class="smart-pred-badge pred-urgent">⚠️ Scade tra ${item.days_to_expiry}gg</span>`;
        }

        return `
        <div class="smart-item" style="border-left: 3px solid ${u.color}; background: ${u.bg}">
            <div class="smart-item-top">
                ${!item.on_bring ? `<input type="checkbox" class="smart-check" data-idx="${globalIdx}">` : ''}
                <span class="smart-item-icon">${catIcon}</span>
                <div class="smart-item-info">
                    <div class="smart-item-name">${escapeHtml(item.name)}${item.brand ? ` <small class="smart-brand">${escapeHtml(item.brand)}</small>` : ''}</div>
                    <div class="smart-item-reasons">${item.reasons.map(r => `<span>${escapeHtml(r)}</span>`).join(' · ')}</div>
                    <div class="smart-item-badges">
                        <span class="smart-urgency-badge" style="color:${u.color}">${u.icon} ${u.label}</span>
                        ${freqBadge}${predBadge}${expiryBadge}
                        ${item.is_opened ? '<span class="smart-freq-badge freq-low">📭 Aperto</span>' : ''}
                        ${item.on_bring ? '<span class="smart-bring-badge">🛒 Già su Bring!</span>' : ''}
                    </div>
                </div>
                <div class="smart-item-stock">
                    <span class="smart-qty">${qtyText}</span>
                    ${item.current_qty > 0 ? `<div class="smart-stock-bar"><div class="smart-stock-fill" style="width:${pct}%;background:${barColor}"></div></div>` : ''}
                </div>
            </div>
        </div>`;
}

async function addSmartToBring() {
    const checks = document.querySelectorAll('.smart-check:checked');
    if (checks.length === 0) {
        showToast('Seleziona almeno un prodotto', 'info');
        return;
    }

    const itemsToAdd = [];
    checks.forEach(cb => {
        const idx = parseInt(cb.dataset.idx);
        const item = smartShoppingItems[idx];
        if (item) {
            itemsToAdd.push({
                name: item.name,
                specification: _urgencyToSpec(item.urgency, item.brand),
            });
        }
    });

    showLoading(true);
    try {
        const result = await api('bring_add', {}, 'POST', {
            items: itemsToAdd,
            listUUID: shoppingListUUID,
        });
        showLoading(false);
        if (result.success) {
            const msg = result.added > 0
                ? `🛒 ${result.added} prodotti aggiunti a Bring!${result.skipped > 0 ? ` (${result.skipped} già presenti)` : ''}`
                : `Tutti i prodotti erano già su Bring!`;
            showToast(msg, result.added > 0 ? 'success' : 'info');
            // Reload to refresh badges
            loadShoppingList();
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch (e) {
        showLoading(false);
        showToast('Errore di connessione', 'error');
    }
}

// Load just the shopping count for dashboard stat card
async function loadShoppingCount() {
    try {
        const data = await api('bring_list');
        if (data.success && data.purchase) {
            document.getElementById('stat-spesa').textContent = data.purchase.length;
        } else {
            document.getElementById('stat-spesa').textContent = '-';
        }
    } catch {
        document.getElementById('stat-spesa').textContent = '-';
    }
    // Smart urgency badge: use cached data if fresh (< 2 min), else fetch
    if (smartShoppingItems.length > 0 && (Date.now() - _smartShoppingLastFetch) < 2 * 60 * 1000) {
        _updateSmartUrgencyBadge();
    } else {
        try {
            const smart = await api('smart_shopping');
            if (smart.success && smart.items) {
                smartShoppingItems = smart.items;
                _smartShoppingLastFetch = Date.now();
                _updateSmartUrgencyBadge();
            }
        } catch { /* ignore */ }
    }
}

/**
 * Sync local 'urgente' tag from Bring specification.
 * If a Bring item's specification contains 'urgente', ensure the local tag is set.
 * If a Bring item's specification is empty/cleared, remove the local urgente tag
 * UNLESS smart shopping considers it critical (to avoid losing urgency on stale specs).
 */
function _syncTagsFromBringSpec() {
    try {
        const tags = JSON.parse(localStorage.getItem('shopping_tags') || '{}');
        let changed = false;
        for (const item of shoppingItems) {
            const key = item.name.toLowerCase();
            const spec = (item.specification || '').toLowerCase();
            const existing = tags[key] || [];
            const hasUrgente = existing.includes('urgente');
            const smartMatch = _matchBringToSmart(item.name, smartShoppingItems);
            const smartIsCritical = smartMatch && (smartMatch.urgency === 'critical' || smartMatch.urgency === 'high');
            if ((spec.includes('urgente') || spec.includes('presto') || smartIsCritical) && !hasUrgente) {
                existing.push('urgente');
                tags[key] = existing;
                changed = true;
            } else if (!spec.includes('urgente') && !spec.includes('presto') && !smartIsCritical && hasUrgente) {
                existing.splice(existing.indexOf('urgente'), 1);
                if (existing.length) tags[key] = existing;
                else delete tags[key];
                changed = true;
            }
        }
        if (changed) localStorage.setItem('shopping_tags', JSON.stringify(tags));
    } catch (e) { /* ignore */ }
}

/**
 * After smart shopping loads, push urgency specifications to Bring for all matched items.
 * This makes urgency visible in the native Bring app via the item specification field.
 * Only updates if the spec has changed (to avoid unnecessary API calls).
 */
async function autoSyncUrgencySpecs() {
    if (!shoppingListUUID || !smartShoppingItems.length) return;
    const toUpdate = [];
    for (const item of shoppingItems) {
        const smartMatch = _matchBringToSmart(item.name, smartShoppingItems);
        if (!smartMatch) continue;
        const expectedSpec = _urgencyToSpec(smartMatch.urgency, '');
        const currentSpec = (item.specification || '').toLowerCase();
        // Only update if urgency marker changed (don't clobber user-set spec info that isn't urgency)
        const currentHasUrgencyMarker = currentSpec.includes('urgente') || currentSpec.includes('presto');
        const needsUpdate = expectedSpec && !currentHasUrgencyMarker;
        const needsClear = !expectedSpec && currentHasUrgencyMarker;
        if (needsUpdate || needsClear) {
            toUpdate.push({ name: item.name, specification: expectedSpec, update_spec: true });
            // Optimistically update local item so re-render is immediate
            item.specification = expectedSpec;
        }
    }
    if (toUpdate.length === 0) return;
    try {
        await api('bring_add', {}, 'POST', { items: toUpdate, listUUID: shoppingListUUID });
    } catch (e) { /* ignore - sync is best-effort */ }
}

async function loadShoppingList() {
    const statusEl = document.getElementById('bring-status');
    const currentEl = document.getElementById('shopping-current');
    const suggestionsEl = document.getElementById('shopping-suggestions');
    
    statusEl.style.display = 'block';
    statusEl.innerHTML = '<div class="bring-loading"><div class="loading-spinner"></div> Connessione a Bring!...</div>';
    currentEl.style.display = 'none';
    suggestionsEl.style.display = 'none';
    
    try {
        const data = await api('bring_list');
        statusEl.style.display = 'none';
        
        if (!data.success) {
            statusEl.style.display = 'block';
            statusEl.innerHTML = `<div class="bring-error">⚠️ ${escapeHtml(data.error || 'Errore connessione Bring!')}</div>`;
            return;
        }
        
        shoppingListUUID = data.listUUID;
        // Detect items removed from Bring since last load (= just purchased by user)
        const prevNames = new Set((shoppingItems || []).map(i => i.name.toLowerCase()));
        const newItems  = data.purchase || [];
        const newNames  = new Set(newItems.map(i => i.name.toLowerCase()));
        if (prevNames.size > 0) {
            const removedNames = [...prevNames].filter(n => !newNames.has(n));
            if (removedNames.length) _markBringPurchased(removedNames);
        }
        shoppingItems = newItems;
        
        // Clean up shoppingPrices for items no longer on the list
        const currentKeys = new Set(shoppingItems.map(i => i.name.toLowerCase()));
        let pricesChanged = false;
        for (const key of Object.keys(shoppingPrices)) {
            if (!currentKeys.has(key)) {
                delete shoppingPrices[key];
                pricesChanged = true;
            }
        }
        if (pricesChanged) saveShoppingPrices();
        
        loadShoppingPrices();
        // Sync urgente local tags from Bring specification (items marked urgent by us or manually)
        _syncTagsFromBringSpec();
        renderShoppingItems();
        currentEl.style.display = 'block';
        
        // Load smart shopping predictions, then re-render to show badges + auto-add critical
        loadSmartShopping().then(() => {
            _syncOnBringFlags();          // sync on_bring against current Bring list before any logic reads it
            _syncTagsFromBringSpec();     // re-sync tags now that smart data is available
            autoSyncUrgencySpecs();       // push urgency specs to Bring for matched items
            renderSmartShopping();        // re-render smart tab with corrected on_bring flags
            updateShoppingTabCounts();    // update tab badges with corrected counts
            autoAddCriticalItems();
            cleanupObsoleteBringItems();
            renderShoppingItems();        // re-render shopping tab with urgency badges
        });

    } catch (err) {
        console.error('Bring! error:', err);
        statusEl.style.display = 'block';
        statusEl.innerHTML = '<div class="bring-error">⚠️ Errore di connessione a Bring!</div>';
    }
}

/** Return the spec text to show in the UI, stripping urgency markers (those are shown as badges). */
function _specDisplayText(spec) {
    if (!spec) return '';
    // Strip known urgency prefixes set by _urgencyToSpec (case-insensitive, then trim separator)
    const lower = spec.toLowerCase();
    for (const prefix of ['⚡ urgente', '🟠 presto']) {
        if (lower.startsWith(prefix)) {
            return spec.slice(prefix.length).replace(/^\s*[·\-]\s*/, '').trim();
        }
    }
    return spec;
}

/** Return the spec for price search, stripping urgency markers that would confuse the AI. */
function _cleanSpecForSearch(spec) {
    return _specDisplayText(spec);
}

async function renderShoppingItems() {
    const container = document.getElementById('shopping-items');
    const countEl = document.getElementById('shopping-count');

    countEl.textContent = shoppingItems.length;
    // Update tab count too
    const tabCount = document.getElementById('tab-count-acquisto');
    if (tabCount) tabCount.textContent = shoppingItems.length;
    
    if (shoppingItems.length === 0) {
        container.innerHTML = '<div class="empty-state" style="padding:20px"><div class="empty-state-icon">✅</div><p>Lista della spesa vuota!<br>Usa il pulsante sotto per generare suggerimenti.</p></div>';
        updateSpesaTotal();
        return;
    }
    
    const s = getSettings();
    let hasSpesa = s.spesa_logged_in && s.spesa_token;
    
    // If not logged in locally, check server-side token
    if (!hasSpesa) {
        try {
            const status = await api('dupliclick_status');
            if (status.logged_in) {
                hasSpesa = true;
                s.spesa_logged_in = true;
                s.spesa_token = 'server';
                s.spesa_user = status.email || '';
                saveSettings(s);
            }
        } catch (e) { /* ignore */ }
    }

    // Build section groups, sorted by urgency weight within each section
    const TAG_LABELS = { urgente: '🔴 Urgente', prio: '⭐ Priorità', check: '✅ Verificare' };
    const urgencyMap = {
        critical: { icon: '🔴', label: 'Urgente', cls: 'badge-critical' },
        high:     { icon: '🟠', label: 'Presto',  cls: 'badge-high' },
        medium:   { icon: '🟡', label: 'Medio',   cls: 'badge-medium' },
        low:      { icon: '🟢', label: 'Ok',       cls: 'badge-low' },
    };

    // Map each item to its section + urgency (strict first-token matching to avoid false positives)
    // Also derive urgency from Bring specification if smart matching fails
    const enriched = shoppingItems.map((item, idx) => {
        const smartData = _matchBringToSmart(item.name, smartShoppingItems);
        let urgency = smartData?.urgency || null;
        // Fallback: read urgency from Bring specification (set by our app when adding)
        if (!urgency && item.specification) {
            const spec = item.specification.toLowerCase();
            if (spec.includes('urgente')) urgency = 'critical';
            else if (spec.includes('presto')) urgency = 'high';
        }
        const sec = getItemSection(item.name);
        return { item, idx, smartData, urgency, sec };
    });

    // Group by section key, preserving SHOPPING_SECTIONS order
    const sectionMap = new Map();
    for (const e of enriched) {
        const key = e.sec.key;
        if (!sectionMap.has(key)) sectionMap.set(key, { sec: e.sec, items: [] });
        sectionMap.get(key).items.push(e);
    }

    // Sort items within each section: by urgency weight desc, then by use_count desc
    for (const [, group] of sectionMap) {
        group.items.sort((a, b) => {
            const wa = URGENCY_WEIGHT[a.urgency] || 0;
            const wb = URGENCY_WEIGHT[b.urgency] || 0;
            if (wb !== wa) return wb - wa;
            return (b.smartData?.use_count || 0) - (a.smartData?.use_count || 0);
        });
    }

    // Render sections in canonical order
    let html = '';
    for (const secDef of SHOPPING_SECTIONS) {
        const group = sectionMap.get(secDef.key);
        if (!group) continue;

        html += `<div class="shopping-section-divider"><span class="sec-icon">${secDef.icon}</span>${secDef.label}</div>`;

        for (const { item, idx, smartData, urgency } of group.items) {
            const catIcon = CATEGORY_ICONS[guessCategoryFromName(item.name)] || '🛒';
            const priceKey = item.name.toLowerCase();
            const priceData = shoppingPrices[priceKey];
            const bgStyle = urgency && URGENCY_BG[urgency] ? ` style="background:${URGENCY_BG[urgency]}"` : '';
            const localTags = getShoppingTags(item.name);

            // Urgency badge
            let urgencyBadge = '';
            if (urgency && urgencyMap[urgency]) {
                const u = urgencyMap[urgency];
                urgencyBadge = `<span class="sinv-badge ${u.cls}">${u.icon} ${u.label}</span>`;
            }

            // Frequency badge
            let freqBadge = '';
            if (smartData && smartData.use_count >= 8) freqBadge = `<span class="sinv-badge badge-freq-high">📈 ${smartData.use_count}x</span>`;
            else if (smartData && smartData.use_count >= 4) freqBadge = `<span class="sinv-badge badge-freq-med">📊 ${smartData.use_count}x</span>`;
            else if (smartData && smartData.use_count >= 2) freqBadge = `<span class="sinv-badge badge-freq-low">📉 ${smartData.use_count}x</span>`;

            const localTagHtml = localTags.map(t =>
                `<span class="sinv-badge badge-local-tag" onclick="event.stopPropagation(); toggleShoppingTag(${idx}, '${t}')">${TAG_LABELS[t] || t} ✕</span>`
            ).join('');

            const tagMenu = `<div class="shopping-tag-menu" onclick="event.stopPropagation()">
                ${Object.entries(TAG_LABELS).map(([k, v]) =>
                    `<button class="sinv-badge badge-tag-add ${localTags.includes(k) ? 'active' : ''}" onclick="toggleShoppingTag(${idx}, '${k}')">${v}</button>`
                ).join('')}
            </div>`;

            let detailHtml = '';
            let priceTag = '';
            let spesaBar = '';
            if (hasSpesa) {
                if (priceData && priceData.loading) {
                    detailHtml = `<div class="spesa-loading">🔍 Cerco...</div>`;
                } else if (priceData && priceData.product) {
                    const p = priceData.product;
                    const promoHtml = p.promo
                        ? `<span class="spesa-promo-badge">${escapeHtml(p.promo.label)} -${Math.round(p.promo.discountPerc)}%</span>`
                        : '';
                    const est = estimateItemPrice(p, item.specification || priceData.spec || '');
                    priceTag = est
                        ? `<div class="shopping-item-price">~€${est.estimated.toFixed(2)}</div>`
                        : `<div class="shopping-item-price">€${p.price.toFixed(2)}</div>`;
                    detailHtml = `<div class="spesa-detail-left">
                        <span class="spesa-found-name">${escapeHtml(p.name)}</span>
                        <span class="spesa-pkg">${escapeHtml(p.packageDescr)}${est ? ' · ' + escapeHtml(String(p.priceUm || '')) + '/kg' : ''}</span>
                        ${promoHtml}
                    </div>`;
                    spesaBar = `<div class="spesa-bar">
                        <button class="spesa-bar-btn" onclick="event.stopPropagation(); searchItemPrice(${idx}, true)" title="Ricerca">🔄 Ricerca</button>
                        <a href="${escapeHtml(p.url)}" target="_blank" class="spesa-bar-btn" title="${escapeHtml(p.name)}" onclick="event.stopPropagation()">🔗 Apri</a>
                    </div>`;
                } else if (priceData && priceData.searched && !priceData.product) {
                    detailHtml = `<div class="spesa-detail-left"><span class="spesa-not-found">Non trovato</span></div>`;
                    spesaBar = `<div class="spesa-bar">
                        <button class="spesa-bar-btn" onclick="event.stopPropagation(); searchItemPrice(${idx}, true)" title="Riprova">🔄 Riprova</button>
                    </div>`;
                } else {
                    spesaBar = `<div class="spesa-bar">
                        <button class="spesa-bar-btn" onclick="event.stopPropagation(); searchItemPrice(${idx})" title="Cerca prezzo">🔍 Cerca prezzo</button>
                    </div>`;
                }
            }

            html += `
            <div class="shopping-item ${priceData?.product?.promo ? 'has-promo' : ''}" id="shop-item-${idx}" onclick="openScanForItem(${idx})" title="Tocca per scansionare"${bgStyle}>
                <span class="shopping-item-icon">${catIcon}</span>
                <div class="shopping-item-body">
                    <div class="shopping-item-top">
                        <div class="shopping-item-info">
                            <div class="shopping-item-name-row">
                                <span class="shopping-item-name">${escapeHtml(item.name)}</span>
                                <span class="shopping-item-scan-hint">📷</span>
                            </div>
                            ${_specDisplayText(item.specification) ? `<div class="shopping-item-spec">${escapeHtml(_specDisplayText(item.specification))}</div>` : ''}
                            ${(urgencyBadge || freqBadge || localTagHtml) ? `<div class="shopping-item-badges">${urgencyBadge}${freqBadge}${localTagHtml}</div>` : ''}
                            ${detailHtml}
                        </div>
                        <div class="shopping-item-right" onclick="event.stopPropagation()">
                            ${priceTag}
                            <button class="shopping-item-tag-btn" onclick="toggleShoppingTagMenu(this)" title="Tag">🏷️</button>
                            <button class="shopping-item-remove" onclick="removeBringItem(${idx})" title="Rimuovi">✕</button>
                        </div>
                    </div>
                    ${spesaBar}
                    <div class="shopping-tag-menu-container" style="display:none">${tagMenu}</div>
                </div>
            </div>`;
        }
    }

    container.innerHTML = html;
    updateSpesaTotal();
}

function toggleShoppingTagMenu(btn) {
    const container = btn.closest('.shopping-item-body').querySelector('.shopping-tag-menu-container');
    if (!container) return;
    const isOpen = container.style.display !== 'none';
    // Close all other menus first
    document.querySelectorAll('.shopping-tag-menu-container').forEach(c => c.style.display = 'none');
    container.style.display = isOpen ? 'none' : 'block';
}

function updateSpesaTotal() {
    const banner = document.getElementById('spesa-total-banner');
    const valueEl = document.getElementById('spesa-total-value');
    const detailEl = document.getElementById('spesa-total-detail');
    
    let total = 0;
    let found = 0;
    let promoSaved = 0;
    
    for (const item of shoppingItems) {
        const pd = shoppingPrices[item.name.toLowerCase()];
        if (pd && pd.product) {
            const est = estimateItemPrice(pd.product, item.specification || pd.spec || '');
            total += est ? est.estimated : pd.product.price;
            found++;
            if (pd.product.promo) {
                promoSaved += pd.product.promo.discount;
            }
        }
    }
    
    if (found === 0) {
        banner.style.display = 'none';
        return;
    }
    
    banner.style.display = 'block';
    valueEl.textContent = `€ ${total.toFixed(2)}`;
    
    let detail = `${found}/${shoppingItems.length} prodotti trovati`;
    if (promoSaved > 0) {
        detail += ` · 🏷️ Risparmi €${promoSaved.toFixed(2)} con le offerte`;
    }
    detailEl.textContent = detail;
}

async function searchItemPrice(idx, force = false) {
    const item = shoppingItems[idx];
    if (!item) return;
    
    const priceKey = item.name.toLowerCase();
    const cached = shoppingPrices[priceKey];
    // Invalidate cache if spec changed (e.g. item was updated in Bring)
    if (!force && cached && cached.searched) {
        const cachedSpec = (cached.spec || '').toLowerCase();
        const currentSpec = (item.specification || '').toLowerCase();
        if (cachedSpec === currentSpec) return;
    }
    
    const s = getSettings();
    const provider = s.spesa_provider || 'dupliclick';
    
    // Show loading state
    shoppingPrices[priceKey] = { searched: false, loading: true, product: null };
    renderShoppingItems();
    
    try {
        // Send item name as query, spec separately for AI selection (strip urgency markers)
        const searchQ = item.name;
        const spec = _cleanSpecForSearch(item.specification || '');
        
        const s2 = getSettings();
        const aiPrompt = s2.spesa_ai_prompt || '';
        const res = await api(`${provider}_search`, { 
            q: searchQ, 
            spec: spec,
            prompt: aiPrompt
        });
        if (res.success && res.product) {
            shoppingPrices[priceKey] = { searched: true, product: res.product, spec: _cleanSpecForSearch(item.specification || '') };
        } else {
            shoppingPrices[priceKey] = { searched: true, product: null };
        }
    } catch (e) {
        shoppingPrices[priceKey] = { searched: true, product: null };
    }
    
    saveShoppingPrices();
    renderShoppingItems();
}

async function searchAllPrices() {
    const s = getSettings();
    if (!s.spesa_logged_in && !s.spesa_token) {
        // Try server-side check
        try {
            const status = await api('dupliclick_status');
            if (!status.logged_in) {
                showToast('Configura prima la Spesa Online nelle impostazioni', 'error');
                return;
            }
            s.spesa_logged_in = true;
            s.spesa_token = 'server';
            saveSettings(s);
        } catch (e) {
            showToast('Configura prima la Spesa Online nelle impostazioni', 'error');
            return;
        }
    }
    
    const btn = document.getElementById('btn-search-prices');
    const toSearch = shoppingItems.filter(item => {
        const pd = shoppingPrices[item.name.toLowerCase()];
        return !pd || !pd.searched;
    });
    
    if (toSearch.length === 0) {
        showToast('Tutti i prodotti sono già stati cercati. Usa 🔄 per ricercare singoli.', 'info');
        return;
    }
    
    btn.disabled = true;
    const totalToSearch = toSearch.length;
    
    for (let i = 0; i < toSearch.length; i++) {
        const item = toSearch[i];
        btn.innerHTML = `⏳ Cerco ${i + 1}/${totalToSearch}...`;
        
        const priceKey = item.name.toLowerCase();
        const provider = s.spesa_provider || 'dupliclick';
        
        try {
            const aiPrompt = s.spesa_ai_prompt || '';
            const res = await api(`${provider}_search`, { 
                q: item.name, 
                spec: _cleanSpecForSearch(item.specification || ''),
                prompt: aiPrompt
            });
            if (res.success && res.product) {
                shoppingPrices[priceKey] = { searched: true, product: res.product, spec: _cleanSpecForSearch(item.specification || '') };
            } else {
                shoppingPrices[priceKey] = { searched: true, product: null };
            }
        } catch (e) {
            shoppingPrices[priceKey] = { searched: true, product: null };
        }
        
        saveShoppingPrices();
        renderShoppingItems();
        
        // Small delay to not overwhelm the API
        if (i < toSearch.length - 1) {
            await new Promise(r => setTimeout(r, 300));
        }
    }
    
    btn.disabled = false;
    btn.innerHTML = '🔍 Cerca tutti i prezzi';
    showToast(`Ricerca completata: ${totalToSearch} prodotti`, 'success');
}

async function removeBringItem(idx) {
    const item = shoppingItems[idx];
    if (!item) return;
    try {
        const data = await api('bring_remove', {}, 'POST', { 
            name: item.name, 
            rawName: item.rawName || '', 
            listUUID: shoppingListUUID 
        });
        if (data.success) {
            shoppingItems.splice(idx, 1);
            renderShoppingItems();
            showToast('Rimosso dalla lista', 'success');
            logOperation('bring_manual_remove', { name: item.name });
            // Update dashboard shopping count
            loadShoppingCount();
        }
    } catch (err) {
        showToast('Errore nella rimozione', 'error');
    }
}

async function generateSuggestions() {
    const btn = document.getElementById('btn-suggest');
    const suggestionsEl = document.getElementById('shopping-suggestions');
    
    btn.disabled = true;
    btn.innerHTML = '<div class="loading-spinner" style="display:inline-block;width:18px;height:18px;margin-right:8px;vertical-align:middle"></div> Analisi in corso...';
    suggestionsEl.style.display = 'none';
    
    try {
        const data = await api('bring_suggest', {}, 'POST', {});
        
        btn.disabled = false;
        btn.innerHTML = '🤖 Suggerisci cosa comprare';
        
        if (!data.success) {
            showToast(data.error || 'Errore nella generazione', 'error');
            return;
        }
        
        suggestionItems = (data.suggestions || []).map(s => ({ ...s, selected: true }));
        
        // Show seasonal tip
        const tipEl = document.getElementById('seasonal-tip');
        if (data.seasonal_tip) {
            tipEl.style.display = 'block';
            tipEl.innerHTML = `🌿 <em>${escapeHtml(data.seasonal_tip)}</em>`;
        } else {
            tipEl.style.display = 'none';
        }
        
        renderSuggestions();
        suggestionsEl.style.display = 'block';
        document.getElementById('suggestion-actions').style.display = 'block';
        
        // Scroll to suggestions
        suggestionsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = '🤖 Suggerisci cosa comprare';
        console.error('Suggestion error:', err);
        showToast('Errore di connessione', 'error');
    }
}

function renderSuggestions() {
    const container = document.getElementById('suggestion-items');
    
    const priorityOrder = { 'alta': 0, 'media': 1, 'bassa': 2 };
    const sorted = [...suggestionItems].sort((a, b) => (priorityOrder[a.priority] || 2) - (priorityOrder[b.priority] || 2));
    
    container.innerHTML = sorted.map((item, idx) => {
        const catIcon = CATEGORY_ICONS[item.category] || '🛒';
        const priorityBadge = {
            'alta': '<span class="priority-badge priority-high">Alta</span>',
            'media': '<span class="priority-badge priority-med">Media</span>',
            'bassa': '<span class="priority-badge priority-low">Bassa</span>',
        }[item.priority] || '';
        
        return `
        <div class="suggestion-item ${item.selected ? 'selected' : ''}" onclick="toggleSuggestion(${idx})">
            <div class="suggestion-check">${item.selected ? '☑️' : '⬜'}</div>
            <span class="shopping-item-icon">${catIcon}</span>
            <div class="suggestion-info">
                <div class="suggestion-name">${escapeHtml(item.name)}${item.specification ? ` <small>(${escapeHtml(item.specification)})</small>` : ''} ${priorityBadge}</div>
                <div class="suggestion-reason">${escapeHtml(item.reason)}</div>
            </div>
        </div>`;
    }).join('');
    
    updateSuggestionActionBtn();
}

function toggleSuggestion(idx) {
    const priorityOrder = { 'alta': 0, 'media': 1, 'bassa': 2 };
    const sorted = [...suggestionItems].sort((a, b) => (priorityOrder[a.priority] || 2) - (priorityOrder[b.priority] || 2));
    const actualItem = sorted[idx];
    // Find in original array
    const origIdx = suggestionItems.indexOf(actualItem);
    if (origIdx >= 0) {
        suggestionItems[origIdx].selected = !suggestionItems[origIdx].selected;
    }
    renderSuggestions();
}

function updateSuggestionActionBtn() {
    const selected = suggestionItems.filter(s => s.selected);
    const btn = document.querySelector('#suggestion-actions .btn-success');
    if (btn) {
        btn.textContent = `✅ Aggiungi ${selected.length} prodott${selected.length === 1 ? 'o' : 'i'} a Bring!`;
        btn.disabled = selected.length === 0;
    }
}

async function addSelectedSuggestions() {
    const selected = suggestionItems.filter(s => s.selected);
    if (selected.length === 0) {
        showToast('Seleziona almeno un prodotto', 'error');
        return;
    }
    
    const btn = document.querySelector('#suggestion-actions .btn-success');
    btn.disabled = true;
    btn.innerHTML = '<div class="loading-spinner" style="display:inline-block;width:18px;height:18px;margin-right:8px;vertical-align:middle"></div> Aggiunta in corso...';
    
    try {
        const items = selected.map(s => {
            return { name: s.name };
        });
        
        const data = await api('bring_add', {}, 'POST', { items, listUUID: shoppingListUUID });
        
        if (data.success) {
            let msg = `${data.added} prodott${data.added === 1 ? 'o aggiunto' : 'i aggiunti'} a Bring!`;
            if (data.skipped > 0) msg += ` (${data.skipped} già in lista)`;
            showToast(msg, 'success');
            // Refresh list
            await loadShoppingList();
            // Update dashboard shopping count
            loadShoppingCount();
            // Clear suggestions
            document.getElementById('shopping-suggestions').style.display = 'none';
            suggestionItems = [];
        } else {
            showToast(data.error || 'Errore', 'error');
        }
    } catch (err) {
        showToast('Errore di connessione', 'error');
    }
    
    btn.disabled = false;
    btn.innerHTML = '✅ Aggiungi selezionati a Bring!';
}

// ===== UTILITY FUNCTIONS =====

// ===== SCAN EXPIRY DATE WITH CAMERA + GEMINI AI =====
let expiryStream = null;

async function scanExpiryWithAI() {
    // Create modal for camera capture
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>📷 Scansiona Data Scadenza</h3>
            <button class="modal-close" onclick="closeExpiryScanner()">✕</button>
        </div>
        <div class="expiry-scanner">
            <div id="expiry-cam-container" style="height:180px;overflow:hidden;border-radius:10px;position:relative">
                <video id="expiry-video" autoplay playsinline style="width:100%;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) scale(2);transform-origin:center center"></video>
                <canvas id="expiry-canvas" style="display:none"></canvas>
                <div style="position:absolute;inset:0;border:2px dashed rgba(255,255,255,0.5);border-radius:10px;pointer-events:none"></div>
            </div>
            <div id="expiry-preview-container" style="display:none;height:180px;overflow:hidden;border-radius:10px">
                <img id="expiry-preview-img" src="" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px">
            </div>
            <p class="form-hint" style="text-align:center;margin:6px 0;font-size:0.8rem">Inquadra la data di scadenza stampata sul prodotto</p>
            <div id="expiry-scan-status" style="display:none;text-align:center;padding:8px">
                <div class="loading-spinner" style="margin:0 auto 6px"></div>
                <p>🤖 Analisi AI in corso...</p>
            </div>
            <div class="expiry-scanner-actions">
                <button class="btn btn-large btn-accent full-width" id="expiry-capture-btn" onclick="captureExpiry()">📸 Scatta Foto</button>
                <button class="btn btn-large btn-secondary full-width" id="expiry-retake-btn" onclick="retakeExpiry()" style="display:none">🔄 Riscatta</button>
            </div>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    
    // Start camera
    try {
        expiryStream = await navigator.mediaDevices.getUserMedia(getCameraConstraints());
        const video = document.getElementById('expiry-video');
        video.srcObject = expiryStream;
        await video.play();
    } catch (err) {
        console.error('Expiry camera error:', err);
        document.getElementById('expiry-cam-container').innerHTML = `
            <p style="color:var(--danger);text-align:center;padding:20px">⚠️ Impossibile accedere alla fotocamera</p>
        `;
    }
}

function closeExpiryScanner() {
    if (expiryStream) {
        expiryStream.getTracks().forEach(t => t.stop());
        expiryStream = null;
    }
    closeModal();
}

function captureExpiry() {
    const video = document.getElementById('expiry-video');
    const canvas = document.getElementById('expiry-canvas');
    const img = document.getElementById('expiry-preview-img');
    
    // Crop to center 50% (matching the 2x zoom view) for better AI accuracy
    const sw = video.videoWidth / 2;
    const sh = video.videoHeight / 2;
    const sx = (video.videoWidth - sw) / 2;
    const sy = (video.videoHeight - sh) / 2;
    canvas.width = sw;
    canvas.height = sh;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
    
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    img.src = dataUrl;
    
    // Stop camera
    if (expiryStream) {
        expiryStream.getTracks().forEach(t => t.stop());
        expiryStream = null;
    }
    video.srcObject = null;
    
    document.getElementById('expiry-cam-container').style.display = 'none';
    document.getElementById('expiry-preview-container').style.display = 'block';
    document.getElementById('expiry-capture-btn').style.display = 'none';
    document.getElementById('expiry-retake-btn').style.display = 'block';
    
    // Auto-analyze
    analyzeExpiryImage(dataUrl);
}

function retakeExpiry() {
    document.getElementById('expiry-cam-container').style.display = 'block';
    document.getElementById('expiry-preview-container').style.display = 'none';
    document.getElementById('expiry-capture-btn').style.display = 'block';
    document.getElementById('expiry-retake-btn').style.display = 'none';
    document.getElementById('expiry-scan-status').style.display = 'none';
    
    // Restart camera
    navigator.mediaDevices.getUserMedia(getCameraConstraints()).then(stream => {
        expiryStream = stream;
        const video = document.getElementById('expiry-video');
        video.srcObject = stream;
        video.play();
    }).catch(err => console.error(err));
}

async function analyzeExpiryImage(dataUrl) {
    const statusDiv = document.getElementById('expiry-scan-status');
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<div class="loading-spinner" style="margin:0 auto 8px"></div><p>🤖 Analisi AI in corso...</p>';
    
    try {
        // Remove data:image/jpeg;base64, prefix
        const base64 = dataUrl.split(',')[1];
        
        const result = await api('gemini_expiry', {}, 'POST', { image: base64 });
        
        if (result.success && result.expiry_date) {
            // Auto-fill the expiry date
            const expiryInput = document.getElementById('add-expiry');
            if (expiryInput) {
                expiryInput.value = result.expiry_date;
            }
            statusDiv.innerHTML = `<p style="color:var(--success);font-weight:600">✅ Data trovata: ${formatDate(result.expiry_date)}</p>`;
            
            // Close modal after delay
            setTimeout(() => closeExpiryScanner(), 1500);
        } else if (result.error === 'no_api_key') {
            statusDiv.innerHTML = `<p style="color:var(--warning)">⚠️ Chiave API Gemini non configurata.<br><small>Aggiungi GEMINI_API_KEY nel file .env sul server.</small></p>`;
        } else {
            statusDiv.innerHTML = `<p style="color:var(--danger)">❌ Non riesco a leggere la data. ${result.raw_text ? '<br><small>Letto: ' + escapeHtml(result.raw_text) + '</small>' : ''}</p>
                <button class="btn btn-secondary" onclick="retakeExpiry()" style="margin-top:8px">🔄 Riprova</button>`;
        }
    } catch (err) {
        console.error('Expiry AI error:', err);
        statusDiv.innerHTML = `<p style="color:var(--danger)">❌ Errore di connessione. Riprova.</p>
            <button class="btn btn-secondary" onclick="retakeExpiry()" style="margin-top:8px">🔄 Riprova</button>`;
    }
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateTime(dtStr) {
    if (!dtStr) return '';
    const d = new Date(dtStr.replace(' ', 'T'));
    return d.toLocaleDateString('it-IT', { day: '2-digit', month: 'short' }) + ' ' + 
           d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
}

function daysUntilExpiry(dateStr) {
    if (!dateStr) return Infinity;
    const expiry = new Date(dateStr + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((expiry - today) / 86400000);
}

function adjustQty(inputId, delta) {
    const input = document.getElementById(inputId);
    let val = parseFloat(input.value) || 0;
    val = Math.max(0.1, val + delta);
    input.value = Math.round(val * 10) / 10;
}

function showLoading(show) {
    document.getElementById('loading').style.display = show ? 'flex' : 'none';
}

function showToast(message, type = '') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast show ' + type;
    setTimeout(() => {
        toast.className = 'toast';
    }, 3000);
}

// ===== LOG =====
let _logOffset = 0;
const LOG_PAGE_SIZE = 50;

async function loadLog(more = false) {
    if (!more) {
        _logOffset = 0;
        document.getElementById('log-list').innerHTML = '<p style="text-align:center;color:var(--text-muted)">Caricamento...</p>';
    }

    try {
        const result = await api(`transactions_list&limit=${LOG_PAGE_SIZE}&offset=${_logOffset}`);
        const txns = result.transactions || [];

        let html = '';
        if (!more && txns.length === 0) {
            html = '<p style="text-align:center;color:var(--text-muted)">Nessuna operazione registrata.</p>';
        } else {
            let lastDate = more ? '' : null;
            txns.forEach(t => {
                const dt = new Date(t.created_at + 'Z');
                const dateStr = dt.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                const timeStr = dt.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });

                if (dateStr !== lastDate) {
                    html += `<div class="log-date-header">${dateStr}</div>`;
                    lastDate = dateStr;
                }

                let icon, typeLabel, colorClass;
                if (t.type === 'bring') {
                    icon = '🛒';
                    typeLabel = 'Aggiunto a Bring!';
                    colorClass = 'log-bring';
                } else if (t.type === 'in') {
                    icon = '➕';
                    typeLabel = 'Aggiunto';
                    colorClass = 'log-in';
                } else {
                    icon = '➖';
                    typeLabel = 'Usato';
                    colorClass = 'log-out';
                }
                const brand = t.brand ? ` <em>(${t.brand})</em>` : '';
                const loc = t.location || '';
                const locLabels = { 'frigo': '🧊 Frigo', 'freezer': '❄️ Freezer', 'dispensa': '🗄️ Dispensa' };
                const locStr = t.type === 'bring' ? '' : (locLabels[loc] || ('📍 ' + loc));
                const notes = t.notes ? ` · ${t.notes}` : '';

                html += `<div class="log-entry ${colorClass}">`;
                html += `<span class="log-icon">${icon}</span>`;
                html += `<div class="log-info">`;
                html += `<div class="log-product"><strong>${t.name}</strong>${brand}</div>`;
                html += `<div class="log-detail">${typeLabel} ${t.type !== 'bring' ? t.quantity + ' ' + (t.unit || '') + ' · ' : ''}${locStr}${notes} · ${timeStr}</div>`;
                html += `</div>`;
                html += `</div>`;
            });
        }

        if (more) {
            document.getElementById('log-list').insertAdjacentHTML('beforeend', html);
        } else {
            document.getElementById('log-list').innerHTML = html;
        }

        _logOffset += txns.length;
        document.getElementById('log-load-more').style.display = txns.length >= LOG_PAGE_SIZE ? '' : 'none';

    } catch (err) {
        console.error('Log load error:', err);
        if (!more) document.getElementById('log-list').innerHTML = '<p style="text-align:center;color:var(--danger)">Errore nel caricamento log</p>';
    }
}

// ===== WEEKLY MEAL PLAN =====

/**
 * All selectable meal categories per slot.
 * id must be URL-safe; icon + label shown in UI.
 */
const MEAL_PLAN_TYPES = [
    { id: 'pasta',      icon: '🍝', label: 'Pasta' },
    { id: 'riso',       icon: '🍚', label: 'Riso' },
    { id: 'carne',      icon: '🥩', label: 'Carne' },
    { id: 'pesce',      icon: '🐟', label: 'Pesce' },
    { id: 'legumi',     icon: '🫘', label: 'Legumi' },
    { id: 'uova',       icon: '🥚', label: 'Uova' },
    { id: 'formaggio',  icon: '🧀', label: 'Formaggio' },
    { id: 'pizza',      icon: '🍕', label: 'Pizza' },
    { id: 'affettati',  icon: '🥓', label: 'Affettati' },
    { id: 'verdure',    icon: '🥦', label: 'Verdure' },
    { id: 'zuppa',      icon: '🍲', label: 'Zuppa' },
    { id: 'insalata',   icon: '🥗', label: 'Insalata' },
    { id: 'pane',       icon: '🥪', label: 'Pane/Sandwich' },
    { id: 'dolce',      icon: '🍰', label: 'Dolce' },
    { id: 'libero',     icon: '🎲', label: 'Libero' },
];

const MEAL_PLAN_TYPE_MAP = {};
MEAL_PLAN_TYPES.forEach(t => { MEAL_PLAN_TYPE_MAP[t.id] = t; });

const WEEK_DAYS = ['Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato','Domenica'];
const WEEK_DAYS_SHORT = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];

/** Default weekly plan as requested. */
const DEFAULT_MEAL_PLAN = {
    1: { pranzo: 'pasta',   cena: 'pesce' },
    2: { pranzo: 'riso',    cena: 'carne' },
    3: { pranzo: 'legumi',  cena: 'uova' },
    4: { pranzo: 'pasta',   cena: 'pesce' },
    5: { pranzo: 'riso',    cena: 'formaggio' },
    6: { pranzo: 'legumi',  cena: 'pizza' },
    0: { pranzo: 'carne',   cena: 'affettati' },  // 0 = Sunday (getDay())
};

function getMealPlan() {
    const s = getSettings();
    return s.meal_plan || DEFAULT_MEAL_PLAN;
}

/** Return today's planned meal type for a given slot ('pranzo'|'cena'), or null. */
function getTodayMealPlanType(slot) {
    const s = getSettings();
    if (s.meal_plan_enabled === false) return null;
    const dow = new Date().getDay(); // 0=Sun,1=Mon,...,6=Sat
    const plan = getMealPlan();
    return plan[dow]?.[slot] || null;
}

/** Toggle handler for the enable/disable switch in settings. */
function onMealPlanEnabledChange(el) {
    const s = getSettings();
    s.meal_plan_enabled = el.checked;
    saveSettingsToStorage(s);
    const mpConfigSection = document.getElementById('meal-plan-config-section');
    if (mpConfigSection) mpConfigSection.style.display = el.checked ? '' : 'none';
    const mpLegendCard = document.getElementById('meal-plan-legend-card');
    if (mpLegendCard) mpLegendCard.style.display = el.checked ? '' : 'none';
    // Close picker if open
    const picker = document.getElementById('meal-plan-picker');
    if (picker) picker.style.display = 'none';
}

/**
 * Render the weekly meal plan editor into #meal-plan-grid.
 * Each cell shows the current type badge + a picker dropdown.
 */
function renderMealPlanEditor() {
    const container = document.getElementById('meal-plan-grid');
    if (!container) return;
    const plan = getMealPlan();
    // JS getDay: 0=Sun … but we display Mon-Sun (1..6,0)
    const dayOrder = [1,2,3,4,5,6,0];
    const today = new Date().getDay();

    const header = `<div class="mplan-header">
        <span class="mplan-col-header">🌤️ Pranzo</span>
        <span class="mplan-col-header">🌙 Cena</span>
    </div>`;

    const rows = dayOrder.map((dow, i) => {
        const pranzo = plan[dow]?.pranzo || 'libero';
        const cena   = plan[dow]?.cena   || 'libero';
        const pt = MEAL_PLAN_TYPE_MAP[pranzo] || MEAL_PLAN_TYPE_MAP.libero;
        const ct = MEAL_PLAN_TYPE_MAP[cena]   || MEAL_PLAN_TYPE_MAP.libero;
        const todayClass = dow === today ? ' mplan-row-today' : '';
        return `<div class="mplan-row${todayClass}">
            <div class="mplan-day-name">${WEEK_DAYS_SHORT[i]}</div>
            <span class="mplan-badge mplan-badge-pranzo" onclick="openMealPlanPicker(${dow},'pranzo',this)">${pt.icon} ${pt.label}</span>
            <span class="mplan-badge mplan-badge-cena" onclick="openMealPlanPicker(${dow},'cena',this)">${ct.icon} ${ct.label}</span>
        </div>`;
    }).join('');

    container.innerHTML = header + rows;
}

let _mplanPickerTarget = null; // {dow, slot, badgeEl}
function openMealPlanPicker(dow, slot, badgeEl) {
    // Close any open picker first
    closeMealPlanPicker();
    _mplanPickerTarget = { dow, slot, badgeEl };
    const picker = document.getElementById('meal-plan-picker');
    if (!picker) return;
    const plan = getMealPlan();
    const current = plan[dow]?.[slot] || 'libero';
    picker.innerHTML = MEAL_PLAN_TYPES.map(t =>
        `<button class="mplan-pick-btn${t.id === current ? ' active' : ''}" onclick="selectMealPlanType(${dow},'${slot}','${t.id}')">${t.icon} ${t.label}</button>`
    ).join('');
    // Position vertically near the badge, centered horizontally (CSS handles centering)
    const rect = badgeEl.getBoundingClientRect();
    const pickerEl = picker;
    // Show first to measure height
    pickerEl.style.display = 'flex';
    const pickerH = pickerEl.offsetHeight || 160;
    const spaceBelow = window.innerHeight - rect.bottom - 8;
    const top = spaceBelow >= pickerH
        ? rect.bottom + 8
        : Math.max(8, rect.top - pickerH - 8);
    pickerEl.style.top = top + 'px';
    // Close on outside tap
    setTimeout(() => document.addEventListener('click', _mplanPickerOutside, { once: true }), 0);
}
function _mplanPickerOutside(e) {
    const picker = document.getElementById('meal-plan-picker');
    if (picker && !picker.contains(e.target)) closeMealPlanPicker();
}
function closeMealPlanPicker() {
    const picker = document.getElementById('meal-plan-picker');
    if (picker) picker.style.display = 'none';
    _mplanPickerTarget = null;
    document.removeEventListener('click', _mplanPickerOutside);
}
function selectMealPlanType(dow, slot, typeId) {
    const s = getSettings();
    if (!s.meal_plan) s.meal_plan = JSON.parse(JSON.stringify(DEFAULT_MEAL_PLAN));
    if (!s.meal_plan[dow]) s.meal_plan[dow] = {};
    s.meal_plan[dow][slot] = typeId;
    saveSettingsToStorage(s);
    closeMealPlanPicker();
    renderMealPlanEditor();
}
function resetMealPlan() {
    const s = getSettings();
    s.meal_plan = JSON.parse(JSON.stringify(DEFAULT_MEAL_PLAN));
    saveSettingsToStorage(s);
    renderMealPlanEditor();
    showToast('Piano settimanale ripristinato', 'success');
}

// ===== RECIPE GENERATION =====
const MEAL_TYPES = [
    { id: 'colazione',  icon: '☀️', label: 'Colazione',       from: 6,  to: 11 },
    { id: 'pranzo',     icon: '🍽️', label: 'Pranzo',           from: 11, to: 14 },
    { id: 'merenda',    icon: '🍪', label: 'Merenda',          from: 14, to: 17 },
    { id: 'cena',       icon: '🌙', label: 'Cena',             from: 17, to: 6  },
    { id: 'dolce',      icon: '🍰', label: 'Dolce',            from: -1, to: -1 },
    { id: 'succo',      icon: '🧃', label: 'Succo di Frutta',  from: -1, to: -1 },
];

function getMealType() {
    const hour = new Date().getHours();
    for (const m of MEAL_TYPES) {
        if (m.from < m.to) { if (hour >= m.from && hour < m.to) return m.id; }
        else { if (hour >= m.from || hour < m.to) return m.id; }
    }
    return 'cena';
}

const MEAL_LABELS = {};
MEAL_TYPES.forEach(m => { MEAL_LABELS[m.id] = `${m.icon} ${m.label}`; });

function getSelectedMealType() {
    const checked = document.querySelector('input[name="recipe-meal"]:checked');
    return checked ? checked.value : getMealType();
}

// ===== RECIPE ARCHIVE (DB-backed) =====
let _recipeArchiveCache = null;

async function getRecipeArchive() {
    if (_recipeArchiveCache !== null) return _recipeArchiveCache;
    try {
        const res = await api('recipes_list');
        if (res.success) {
            _recipeArchiveCache = res.recipes || [];
            return _recipeArchiveCache;
        }
    } catch(e) { console.warn('Failed to load recipes from DB:', e); }
    return [];
}

async function saveRecipeToArchive(recipe) {
    const today = new Date().toISOString().slice(0, 10);
    try {
        await api('recipes_save', {}, 'POST', { date: today, meal: recipe.meal, recipe });
        // Invalidate cache and refresh the archive list
        _recipeArchiveCache = null;
        loadRecipeArchive();
    } catch(e) { console.error('Failed to save recipe:', e); }
}

async function getTodayRecipeTitles() {
    const archive = await getRecipeArchive();
    const today = new Date().toISOString().slice(0, 10);
    return archive
        .filter(e => e.date === today && e.recipe && e.recipe.title)
        .map(e => e.recipe.title);
}

let _recipeArchiveEntries = [];

async function loadRecipeArchive() {
    const container = document.getElementById('recipe-archive');
    if (!container) return;
    const archive = await getRecipeArchive();
    _recipeArchiveEntries = archive;
    
    if (archive.length === 0) {
        container.innerHTML = '<div class="empty-state" style="padding:20px"><div class="empty-state-icon">🍳</div><p>Nessuna ricetta salvata.<br>Genera la tua prima ricetta!</p></div>';
        return;
    }
    
    // Group by date
    const byDate = {};
    for (const entry of archive) {
        if (!byDate[entry.date]) byDate[entry.date] = [];
        byDate[entry.date].push(entry);
    }
    
    let html = '';
    let flatIdx = 0;
    const today = new Date().toISOString().slice(0, 10);
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    
    for (const [date, entries] of Object.entries(byDate)) {
        let dateLabel = new Date(date + 'T12:00:00').toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
        if (date === today) dateLabel = '📅 Oggi';
        else if (date === yesterday) dateLabel = '📅 Ieri';
        
        html += `<div class="recipe-archive-day">`;
        html += `<div class="recipe-archive-date">${escapeHtml(dateLabel)}</div>`;
        
        for (const entry of entries) {
            const r = entry.recipe;
            const mealIcon = MEAL_LABELS[r.meal] || r.meal;
            const tags = (r.tags || []).slice(0, 3).join(', ');
            // Find this entry's index in the flat archive array
            const archiveIdx = archive.indexOf(entry);
            html += `<div class="recipe-archive-card" onclick="viewArchivedRecipe(${archiveIdx})">`;
            html += `<div class="recipe-archive-card-header">`;
            html += `<span class="recipe-archive-meal">${mealIcon}</span>`;
            html += `<span class="recipe-archive-title">${escapeHtml(r.title)}</span>`;
            html += `</div>`;
            html += `<div class="recipe-archive-card-meta">`;
            if (r.prep_time) html += `<span>🔪 ${r.prep_time}</span>`;
            if (r.cook_time) html += `<span>🔥 ${r.cook_time}</span>`;
            html += `<span>👥 ${r.persons}</span>`;
            if (tags) html += `<span>${tags}</span>`;
            html += `</div></div>`;
            flatIdx++;
        }
        html += `</div>`;
    }
    
    container.innerHTML = html;
}

function viewArchivedRecipe(idx) {
    const entry = _recipeArchiveEntries[idx];
    if (!entry) return;
    _cachedRecipe = { meal: entry.meal, recipe: entry.recipe };
    renderRecipe(entry.recipe);
    document.getElementById('recipe-overlay').style.display = 'flex';
    document.getElementById('recipe-ask').style.display = 'none';
    document.getElementById('recipe-loading').style.display = 'none';
    document.getElementById('recipe-result').style.display = '';
}

let _cachedRecipe = null;
let _generatedTodayTitles = []; // client-side list, robust vs race conditions
let _recipeVariationCount = {}; // { 'pranzo': 0, 'cena': 1, ... }

function openRecipeDialog() {
    const meal = getMealType();
    const settings = getSettings();
    document.getElementById('recipe-overlay').style.display = 'flex';

    // Build meal selector radios
    const mealGrid = document.getElementById('recipe-meal-grid');
    if (mealGrid) {
        mealGrid.innerHTML = MEAL_TYPES.map(m => {
            const checked = m.id === meal ? ' checked' : '';
            return `<label class="recipe-meal-chip"><input type="radio" name="recipe-meal" value="${m.id}"${checked}> ${m.icon} ${m.label}</label>`;
        }).join('');
    }
    updateRecipeMealTitle();

    // Show today's meal plan hint
    _renderMealPlanHint(meal);

    // Check for cached recipe matching current meal type
    if (_cachedRecipe && _cachedRecipe.meal === meal && _cachedRecipe.recipe) {
        document.getElementById('recipe-ask').style.display = 'none';
        document.getElementById('recipe-loading').style.display = 'none';
        renderRecipe(_cachedRecipe.recipe);
        document.getElementById('recipe-result').style.display = '';
        return;
    }

    // Pre-fill persons from settings
    document.getElementById('recipe-persons').value = settings.default_persons || 1;
    
    // Pre-select option chips from settings
    const prefMap = {
        'veloce': 'recipe-opt-veloce',
        'pocafame': 'recipe-opt-pocafame', 
        'scadenze': 'recipe-opt-scadenze',
        'salutare': 'recipe-opt-healthy',
        'opened': 'recipe-opt-opened',
        'zerowaste': 'recipe-opt-zerowaste'
    };
    Object.entries(prefMap).forEach(([key, id]) => {
        const cb = document.getElementById(id);
        if (cb) cb.checked = settings.recipe_prefs && settings.recipe_prefs.includes(key);
    });
    
    document.getElementById('recipe-ask').style.display = '';
    document.getElementById('recipe-loading').style.display = 'none';
    document.getElementById('recipe-result').style.display = 'none';
}

// Toggle recipe option chip
function toggleRecipeOption(btn) {
    btn.classList.toggle('active');
}

function closeRecipeDialog() {
    document.getElementById('recipe-overlay').style.display = 'none';
}

function adjustRecipePersons(delta) {
    const input = document.getElementById('recipe-persons');
    let val = parseInt(input.value) || 1;
    val = Math.max(1, Math.min(20, val + delta));
    input.value = val;
}

let _recipeUseContext = null; // { idx, productId, btn, qtyNumber }
let _recipeUseConfMode = null;
let _recipeUseNormalUnit = 'pz';

async function useRecipeIngredient(idx, productId, location, qtyNumber, btn) {
    if (btn.disabled) return;
    if (!qtyNumber || qtyNumber <= 0) qtyNumber = 1;
    
    _recipeUseContext = { idx, productId, btn, qtyNumber };
    _recipeUseConfMode = null;
    
    // Fetch inventory to build the modal
    try {
        const data = await api('inventory_list');
        const items = (data.inventory || []).filter(i => i.product_id == productId);
        
        if (items.length === 0) {
            showToast('⚠️ Prodotto non trovato in inventario', 'error');
            return;
        }
        
        const unit = items[0].unit || 'pz';
        const pkgSize = parseFloat(items[0].default_quantity) || 0;
        const pkgUnit = items[0].package_unit || '';
        const isConf = unit === 'conf' && pkgSize > 0 && pkgUnit;
        
        // Find opened package location
        const openedItem = items.find(i => {
            const q = parseFloat(i.quantity);
            const dq = parseFloat(i.default_quantity) || 0;
            if (i.unit === 'conf' && dq > 0) return q !== Math.floor(q);
            if (dq > 0) return Math.abs(q - Math.round(q / dq) * dq) > dq * 0.02;
            return false;
        });
        const defaultLoc = openedItem ? openedItem.location : (items.find(i => i.location === location) ? location : items[0].location);
        
        // Build location buttons
        const productLocations = [...new Set(items.map(i => i.location))];
        const locButtons = productLocations.map(loc => {
            const locInfo = LOCATIONS[loc] || { icon: '📦', label: loc };
            const locItems = items.filter(i => i.location === loc);
            const locQty = locItems.reduce((s, i) => s + parseFloat(i.quantity), 0);
            const qtyLabel = formatQuantity(locQty, unit, pkgSize, pkgUnit);
            return `<button type="button" class="loc-btn ${loc === defaultLoc ? 'active' : ''}" onclick="selectRecipeUseLoc(this, '${loc}')">${locInfo.icon} ${locInfo.label} (${qtyLabel})</button>`;
        }).join('');
        
        // Build quantity controls
        let qtySection = '';
        let defaultQtyValue = qtyNumber;
        
        if (isConf) {
            const totalConf = items.reduce((s, i) => s + parseFloat(i.quantity), 0);
            const totalSub = totalConf * pkgSize;
            const unitLabels = { 'ml': 'ml', 'g': 'g', 'pz': 'pz' };
            const subLabel = unitLabels[pkgUnit] || pkgUnit;
            _recipeUseConfMode = { packageSize: pkgSize, packageUnit: pkgUnit, totalSub, totalConf, subLabel, _activeUnit: 'sub' };
            
            // qtyNumber from recipe is in sub-units (g, ml)
            const step = getSubUnitStep(pkgUnit);
            defaultQtyValue = qtyNumber;
            
            qtySection = `
                <div class="use-unit-switch" style="display:flex;margin-bottom:8px">
                    <button type="button" class="use-unit-btn active" id="ruse-unit-sub" onclick="switchRecipeUseUnit('sub')">${subLabel}</button>
                    <button type="button" class="use-unit-btn" id="ruse-unit-conf" onclick="switchRecipeUseUnit('conf')">Confezioni</button>
                </div>
                <p id="ruse-hint" style="font-size:0.85rem;color:var(--text-muted);margin-bottom:8px">Quantità in ${subLabel} (totale: ${Math.round(totalSub)}${subLabel})</p>
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(-1)">−</button>
                    <input type="number" id="ruse-quantity" value="${defaultQtyValue}" min="${step}" step="${step}" class="qty-input">
                    <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(1)">+</button>
                </div>`;
        } else {
            _recipeUseNormalUnit = unit;
            const unitLabels = { 'pz': 'pz', 'g': 'g', 'ml': 'ml' };
            const unitLabel = unitLabels[unit] || unit;
            const inputMin = '0.1';
            qtySection = `
                <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:8px">Quantità da usare (${unitLabel}):</p>
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(-1)">−</button>
                    <input type="number" id="ruse-quantity" value="${defaultQtyValue}" min="${inputMin}" step="any" class="qty-input">
                    <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(1)">+</button>
                </div>`;
        }
        
        // Available info
        const availInfo = items.map(i => {
            const loc = LOCATIONS[i.location] || { icon: '📦', label: i.location };
            return `${loc.icon} ${formatQuantity(i.quantity, i.unit, i.default_quantity, i.package_unit)}`;
        }).join(' · ');
        
        document.getElementById('modal-content').innerHTML = `
            <div class="modal-header">
                <h3>📤 Usa ingrediente</h3>
                <button class="modal-close" onclick="closeModal()">✕</button>
            </div>
            <div style="padding:0 16px 16px">
                <p style="margin-bottom:8px;font-weight:600">${escapeHtml(items[0].name)}</p>
                <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:12px">📦 ${availInfo}</p>
                <div class="form-group">
                    <label>📍 Da dove?</label>
                    <div class="location-selector">${locButtons}</div>
                    <input type="hidden" id="ruse-location" value="${defaultLoc}">
                </div>
                <div class="form-group">
                    <label>Quanto?</label>
                    ${qtySection}
                </div>
                <button type="button" class="btn btn-large btn-danger full-width" onclick="submitRecipeUse(false)" style="margin-top:8px">
                    📤 Usa questa quantità
                </button>
                <button type="button" class="btn btn-large btn-secondary full-width" style="margin-top:8px" onclick="submitRecipeUse(true)">
                    🗑️ Usa TUTTO / Finito
                </button>
            </div>
        `;
        document.getElementById('modal-overlay').style.display = 'flex';
        
    } catch (err) {
        console.error('useRecipeIngredient error:', err);
        showToast('Errore nel caricamento', 'error');
    }
}

function selectRecipeUseLoc(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('ruse-location').value = loc;
}

function switchRecipeUseUnit(mode) {
    if (!_recipeUseConfMode) return;
    const subBtn = document.getElementById('ruse-unit-sub');
    const confBtn = document.getElementById('ruse-unit-conf');
    const qtyInput = document.getElementById('ruse-quantity');
    const hint = document.getElementById('ruse-hint');
    
    if (mode === 'sub') {
        subBtn.classList.add('active');
        confBtn.classList.remove('active');
        _recipeUseConfMode._activeUnit = 'sub';
        const step = getSubUnitStep(_recipeUseConfMode.packageUnit);
        qtyInput.value = _recipeUseContext.qtyNumber || step;
        qtyInput.step = step;
        qtyInput.min = step;
        hint.textContent = `Quantità in ${_recipeUseConfMode.subLabel} (totale: ${Math.round(_recipeUseConfMode.totalSub)}${_recipeUseConfMode.subLabel})`;
    } else {
        confBtn.classList.add('active');
        subBtn.classList.remove('active');
        _recipeUseConfMode._activeUnit = 'conf';
        qtyInput.value = 1;
        qtyInput.step = 0.5;
        qtyInput.min = 0.5;
        hint.textContent = `Confezioni da ${_recipeUseConfMode.packageSize}${_recipeUseConfMode.subLabel} (hai ${_recipeUseConfMode.totalConf.toFixed(1)} conf)`;
    }
}

function adjustRecipeUseQty(direction) {
    const input = document.getElementById('ruse-quantity');
    let val = parseFloat(input.value) || 0;
    let step;
    if (_recipeUseConfMode && _recipeUseConfMode._activeUnit === 'sub') {
        step = getSubUnitStep(_recipeUseConfMode.packageUnit);
    } else if (_recipeUseConfMode && _recipeUseConfMode._activeUnit === 'conf') {
        step = 0.5;
    } else {
        const u = _recipeUseNormalUnit || 'pz';
        if (u === 'g' || u === 'ml') {
            step = val < 50 ? 1 : (val < 500 ? 10 : 50);
        } else {
            step = 1;
        }
    }
    val = Math.max(step, val + direction * step);
    input.value = Math.round(val * 1000) / 1000;
}

async function submitRecipeUse(useAll) {
    if (!_recipeUseContext) return;
    const { idx, productId, btn } = _recipeUseContext;
    const location = document.getElementById('ruse-location').value;
    
    let qty;
    if (useAll) {
        qty = 0; // API handles use_all
    } else {
        qty = parseFloat(document.getElementById('ruse-quantity').value) || 1;
        if (_recipeUseConfMode && _recipeUseConfMode._activeUnit === 'sub') {
            qty = qty / _recipeUseConfMode.packageSize;
        }
    }
    
    closeModal();
    btn.disabled = true;
    btn.textContent = '⏳...';
    
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: productId,
            quantity: qty,
            use_all: useAll,
            location: location
        });
        
        if (result.success) {
            const li = document.getElementById(`recipe-ing-${idx}`);
            if (li) li.classList.add('recipe-ing-used');
            btn.textContent = '✔️ Scalato';
            btn.classList.add('btn-used');
            
            if (_cachedRecipe && _cachedRecipe.recipe && _cachedRecipe.recipe.ingredients && _cachedRecipe.recipe.ingredients[idx]) {
                _cachedRecipe.recipe.ingredients[idx].used = true;
                // Persist used state to DB
                saveRecipeToArchive(_cachedRecipe.recipe);
            }
            
            showToast('📦 Ingrediente scalato dalla dispensa!', 'success');
            if (result.added_to_bring) {
                setTimeout(() => showToast('🛒 Prodotto finito → aggiunto a Bring!', 'info'), 1500);
            }
            
            // Check low stock → Bring! prompt, then offer move
            const moveCallback = result.remaining > 0
                ? () => setTimeout(() => showRecipeMoveModal(productId, location, result.remaining, result.opened_id), 300)
                : null;
            setTimeout(() => showLowStockBringPrompt(result, moveCallback), 300);
        } else {
            btn.disabled = false;
            btn.textContent = '📦 Usa';
            showToast(result.error || 'Errore nello scalare', 'error');
        }
    } catch (err) {
        console.error('Recipe use error:', err);
        btn.disabled = false;
        btn.textContent = '📦 Usa';
        showToast('Errore di connessione', 'error');
    }
    _recipeUseContext = null;
}

function showRecipeMoveModal(productId, fromLoc, remaining, openedId) {
    const otherLocs = Object.entries(LOCATIONS).filter(([k]) => k !== fromLoc);
    const locButtons = otherLocs.map(([k, v]) =>
        `<button type="button" class="loc-btn" onclick="clearMoveModalTimer();confirmRecipeMove(${productId}, '${fromLoc}', '${k}', ${openedId || 0})">${v.icon} ${v.label}</button>`
    ).join('');
    
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>📦 Spostare il resto?</h3>
            <button class="modal-close" onclick="clearMoveModalTimer();closeModal()">✕</button>
        </div>
        <div style="padding:0 16px 16px">
            <p style="margin-bottom:12px">Vuoi spostare ${openedId ? 'la confezione aperta' : 'il resto'} in un'altra posizione?</p>
            <div class="location-selector">${locButtons}</div>
            <button type="button" id="btn-move-stay" class="btn btn-secondary full-width move-countdown-btn" style="margin-top:12px" onclick="clearMoveModalTimer();closeModal()">No, resta in ${LOCATIONS[fromLoc]?.label || fromLoc}</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    startMoveModalCountdown('btn-move-stay', () => { closeModal(); });
}

async function confirmRecipeMove(productId, fromLoc, toLoc, openedId) {
    clearMoveModalTimer();
    closeModal();
    try {
        if (openedId) {
            let days = estimateExpiryDays({ name: '', category: '' }, toLoc);
            await api('inventory_update', {}, 'POST', {
                id: openedId,
                location: toLoc,
                expiry_date: addDays(days),
                product_id: productId,
            });
        } else {
            const data = await api('inventory_list');
            const item = (data.inventory || []).find(i => i.product_id == productId && i.location === fromLoc && parseFloat(i.quantity) > 0);
            if (item) {
                let days = estimateExpiryDays({ name: item.name || '', category: item.category || '' }, toLoc);
                if (item.vacuum_sealed) days = getVacuumExpiryDays(days);
                await api('inventory_update', {}, 'POST', {
                    id: item.id,
                    location: toLoc,
                    expiry_date: addDays(days),
                    product_id: productId,
                });
            }
        }
        showToast(`📦 Spostato in ${LOCATIONS[toLoc]?.label || toLoc}`, 'success');
    } catch (e) {
        console.error('Recipe move error:', e);
    }
}

function renderRecipe(r) {
    let html = `<h2>${r.title}</h2>`;

    // Meta tags
    html += '<div class="recipe-meta">';
    html += `<span class="recipe-tag">${MEAL_LABELS[r.meal] || r.meal}</span>`;
    html += `<span class="recipe-tag">👥 ${r.persons} pers.</span>`;
    if (r.prep_time) html += `<span class="recipe-tag">🔪 ${r.prep_time}</span>`;
    if (r.cook_time) html += `<span class="recipe-tag">🔥 ${r.cook_time}</span>`;
    if (r.tags) r.tags.forEach(t => { html += `<span class="recipe-tag">${t}</span>`; });
    html += '</div>';

    // Expiry note
    if (r.expiry_note) {
        html += `<div class="recipe-expiry-note">⚠️ ${r.expiry_note}</div>`;
    }

    // Ingredients
    html += '<h3>🧾 Ingredienti</h3><ul class="recipe-ingredients">';
    (r.ingredients || []).forEach((ing, idx) => {
        if (ing.from_pantry && ing.product_id) {
            const qtyNum = ing.qty_number || 0;
            const loc = (ing.location || 'dispensa').replace(/'/g, "\\'");
            const alreadyUsed = ing.used === true;
            html += `<li class="recipe-ingredient${alreadyUsed ? ' recipe-ing-used' : ''}" id="recipe-ing-${idx}">`;
            html += `<span class="recipe-ing-text"><strong>${ing.name}</strong>${ing.brand ? ' <em>(' + ing.brand + ')</em>' : ''}: ${ing.qty} ✅`;
            // Detail line: location + expiry
            let details = [];
            const locLabels = { 'frigo': '🧊 Frigo', 'freezer': '🧊 Freezer', 'dispensa': '🗄️ Dispensa' };
            details.push(locLabels[ing.location] || ('📍 ' + ing.location));
            if (ing.expiry_date) {
                const exp = new Date(ing.expiry_date);
                const now = new Date(); now.setHours(0,0,0,0);
                const diffDays = Math.round((exp - now) / 86400000);
                if (diffDays < 0) details.push(`⛔ Scaduto da ${Math.abs(diffDays)}g`);
                else if (diffDays <= 3) details.push(`🔴 Scade tra ${diffDays}g`);
                else if (diffDays <= 7) details.push(`🟡 Scade tra ${diffDays}g`);
                else details.push(`📅 ${exp.toLocaleDateString('it-IT')}`);
            }
            if (details.length) html += `<br><small class="recipe-ing-detail">${details.join(' · ')}</small>`;
            html += `</span>`;
            if (alreadyUsed) {
                html += `<button class="btn-use-ingredient btn-used" disabled>✔️ Scalato</button>`;
            } else {
                html += `<button class="btn-use-ingredient" onclick="useRecipeIngredient(${idx}, ${ing.product_id}, '${loc}', ${qtyNum}, this)" title="Scala dalla dispensa">📦 Usa</button>`;
            }
            html += `</li>`;
        } else {
            const pantryIcon = ing.from_pantry ? ' ✅' : ' 🛒';
            html += `<li class="recipe-ingredient"><span class="recipe-ing-text"><strong>${ing.name}</strong>: ${ing.qty}${pantryIcon}</span></li>`;
        }
    });
    html += '</ul>';

    // Steps
    html += '<h3>👨‍🍳 Procedimento</h3><ol>';
    (r.steps || []).forEach(step => {
        const cleanStep = step.replace(/^Passo\s*\d+\s*:\s*/i, '');
        html += `<li>${cleanStep}</li>`;
    });
    html += '</ol>';

    // Nutrition note
    if (r.nutrition_note) {
        html += `<p style="color:var(--text-muted);font-size:0.85rem;margin-top:12px">💡 ${r.nutrition_note}</p>`;
    }

    document.getElementById('recipe-content').innerHTML = html;
}

// ===== COOKING MODE =====
let _cookingRecipe = null;
let _cookingStep = 0;
let _cookingTTS = true;
let _cookingVisited = new Set(); // indices of steps already seen

function startCookingMode() {
    const recipe = _cachedRecipe && _cachedRecipe.recipe ? _cachedRecipe.recipe : null;
    if (!recipe || !(recipe.steps || []).length) {
        showToast('Nessun procedimento disponibile', 'info');
        return;
    }
    // Resume if same recipe; otherwise start fresh
    const isSame = _cookingRecipe && _cookingRecipe.title === recipe.title;
    if (!isSame) {
        _cookingRecipe = JSON.parse(JSON.stringify(recipe));
        _cookingStep = 0;
        _cookingVisited = new Set();
        clearAllCookingTimers();
    }
    _cookingTTS = true;
    document.getElementById('cooking-title').textContent = _cookingRecipe.title || '';
    document.getElementById('cooking-tts-btn').textContent = '🔊';
    document.getElementById('cooking-overlay').style.display = 'flex';
    document.body.classList.add('cooking-mode-active');
    try { screen.orientation?.lock('portrait'); } catch (_) { /* ignore */ }
    renderCookingStep();
}
function closeCookingMode() {
    document.getElementById('cooking-overlay').style.display = 'none';
    document.body.classList.remove('cooking-mode-active');
    // NOTE: intentionally keep _cookingRecipe, _cookingStep, _cookingVisited
    // so the user can resume from the same step when they reopen
    try { screen.orientation?.unlock(); } catch (_) { /* ignore */ }
}

function restartCookingMode() {
    _cookingStep = 0;
    _cookingVisited = new Set();
    clearAllCookingTimers();
    renderCookingStep();
}

function renderCookingStep() {
    if (!_cookingRecipe) return;
    const steps = _cookingRecipe.steps || [];
    const step = steps[_cookingStep] || '';
    const cleanStep = step.replace(/^Passo\s*\d+\s*[:.]\s*/i, '');
    const total = steps.length;

    // Mark current step as visited
    _cookingVisited.add(_cookingStep);

    document.getElementById('cooking-step-num').textContent = `${_cookingStep + 1} / ${total}`;
    document.getElementById('cooking-step-text').textContent = cleanStep;

    // Progress dots
    const dotsEl = document.getElementById('cooking-progress-dots');
    if (dotsEl) {
        dotsEl.innerHTML = Array.from({ length: total }, (_, i) => {
            let cls = 'cprog-dot';
            if (i === _cookingStep) cls += ' current';
            else if (_cookingVisited.has(i)) cls += ' visited';
            return `<span class="${cls}"></span>`;
        }).join('');
    }

    // Find pantry ingredients that appear in this step's text and haven't been used yet
    const stepLower = cleanStep.toLowerCase();
    const ings = (_cookingRecipe.ingredients || [])
        .map((ing, idx) => ({ ...ing, _idx: idx }))
        .filter(ing => ing.from_pantry && ing.product_id && ing.used !== true)
        .filter(ing => {
            const name = (ing.name || '').toLowerCase();
            return name.split(' ').some(word => word.length > 2 && stepLower.includes(word));
        });

    const ingsEl = document.getElementById('cooking-step-ings');
    if (ings.length > 0) {
        const LOC_LABELS = { dispensa: '🏠 Dispensa', frigo: '❄️ Frigo', freezer: '🧊 Freezer' };
        ingsEl.innerHTML = ings.map(ing => {
            const loc = (ing.location || 'dispensa').replace(/'/g, "\\'");
            const qtyNum = ing.qty_number || 0;
            // Build info chips: brand, location, expiry
            const chips = [];
            if (ing.brand) chips.push(`<span class="cooking-ing-chip">${escapeHtml(ing.brand)}</span>`);
            const locLabel = LOC_LABELS[ing.location] || (ing.location ? `📍 ${ing.location}` : '🏠 Dispensa');
            chips.push(`<span class="cooking-ing-chip">${locLabel}</span>`);
            if (ing.expiry_date) {
                const daysLeft = Math.round((new Date(ing.expiry_date) - new Date()) / 86400000);
                const expClass = daysLeft <= 3 ? 'exp-soon' : daysLeft <= 7 ? 'exp-close' : '';
                chips.push(`<span class="cooking-ing-chip ${expClass}">📅 scade ${formatDate(ing.expiry_date)}</span>`);
            }
            return `<div class="cooking-ing-row">
                <div style="flex:1;min-width:0">
                    <span class="cooking-ing-name">📦 <strong>${escapeHtml(ing.name)}</strong>: ${escapeHtml(ing.qty)}</span>
                    <div class="cooking-ing-meta">${chips.join('')}</div>
                </div>
                <button class="cooking-use-btn" onclick="cookingUseIngredient(${ing._idx}, ${ing.product_id}, '${loc}', ${qtyNum}, this)">📤 Usa</button>
            </div>`;
        }).join('');
        ingsEl.style.display = 'flex';
    } else {
        ingsEl.innerHTML = '';
        ingsEl.style.display = 'none';
    }

    // Navigation button states
    const prevBtn = document.getElementById('cooking-prev');
    const nextBtn = document.getElementById('cooking-next');
    prevBtn.disabled = _cookingStep === 0;
    nextBtn.textContent = _cookingStep === total - 1 ? '✅ Fine' : 'Successivo ▶';

    // Timer: detect duration in step text and show suggestion
    setupCookingTimerSuggestion(cleanStep);

    // TTS: only speak when explicitly requested via "Rileggi" button
    // (auto-speak removed — use replayCookingTTS() to trigger manually)
}

function _buildTtsRequest(text, s) {
    const url = s.tts_url || 'http://192.168.1.133:8123/api/events/noemi_speak';
    const method = s.tts_method || 'POST';
    const authType = s.tts_auth_type || 'bearer';
    const token = s.tts_token || '';
    const payloadKey = s.tts_payload_key || 'message';
    const contentType = s.tts_content_type || 'application/json';
    let extraFields = {};
    try { extraFields = JSON.parse(s.tts_extra_fields || '{}'); } catch(e) { /* invalid JSON, ignore */ }
    const headers = { 'Content-Type': contentType };
    if (authType === 'bearer' && token) {
        headers['Authorization'] = `Bearer ${token}`;
    } else if (authType === 'header' && s.tts_auth_header_name) {
        headers[s.tts_auth_header_name] = s.tts_auth_header_value || '';
    }
    const payload = { [payloadKey]: text, ...extraFields };
    let body;
    if (contentType === 'application/json') {
        body = JSON.stringify(payload);
    } else if (contentType === 'application/x-www-form-urlencoded') {
        body = new URLSearchParams(Object.entries(payload).map(([k, v]) => [k, String(v)])).toString();
    } else {
        body = text;
    }
    return { url, method, headers, body };
}

async function _ttsViaProxy(req) {
    // Route through server-side proxy to avoid mixed-content / CORS issues
    return fetch('api/index.php?action=tts_proxy', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            url: req.url,
            method: req.method,
            headers: req.headers,
            payload: req.body
        })
    });
}

async function speakCookingStep(text) {
    if (!text) return;
    const s = getSettings();
    if (!s.tts_enabled) return;
    try {
        const req = _buildTtsRequest(text, s);
        await _ttsViaProxy(req);
    } catch(e) { /* silent — TTS is non-critical */ }
}

function replayCookingTTS() {
    if (!_cookingRecipe) return;
    const steps = _cookingRecipe.steps || [];
    const text = (steps[_cookingStep] || '').replace(/^Passo\s*\d+\s*[:.]\s*/i, '');
    if (text) speakCookingStep(text);
}

function onTtsAuthTypeChange(type) {
    const tokenGroup = document.getElementById('tts-token-group');
    const headerGroup = document.getElementById('tts-custom-header-group');
    if (tokenGroup) tokenGroup.style.display = type === 'bearer' ? '' : 'none';
    if (headerGroup) headerGroup.style.display = type === 'header' ? '' : 'none';
}

async function testTTS() {
    const statusEl = document.getElementById('tts-test-status');
    // Build settings from current form values (before saving)
    let extraFields = {};
    try { extraFields = JSON.parse((document.getElementById('setting-tts-extra-fields')?.value || '{}').trim() || '{}'); } catch(e) { /* ignore */ }
    const formSettings = {
        tts_url: (document.getElementById('setting-tts-url')?.value || '').trim(),
        tts_method: document.getElementById('setting-tts-method')?.value || 'POST',
        tts_auth_type: document.getElementById('setting-tts-auth-type')?.value || 'bearer',
        tts_token: (document.getElementById('setting-tts-token')?.value || '').trim(),
        tts_auth_header_name: (document.getElementById('setting-tts-auth-header-name')?.value || '').trim(),
        tts_auth_header_value: (document.getElementById('setting-tts-auth-header-value')?.value || '').trim(),
        tts_content_type: document.getElementById('setting-tts-content-type')?.value || 'application/json',
        tts_payload_key: (document.getElementById('setting-tts-payload-key')?.value || '').trim() || 'message',
        tts_extra_fields: document.getElementById('setting-tts-extra-fields')?.value || ''
    };
    const enabled = document.getElementById('setting-tts-enabled')?.checked;
    if (!enabled) {
        if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '⚠️ TTS non attivo — attiva il toggle prima di testare.'; }
        return;
    }
    if (!formSettings.tts_url) {
        if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '⚠️ URL endpoint mancante.'; }
        return;
    }
    if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status'; statusEl.textContent = '⏳ Invio in corso…'; }
    try {
        const req = _buildTtsRequest('Test vocale Dispensa Manager', formSettings);
        const res = await _ttsViaProxy(req);
        const data = await res.json().catch(() => ({}));
        const httpCode = data.status || res.status;
        if (res.ok && httpCode >= 200 && httpCode < 300) {
            if (statusEl) { statusEl.className = 'settings-status success'; statusEl.textContent = `✅ Risposta ${httpCode} — controlla che l'altoparlante abbia parlato.`; }
        } else {
            const errDetail = data.error || data.body || res.statusText;
            if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `⚠️ HTTP ${httpCode}: ${errDetail}`; }
        }
    } catch(e) {
        if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ Errore: ${e.message}`; }
    }
}

// ===== COOKING TIMER SYSTEM =====
let _cookingTimers = [];          // { id, label, total, seconds, running, interval }
let _cookingTimerIdCounter = 0;
let _cookingSuggestedSeconds = 0;
let _cookingSuggestedLabel = '';

/**
 * Parse time durations from step text.
 * Returns total seconds or 0 if no time found.
 */
function _parseStepTimer(text) {
    const t = text.toLowerCase();
    let totalSec = 0;
    if (/mezz['']?\s*ora/i.test(t)) totalSec += 30 * 60;
    if (/un\s+quarto\s+d['']?\s*ora/i.test(t)) totalSec += 15 * 60;
    if (/un['']?\s*ora(?!\s*e)/i.test(t) && !/\d\s*or[ae]/i.test(t)) totalSec += 60 * 60;
    if (totalSec > 0) return totalSec;
    const reOre = /(\d+(?:[.,]\d+)?)\s*or[ae]/gi;
    const reMin = /(\d+(?:[.,]\d+)?)\s*min(?:ut[oi])?/gi;
    const reSec = /(\d+(?:[.,]\d+)?)\s*second[oi]/gi;
    let m;
    while ((m = reOre.exec(t)) !== null) totalSec += parseFloat(m[1].replace(',', '.')) * 3600;
    while ((m = reMin.exec(t)) !== null) totalSec += parseFloat(m[1].replace(',', '.')) * 60;
    while ((m = reSec.exec(t)) !== null) totalSec += parseFloat(m[1].replace(',', '.'));
    if (totalSec === 0 && /(?:un\s+paio\s+di|qualche|pochi)\s+minut/i.test(t)) totalSec = 2 * 60;
    if (totalSec === 0 && /qualche\s+second/i.test(t)) totalSec = 15;
    return Math.round(totalSec);
}

function _formatTimerDisplay(sec) {
    const abs = Math.abs(sec);
    const m = Math.floor(abs / 60);
    const s = abs % 60;
    const sign = sec < 0 ? '+' : '';
    return `${sign}${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

/** Extract a short 2-3 word label from the step text for the timer. */
function _extractTimerLabel(text, stepNum) {
    const fillers = new Set(['il','la','lo','le','gli','i','dell','della','dello','delle','degli','dei',
        'un','una','uno','del','al','alla','allo','alle','agli','ai','nel','nella','nello','nelle',
        'negli','nei','per','con','che','poi','e','o','non','se','in','di','a','da','fino','mentre',
        'quando','dopo','prima','circa','bene','ancora','subito','su','ad','ed','più','meno','tutto','tutta']);
    const timePatterns = [/mezz['']?\s*ora/i, /\bor[ae]\b/i, /\bmin(?:ut[oi])?\b/i, /\bsecond[oi]\b/i, /\bquarto\s+d['']?\s*ora/i];
    let timeIdx = text.length;
    for (const p of timePatterns) { const r = p.exec(text); if (r && r.index < timeIdx) timeIdx = r.index; }
    const beforeTime = (text.slice(0, timeIdx).trim() || text);
    const words = beforeTime.replace(/[.,!?;:'"()\[\]]/g, '').split(/\s+/).filter(w => w.length > 2 && !/^\d+$/.test(w));
    const meaningful = words.filter(w => !fillers.has(w.toLowerCase()));
    if (meaningful.length >= 1) return meaningful.slice(0, 3).join(' ');
    return `Passo ${stepNum + 1}`;
}

function setupCookingTimerSuggestion(stepText) {
    const seconds = _parseStepTimer(stepText);
    const suggestEl = document.getElementById('cooking-timer-suggest');
    if (seconds <= 0) {
        suggestEl.style.display = 'none';
        _cookingSuggestedSeconds = 0;
        _cookingSuggestedLabel = '';
        return;
    }
    _cookingSuggestedSeconds = seconds;
    _cookingSuggestedLabel = _extractTimerLabel(stepText, _cookingStep);
    document.getElementById('cooking-timer-suggest-text').textContent =
        `⏱️ ${_formatTimerDisplay(seconds)}  ·  ${_cookingSuggestedLabel}`;
    suggestEl.style.display = 'flex';
}

function addSuggestedCookingTimer() {
    if (_cookingSuggestedSeconds <= 0) return;
    addCookingTimer(_cookingSuggestedSeconds, _cookingSuggestedLabel);
    document.getElementById('cooking-timer-suggest').style.display = 'none';
    _cookingSuggestedSeconds = 0;
}

function addCookingTimer(seconds, label) {
    const id = ++_cookingTimerIdCounter;
    _cookingTimers.push({ id, label, total: seconds, seconds, running: false, interval: null });
    renderTimersBar();
    toggleCookingTimerById(id); // auto-start
}

function removeCookingTimer(id) {
    const t = _cookingTimers.find(t => t.id === id);
    if (t && t.interval) clearInterval(t.interval);
    _cookingTimers = _cookingTimers.filter(t => t.id !== id);
    renderTimersBar();
    _updateScreenFlash();
}

function toggleCookingTimerById(id) {
    const t = _cookingTimers.find(t => t.id === id);
    if (!t) return;
    if (t.running) {
        clearInterval(t.interval);
        t.interval = null;
        t.running = false;
    } else {
        t.running = true;
        t.interval = setInterval(() => {
            t.seconds--;
            if (t.seconds === 0) _cookingTimerDoneById(id);
            _updateTimerCard(id);
        }, 1000);
    }
    _updateTimerCard(id);
}

function resetCookingTimerById(id) {
    const t = _cookingTimers.find(t => t.id === id);
    if (!t) return;
    clearInterval(t.interval);
    t.interval = null;
    t.running = false;
    t.seconds = t.total;
    _updateTimerCard(id);
}

function _cookingTimerDoneById(id) {
    if (navigator.vibrate) navigator.vibrate([300, 100, 300, 100, 300]);
    const t = _cookingTimers.find(t => t.id === id);
    if (_cookingTTS && t) speakCookingStep(`Timer ${t.label} scaduto!`);
}

function _updateTimerCard(id) {
    const t = _cookingTimers.find(t => t.id === id);
    if (!t) return;
    const card = document.getElementById(`ctimer-${id}`);
    if (!card) { renderTimersBar(); return; }
    const sec = t.seconds;
    const dispEl = card.querySelector('.ctimer-display');
    const toggleBtn = card.querySelector('.ctimer-toggle');
    dispEl.textContent = _formatTimerDisplay(sec);
    if (sec <= 0) {
        dispEl.className = 'ctimer-display ctimer-done';
    } else if (sec <= 30) {
        dispEl.className = 'ctimer-display ctimer-warning';
    } else {
        dispEl.className = 'ctimer-display';
    }
    toggleBtn.textContent = t.running ? '⏸' : '▶';
    toggleBtn.classList.toggle('running', t.running);
    _updateScreenFlash();
}

/** Update the full-screen colour flash based on the worst active timer state. */
function _updateScreenFlash() {
    const flashEl = document.getElementById('cooking-flash-overlay');
    if (!flashEl) return;
    let hasDone = false, hasWarning = false;
    for (const t of _cookingTimers) {
        if (t.seconds <= 0) { hasDone = true; break; }
        if (t.seconds <= 30 && t.running) hasWarning = true;
    }
    if (hasDone) {
        flashEl.className = 'cooking-flash-overlay flash-done';
    } else if (hasWarning) {
        flashEl.className = 'cooking-flash-overlay flash-warning';
    } else {
        flashEl.className = 'cooking-flash-overlay';
    }
}

function renderTimersBar() {
    const bar = document.getElementById('cooking-timers-bar');
    if (!bar) return;
    if (_cookingTimers.length === 0) {
        bar.style.display = 'none';
        bar.innerHTML = '';
        return;
    }
    bar.style.display = 'flex';
    bar.innerHTML = _cookingTimers.map(t => {
        const sec = t.seconds;
        const doneClass = sec <= 0 ? ' ctimer-done' : sec <= 30 ? ' ctimer-warning' : '';
        const runClass = t.running ? ' running' : '';
        return `<div class="cooking-timer-card" id="ctimer-${t.id}">
            <span class="ctimer-label">${escapeHtml(t.label)}</span>
            <span class="ctimer-display${doneClass}">${_formatTimerDisplay(sec)}</span>
            <div class="ctimer-btns">
                <button class="ctimer-btn ctimer-toggle${runClass}" onclick="toggleCookingTimerById(${t.id})">${t.running ? '⏸' : '▶'}</button>
                <button class="ctimer-btn ctimer-reset" onclick="resetCookingTimerById(${t.id})">↩</button>
                <button class="ctimer-btn ctimer-remove" onclick="removeCookingTimer(${t.id})">✕</button>
            </div>
        </div>`;
    }).join('');
}

function clearAllCookingTimers() {
    _cookingTimers.forEach(t => { if (t.interval) clearInterval(t.interval); });
    _cookingTimers = [];
    _cookingTimerIdCounter = 0;
    _cookingSuggestedSeconds = 0;
    _cookingSuggestedLabel = '';
    const bar = document.getElementById('cooking-timers-bar');
    if (bar) { bar.style.display = 'none'; bar.innerHTML = ''; }
    _updateScreenFlash();
}
// ===== END COOKING TIMER SYSTEM =====

function toggleCookingTTS() {
    _cookingTTS = !_cookingTTS;
    const btn = document.getElementById('cooking-tts-btn');
    btn.textContent = _cookingTTS ? '🔊' : '🔇';
    if (_cookingTTS) {
        const steps = _cookingRecipe?.steps || [];
        const text = (steps[_cookingStep] || '').replace(/^Passo\s*\d+\s*[:.]\s*/i, '');
        speakCookingStep(text);
    }
}

function navigateCookingStep(delta) {
    if (!_cookingRecipe) return;
    const total = (_cookingRecipe.steps || []).length;
    const next = _cookingStep + delta;
    if (next < 0) return;
    if (next >= total) {
        // All steps done: mark all visited, close overlay
        for (let i = 0; i < total; i++) _cookingVisited.add(i);
        closeCookingMode();
        return;
    }
    _cookingStep = next;
    renderCookingStep();
}

function cookingUseIngredient(idx, productId, location, qtyNumber, btn) {
    // Reuse the same modal used in the recipe dialog
    useRecipeIngredient(idx, productId, location, qtyNumber, btn);
    // Mark ingredient as used so it's hidden from further steps
    if (_cookingRecipe && _cookingRecipe.ingredients && _cookingRecipe.ingredients[idx]) {
        _cookingRecipe.ingredients[idx].used = true;
    }
    setTimeout(() => renderCookingStep(), 400);
}
// ===== END COOKING MODE =====

function updateRecipeMealTitle() {
    const meal = getSelectedMealType();
    document.getElementById('recipe-meal-title').textContent = MEAL_LABELS[meal] || '🍳 Ricetta';
    _renderMealPlanHint(meal);
}

/** Show/hide the meal-plan badge hint + top banner in the recipe dialog. */
function onMealPlanChipChange(cb) {
    const show = cb.checked;
    const banner = document.getElementById('recipe-mealplan-banner');
    const hint   = document.getElementById('recipe-mealplan-hint');
    if (banner) banner.style.display = show ? 'flex' : 'none';
    if (hint)   hint.style.display   = show ? 'flex' : 'none';
}

function _renderMealPlanHint(mealSlot) {
    const el = document.getElementById('recipe-mealplan-hint');
    const banner = document.getElementById('recipe-mealplan-banner');
    const chipWrap = document.getElementById('recipe-opt-mealplan-wrap');
    const chipLabel = document.getElementById('recipe-opt-mealplan-label');
    const chipCb = document.getElementById('recipe-opt-mealplan');
    // mealSlot = 'pranzo' or 'cena' (from getMealType/getSelectedMealType)
    const typeId = (mealSlot === 'pranzo' || mealSlot === 'cena')
        ? getTodayMealPlanType(mealSlot)
        : null;
    if (!typeId || typeId === 'libero') {
        if (el) el.style.display = 'none';
        if (banner) banner.style.display = 'none';
        if (chipWrap) chipWrap.style.display = 'none';
        return;
    }
    const t = MEAL_PLAN_TYPE_MAP[typeId];
    if (!t) {
        if (el) el.style.display = 'none';
        if (banner) banner.style.display = 'none';
        if (chipWrap) chipWrap.style.display = 'none';
        return;
    }
    if (el) {
        el.innerHTML = `<span class="mplan-hint-badge">${t.icon} ${t.label}</span> <span class="mplan-hint-label">suggerito dal piano settimanale</span>`;
        el.style.display = 'flex';
    }
    if (banner) {
        const slotLabel = mealSlot === 'pranzo' ? '🌤️ Pranzo' : '🌙 Cena';
        banner.innerHTML = `<span style="opacity:0.75;font-weight:500">${slotLabel}</span><span style="opacity:0.45">·</span><span>${t.icon} ${t.label}</span>`;
        banner.style.display = 'flex';
    }
    // Show the meal-plan chip (active by default, user can uncheck to ignore the plan)
    if (chipWrap) {
        chipWrap.style.display = '';
        if (chipLabel) chipLabel.textContent = `${t.icon} ${t.label}`;
        if (chipCb) chipCb.checked = true;
    }
}

function regenerateRecipe() {
    _cachedRecipe = null;
    // Use the meal the user currently has selected (not the auto-detected one)
    const meal = getSelectedMealType();
    // increment variation counter for this meal slot
    _recipeVariationCount[meal] = (_recipeVariationCount[meal] || 0) + 1;
    document.getElementById('recipe-result').style.display = 'none';
    document.getElementById('recipe-loading').style.display = 'none';
    // Keep all existing form settings (persons, chips, meal) — just show the form again
    document.getElementById('recipe-ask').style.display = '';
}

async function generateRecipe() {
    const meal = getSelectedMealType();
    const persons = parseInt(document.getElementById('recipe-persons').value) || 1;
    const settings = getSettings();

    // Determine meal plan type for today's selected slot,
    // but only if the user has NOT unchecked the meal-plan chip
    const mealPlanChipWrap = document.getElementById('recipe-opt-mealplan-wrap');
    const mealPlanCb = document.getElementById('recipe-opt-mealplan');
    const mealPlanChipActive = !mealPlanChipWrap || mealPlanChipWrap.style.display === 'none' || (mealPlanCb && mealPlanCb.checked);
    const mealPlanType = mealPlanChipActive && (meal === 'pranzo' || meal === 'cena')
        ? (getTodayMealPlanType(meal) || null)
        : null;
    
    // Gather active options from checkboxes
    const options = [];
    const optMap = {
        'recipe-opt-veloce': 'veloce',
        'recipe-opt-pocafame': 'pocafame',
        'recipe-opt-scadenze': 'scadenze',
        'recipe-opt-healthy': 'salutare',
        'recipe-opt-opened': 'opened',
        'recipe-opt-zerowaste': 'zerowaste'
    };
    Object.entries(optMap).forEach(([id, key]) => {
        const cb = document.getElementById(id);
        if (cb && cb.checked) options.push(key);
    });

    document.getElementById('recipe-ask').style.display = 'none';
    document.getElementById('recipe-loading').style.display = '';
    document.getElementById('recipe-result').style.display = 'none';

    try {
        const result = await api('generate_recipe', {}, 'POST', { 
            meal, 
            persons,
            options,
            appliances: settings.appliances || [],
            dietary_restrictions: settings.dietary_restrictions || '',
            today_recipes: [...new Set([...await getTodayRecipeTitles(), ..._generatedTodayTitles])],
            meal_plan_type: mealPlanType,
            variation: _recipeVariationCount[meal] || 0,
        });

        if (!result.success) {
            document.getElementById('recipe-loading').style.display = 'none';
            document.getElementById('recipe-ask').style.display = '';
            if (result.error === 'no_api_key') {
                showToast('⚠️ Chiave API Gemini non configurata', 'warning');
            } else {
                showToast(result.error || 'Errore nella generazione', 'error');
            }
            return;
        }

        const r = result.recipe;
        renderRecipe(r);

        // Track title client-side immediately (before DB save completes)
        if (r.title) _generatedTodayTitles.push(r.title);

        // Save to archive
        await saveRecipeToArchive(r);

        // Cache the recipe for this meal type (in-memory only)
        _cachedRecipe = { meal, recipe: r };

        document.getElementById('recipe-loading').style.display = 'none';
        document.getElementById('recipe-result').style.display = '';

    } catch (err) {
        console.error('Recipe error:', err);
        document.getElementById('recipe-loading').style.display = 'none';
        document.getElementById('recipe-ask').style.display = '';
        showToast('Errore di connessione', 'error');
    }
}

// ===== GEMINI CHAT =====
let chatHistory = [];
let chatInventoryContext = null;
let _chatSavedCount = 0; // track how many messages already saved to DB

function initChat() {
    // Load chat history from DB
    api('chat_list').then(res => {
        if (res.success && res.messages && res.messages.length > 0) {
            chatHistory = res.messages.map(m => ({ role: m.role, text: m.text }));
            _chatSavedCount = chatHistory.length;
            renderChatHistory();
        } else {
            _chatSavedCount = 0;
        }
    }).catch(() => { _chatSavedCount = 0; });
    // Always reload fresh inventory context
    loadChatContext();
    // Focus input
    setTimeout(() => {
        const input = document.getElementById('chat-input');
        if (input) input.focus();
    }, 300);
}

async function loadChatContext() {
    try {
        const data = await api('inventory_list');
        chatInventoryContext = data.inventory || [];
    } catch(e) { chatInventoryContext = []; }
}

function sendChatSuggestion(text) {
    document.getElementById('chat-input').value = text;
    sendChatMessage();
}

async function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text) return;
    
    input.value = '';
    
    // Hide welcome if first message
    const welcome = document.querySelector('.chat-welcome');
    if (welcome) welcome.style.display = 'none';
    
    // Add user message
    chatHistory.push({ role: 'user', text });
    appendChatBubble('user', text);
    saveChatHistory();
    
    // Show typing indicator
    const typingEl = appendChatBubble('gemini', '<div class="chat-typing"><span></span><span></span><span></span></div>', true);
    scrollChatBottom();
    
    // Disable send
    const btn = document.getElementById('btn-chat-send');
    btn.disabled = true;
    
    try {
        const settings = getSettings();
        const result = await api('gemini_chat', {}, 'POST', {
            message: text,
            history: chatHistory.slice(0, -1).slice(-20), // last 20 messages for context
            appliances: settings.appliances || [],
            dietary_restrictions: settings.dietary_restrictions || ''
        });
        
        // Remove typing indicator
        typingEl.remove();
        
        if (result.success) {
            chatHistory.push({ role: 'gemini', text: result.reply });
            appendChatBubble('gemini', formatChatReply(result.reply));
        } else {
            const errMsg = result.error === 'no_api_key' ? 'Configura la chiave API Gemini nelle impostazioni.' : (result.error || 'Errore nella risposta');
            appendChatBubble('gemini', `⚠️ ${escapeHtml(errMsg)}`);
        }
    } catch(err) {
        typingEl.remove();
        appendChatBubble('gemini', '⚠️ Errore di connessione');
    }
    
    btn.disabled = false;
    saveChatHistory();
    scrollChatBottom();
}

function appendChatBubble(role, html, isRaw = false) {
    const container = document.getElementById('chat-messages');
    const bubble = document.createElement('div');
    bubble.className = `chat-bubble chat-${role}`;
    if (isRaw) {
        bubble.innerHTML = html;
    } else if (role === 'user') {
        bubble.textContent = html;
    } else {
        bubble.innerHTML = html;
    }
    container.appendChild(bubble);
    scrollChatBottom();
    return bubble;
}

function formatChatReply(text) {
    // Convert markdown-like formatting
    let html = escapeHtml(text);
    // Bold **text**
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // Italic *text*
    html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
    // Lists
    html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
    // Numbered lists  
    html = html.replace(/^(\d+)\. (.+)$/gm, '<li>$2</li>');
    // Line breaks
    html = html.replace(/\n/g, '<br>');
    // Clean up consecutive ul tags
    html = html.replace(/<\/ul>\s*<br>\s*<ul>/g, '');
    return html;
}

function renderChatHistory() {
    const container = document.getElementById('chat-messages');
    if (chatHistory.length === 0) return;
    
    // Hide welcome
    const welcome = container.querySelector('.chat-welcome');
    if (welcome) welcome.style.display = 'none';
    
    chatHistory.forEach(msg => {
        if (msg.role === 'user') {
            appendChatBubble('user', msg.text);
        } else {
            appendChatBubble('gemini', formatChatReply(msg.text));
        }
    });
    scrollChatBottom();
}

function scrollChatBottom() {
    const container = document.getElementById('chat-messages');
    setTimeout(() => container.scrollTop = container.scrollHeight, 50);
}

function clearChat() {
    chatHistory = [];
    api('chat_clear', {}, 'POST').catch(() => {});
    const container = document.getElementById('chat-messages');
    container.innerHTML = `
        <div class="chat-welcome">
            <svg class="gemini-icon-lg" viewBox="0 0 24 24" width="48" height="48" fill="#6366f1"><path d="M12 0C12 6.627 6.627 12 0 12c6.627 0 12 5.373 12 12 0-6.627 5.373-12 12-12-6.627 0-12-5.373-12-12z"/></svg>
            <h3>Ciao! Sono il tuo assistente cucina</h3>
            <p>Chiedimi di prepararti un succo, uno spuntino, un piatto veloce... Conosco la tua dispensa, i tuoi elettrodomestici e le tue preferenze!</p>
            <div class="chat-suggestions">
                <button class="chat-suggestion" onclick="sendChatSuggestion('Cosa posso preparare per uno spuntino veloce?')">🍿 Spuntino veloce</button>
                <button class="chat-suggestion" onclick="sendChatSuggestion('Fammi un succo o frullato con quello che ho')">🥤 Succo/Frullato</button>
                <button class="chat-suggestion" onclick="sendChatSuggestion('Ho fame ma voglio qualcosa di leggero')">🥗 Qualcosa di leggero</button>
                <button class="chat-suggestion" onclick="sendChatSuggestion('Cosa sta per scadere e come posso usarlo?')">⏰ Usa le scadenze</button>
            </div>
        </div>
    `;
    showToast('Chat cancellata', 'success');
}

function saveChatHistory() {
    // Keep last 50 messages max
    if (chatHistory.length > 50) {
        const trimmed = chatHistory.length - 50;
        chatHistory = chatHistory.slice(-50);
        _chatSavedCount = Math.max(0, _chatSavedCount - trimmed);
    }
    // Only save messages that haven't been saved yet (prevent duplicates)
    const unsaved = chatHistory.slice(_chatSavedCount);
    if (unsaved.length === 0) return;
    api('chat_save', {}, 'POST', { messages: unsaved }).then(() => {
        _chatSavedCount = chatHistory.length;
    }).catch(() => {});
}

// ===== SCREENSAVER & INACTIVITY AUTO-REFRESH =====
let _inactivityTimer = null;
let _screensaverActive = false;
let _screensaverClockInterval = null;
let _screensaverFactInterval = null;
let _screensaverData = null; // cached data for fact generation
const SCREENSAVER_FACT_DURATION = 5 * 60 * 1000; // 5 minutes per fact
const INACTIVITY_TIMEOUT = 5 * 60 * 1000; // 5 minutes

function resetInactivityTimer() {
    if (_screensaverActive) return; // don't reset while screensaver is showing
    clearTimeout(_inactivityTimer);
    _inactivityTimer = setTimeout(activateScreensaver, INACTIVITY_TIMEOUT);
}

function activateScreensaver() {
    if (_screensaverActive) return;
    if (document.body.classList.contains('cooking-mode-active')) return;
    _screensaverActive = true;
    const overlay = document.getElementById('screensaver');
    overlay.style.display = 'flex';
    // Fade in
    requestAnimationFrame(() => overlay.classList.add('visible'));
    updateScreensaverClock();
    _screensaverClockInterval = setInterval(updateScreensaverClock, 1000);
    // Load data and start facts
    loadScreensaverData().then(() => {
        showNextScreensaverFact();
        _screensaverFactInterval = setInterval(showNextScreensaverFact, SCREENSAVER_FACT_DURATION);
    });
}

function updateScreensaverClock() {
    const now = new Date();
    const time = now.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    const date = now.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
    const el = document.getElementById('screensaver-clock');
    if (el) el.innerHTML = `${time}<div class="screensaver-date">${date}</div>`;
    updateScreensaverMealPlan();
}

/** Show/hide the planned meal type badge on the screensaver based on current time slot. */
function updateScreensaverMealPlan() {
    const el = document.getElementById('screensaver-mealplan');
    if (!el) return;
    const s = getSettings();
    if (s.meal_plan_enabled === false) { el.style.display = 'none'; return; }
    const hour = new Date().getHours();
    // Before 15:00 show pranzo, from 15:00 onwards show cena
    const slot = hour < 15 ? 'pranzo' : 'cena';
    const typeId = getTodayMealPlanType(slot);
    if (!typeId || typeId === 'libero') { el.style.display = 'none'; return; }
    const t = MEAL_PLAN_TYPE_MAP[typeId];
    if (!t) { el.style.display = 'none'; return; }
    const slotLabel = slot === 'pranzo' ? '🌤️ Pranzo' : '🌙 Cena';
    el.innerHTML = `<span class="screensaver-mealplan-badge">${slotLabel} · ${t.icon} ${t.label}</span>`;
    el.style.display = 'block';
}

function dismissScreensaver(targetPage) {
    if (!_screensaverActive) return;
    clearInterval(_screensaverClockInterval);
    clearInterval(_screensaverFactInterval);
    const overlay = document.getElementById('screensaver');
    overlay.classList.remove('visible');
    setTimeout(() => {
        overlay.style.display = 'none';
        _screensaverActive = false;
        _screensaverData = null;
        if (targetPage) {
            showPage(targetPage);
        } else {
            refreshCurrentPage();
        }
        resetInactivityTimer();
    }, 400);
}

// Load all data needed for screensaver facts
async function loadScreensaverData() {
    try {
        const [statsRes, invRes, bringRes] = await Promise.all([
            api('stats'),
            api('inventory_list'),
            api('bring_list').catch(() => null)
        ]);
        _screensaverData = {
            stats: statsRes,
            inventory: invRes.inventory || [],
            shopping: bringRes && bringRes.success ? (bringRes.purchase || []) : []
        };
    } catch (e) {
        _screensaverData = { stats: {}, inventory: [], shopping: [] };
    }
}

// Show next random fact with fade in/out
function showNextScreensaverFact() {
    const el = document.getElementById('screensaver-fact');
    if (!el) return;
    el.classList.remove('visible');
    setTimeout(() => {
        el.textContent = generateScreensaverFact();
        el.classList.add('visible');
    }, 1600);
}

// Generate a dynamic fact from available data
function generateScreensaverFact() {
    const d = _screensaverData || { stats: {}, inventory: [], shopping: [] };
    const inv = d.inventory;
    const stats = d.stats;
    const shop = d.shopping;
    const now = new Date();
    const hour = now.getHours();

    // Pre-compute useful data
    const expired = stats.expired || [];
    const expiringSoon = stats.expiring_soon || [];
    const totalProducts = stats.total_products || inv.length;
    const totalItems = stats.total_items || 0;

    const byLocation = {};
    const byCategory = {};
    const withExpiry = [];
    const noExpiry = [];
    const expiringThisWeek = [];
    const expiringThisMonth = [];
    const inFreezer = [];
    const inFrigo = [];
    const inDispensa = [];

    for (const item of inv) {
        // by location
        const loc = item.location || 'altro';
        if (!byLocation[loc]) byLocation[loc] = [];
        byLocation[loc].push(item);
        if (loc === 'freezer') inFreezer.push(item);
        else if (loc === 'frigo') inFrigo.push(item);
        else if (loc === 'dispensa') inDispensa.push(item);

        // by category
        const cat = mapToLocalCategory(item.category, item.name);
        if (!byCategory[cat]) byCategory[cat] = [];
        byCategory[cat].push(item);

        // expiry
        if (item.expiry_date) {
            withExpiry.push(item);
            const days = daysUntilExpiry(item.expiry_date);
            if (days >= 0 && days <= 7) expiringThisWeek.push(item);
            if (days >= 0 && days <= 30) expiringThisMonth.push(item);
        } else {
            noExpiry.push(item);
        }
    }

    // Greeting based on time
    const greeting = hour < 12 ? 'Buongiorno' : hour < 18 ? 'Buon pomeriggio' : 'Buonasera';

    // Estimated shopping total
    let spesaTotal = 0;
    let spesaPriced = 0;
    for (const item of shop) {
        const pd = shoppingPrices[item.name.toLowerCase()];
        if (pd && pd.product) {
            const est = estimateItemPrice(pd.product, item.specification || pd.spec || '');
            spesaTotal += est ? est.estimated : pd.product.price;
            spesaPriced++;
        }
    }

    // Random item picker
    const rItem = (arr) => arr.length ? arr[Math.floor(Math.random() * arr.length)] : null;

    // All fact generators
    const facts = [];

    // --- Expired items facts ---
    if (expired.length > 0) {
        facts.push(() => `Hai ${expired.length} ${expired.length === 1 ? 'prodotto scaduto' : 'prodotti scaduti'} in dispensa. Controlla!`);
        facts.push(() => {
            const names = expired.slice(0, 3).map(i => i.name);
            return `Prodotti scaduti: ${names.join(', ')}${expired.length > 3 ? ` e altri ${expired.length - 3}` : ''}`;
        });
        const freezerExpired = expired.filter(i => i.location === 'freezer');
        if (freezerExpired.length > 0) {
            facts.push(() => {
                const item = rItem(freezerExpired);
                const safety = getExpiredSafety(item, Math.abs(daysUntilExpiry(item.expiry_date)));
                if (safety.level === 'ok' || safety.level === 'warning') {
                    return `${item.name} è scaduto, ma essendo in freezer potrebbe essere ancora buono! Controlla.`;
                }
                return `${item.name} in freezer è scaduto da troppo tempo. Meglio buttarlo.`;
            });
        }
        const frigoExpired = expired.filter(i => i.location === 'frigo');
        if (frigoExpired.length > 0) {
            facts.push(() => `Hai ${frigoExpired.length} ${frigoExpired.length === 1 ? 'prodotto scaduto' : 'prodotti scaduti'} in frigo!`);
        }
    }

    // --- Expiring soon facts ---
    if (expiringSoon.length > 0) {
        facts.push(() => {
            const item = expiringSoon[0];
            const days = daysUntilExpiry(item.expiry_date);
            if (days === 0) return `${item.name} scade oggi! Usalo subito.`;
            if (days === 1) return `${item.name} scade domani. Pensaci!`;
            return `${item.name} scade tra ${days} giorni.`;
        });
        if (expiringSoon.length > 1) {
            facts.push(() => `Hai ${expiringSoon.length} prodotti in scadenza ravvicinata.`);
        }
    }
    if (expiringThisWeek.length > 0) {
        facts.push(() => `Questa settimana scadono ${expiringThisWeek.length} prodotti. Pianifica i pasti di conseguenza!`);
        facts.push(() => {
            const item = rItem(expiringThisWeek);
            const days = daysUntilExpiry(item.expiry_date);
            const locLabel = LOCATIONS[item.location]?.label || item.location;
            return `${item.name} (${locLabel}) scade tra ${days} ${days === 1 ? 'giorno' : 'giorni'}.`;
        });
    }
    if (expiringThisMonth.length > 0) {
        facts.push(() => `In questo mese scadranno ${expiringThisMonth.length} prodotti.`);
    }

    // --- Shopping list facts ---
    if (shop.length > 0) {
        facts.push(() => `Hai ${shop.length} ${shop.length === 1 ? 'prodotto' : 'prodotti'} nella lista della spesa.`);
        facts.push(() => {
            const names = shop.slice(0, 4).map(i => i.name);
            return `Nella spesa: ${names.join(', ')}${shop.length > 4 ? '...' : ''}`;
        });
        if (spesaTotal > 0) {
            facts.push(() => `Il totale previsto per la spesa è circa €${spesaTotal.toFixed(2)}.`);
            if (spesaPriced < shop.length) {
                facts.push(() => `Spesa stimata: €${spesaTotal.toFixed(2)} (${spesaPriced} di ${shop.length} prodotti con prezzo).`);
            }
        }
    }
    if (shop.length === 0) {
        facts.push(() => `La lista della spesa è vuota. Tutto a posto!`);
    }

    // --- Location-based facts ---
    if (inFrigo.length > 0) {
        facts.push(() => `Hai ${inFrigo.length} prodotti in frigo.`);
        facts.push(() => {
            const item = rItem(inFrigo);
            return `In frigo c'è: ${item.name}${item.brand ? ' (' + item.brand + ')' : ''}.`;
        });
    }
    if (inFreezer.length > 0) {
        facts.push(() => `Hai ${inFreezer.length} prodotti nel freezer.`);
        facts.push(() => {
            const item = rItem(inFreezer);
            return `Nel freezer c'è: ${item.name}. Non dimenticartelo!`;
        });
    }
    if (inDispensa.length > 0) {
        facts.push(() => `In dispensa ci sono ${inDispensa.length} prodotti.`);
    }

    // --- Category-based facts ---
    const catEntries = Object.entries(byCategory);
    if (catEntries.length > 0) {
        facts.push(() => {
            const sorted = catEntries.sort((a, b) => b[1].length - a[1].length);
            const top = sorted[0];
            const catLabel = top[0];
            const icon = CATEGORY_ICONS[catLabel] || '📦';
            return `La categoria più presente è ${icon} ${catLabel} con ${top[1].length} prodotti.`;
        });
        if (byCategory['carne'] && byCategory['carne'].length > 0) {
            facts.push(() => `Hai ${byCategory['carne'].length} prodotti di carne. 🥩`);
        }
        if (byCategory['latticini'] && byCategory['latticini'].length > 0) {
            facts.push(() => `Hai ${byCategory['latticini'].length} latticini in casa. 🥛`);
        }
        if (byCategory['verdura'] && byCategory['verdura'].length > 0) {
            facts.push(() => `Hai ${byCategory['verdura'].length} tipi di verdura. Ottimo per la salute! 🥬`);
        }
        if (byCategory['frutta'] && byCategory['frutta'].length > 0) {
            facts.push(() => `Hai ${byCategory['frutta'].length} tipi di frutta. 🍎`);
        }
        if (byCategory['bevande'] && byCategory['bevande'].length > 0) {
            facts.push(() => `Hai ${byCategory['bevande'].length} bevande disponibili. 🥤`);
        }
        if (byCategory['surgelati'] && byCategory['surgelati'].length > 0) {
            facts.push(() => `Hai ${byCategory['surgelati'].length} surgelati nel freezer. ❄️`);
        }
        if (byCategory['pasta'] && byCategory['pasta'].length > 0) {
            facts.push(() => `Hai ${byCategory['pasta'].length} tipi di pasta. 🍝 Che ne dici di una carbonara?`);
        }
        if (byCategory['conserve'] && byCategory['conserve'].length > 0) {
            facts.push(() => `Hai ${byCategory['conserve'].length} conserve in dispensa. 🥫`);
        }
        if (byCategory['snack'] && byCategory['snack'].length > 0) {
            facts.push(() => `Hai ${byCategory['snack'].length} snack. Resisti alla tentazione! 🍪`);
        }
        if (byCategory['condimenti'] && byCategory['condimenti'].length > 0) {
            facts.push(() => `Hai ${byCategory['condimenti'].length} condimenti a disposizione. 🧂`);
        }
    }

    // --- General inventory facts ---
    if (inv.length > 0) {
        facts.push(() => `Hai ${totalProducts} prodotti diversi in casa per un totale di ${Math.round(totalItems)} pezzi.`);
        facts.push(() => {
            const item = rItem(inv);
            return `Lo sapevi? Hai ${item.name} in ${LOCATIONS[item.location]?.label || item.location}.`;
        });
        facts.push(() => {
            const item = rItem(inv);
            const qty = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
            return `${item.name}: ne hai ${qty}.`;
        });
    }
    if (noExpiry.length > 0) {
        facts.push(() => `${noExpiry.length} prodotti non hanno una data di scadenza impostata.`);
    }
    if (withExpiry.length > 0) {
        // Find the one expiring furthest away
        const furthest = withExpiry.reduce((best, item) => {
            const d = daysUntilExpiry(item.expiry_date);
            return d > (best.d || 0) ? { item, d } : best;
        }, { d: 0 });
        if (furthest.item && furthest.d > 30) {
            facts.push(() => `Il prodotto con scadenza più lontana è ${furthest.item.name}: ${Math.round(furthest.d / 30)} mesi.`);
        }
    }

    // --- Quantity-based facts ---
    const highQtyItems = inv.filter(i => parseFloat(i.quantity) >= 5);
    if (highQtyItems.length > 0) {
        facts.push(() => {
            const item = rItem(highQtyItems);
            const qty = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
            return `Hai una bella scorta di ${item.name}: ${qty}!`;
        });
    }
    const lowQtyItems = inv.filter(i => parseFloat(i.quantity) <= 1 && parseFloat(i.quantity) > 0);
    if (lowQtyItems.length > 0) {
        facts.push(() => {
            const item = rItem(lowQtyItems);
            return `${item.name} sta per finire. Aggiungilo alla spesa?`;
        });
        facts.push(() => `Ci sono ${lowQtyItems.length} prodotti quasi finiti.`);
    }

    // --- Time-of-day greetings & suggestions ---
    facts.push(() => `${greeting}! Se vuoi che ti preparo una ricetta, tocca qui.`);
    facts.push(() => `${greeting}! La tua dispensa è sotto controllo. 😊`);
    if (hour >= 6 && hour < 10) {
        facts.push(() => `Buongiorno! Pronto per la colazione? ☕`);
        if (byCategory['pane']) facts.push(() => `Buongiorno! Hai del pane per la colazione. 🍞`);
        if (byCategory['latticini']) facts.push(() => `C'è del latte in frigo per il cappuccino? ☕🥛`);
    }
    if (hour >= 11 && hour < 14) {
        facts.push(() => `È quasi ora di pranzo! Cosa cuciniamo? 🍽️`);
        if (byCategory['pasta']) facts.push(() => `Ora di pranzo… Un bel piatto di pasta? 🍝`);
    }
    if (hour >= 17 && hour < 21) {
        facts.push(() => `Buona sera! Hai pensato alla cena? 🍽️`);
        if (byCategory['carne']) facts.push(() => `Per cena potresti usare la carne che hai. 🥩`);
        if (byCategory['pesce']) facts.push(() => `Che ne dici di pesce per cena? 🐟`);
    }
    if (hour >= 21 || hour < 6) {
        facts.push(() => `Buonanotte! Domani controlla le scadenze. 🌙`);
    }

    // --- Weekly stats ---
    const recentIn = stats.recent_in || 0;
    const recentOut = stats.recent_out || 0;
    if (recentIn > 0) {
        facts.push(() => `Questa settimana hai aggiunto ${recentIn} prodotti.`);
    }
    if (recentOut > 0) {
        facts.push(() => `Questa settimana hai consumato ${recentOut} prodotti.`);
    }
    if (recentIn > 0 && recentOut > 0) {
        facts.push(() => `Bilancio settimanale: +${recentIn} entrati, -${recentOut} usciti.`);
    }

    // --- Tips & curiosità (statici ma ruotano) ---
    facts.push(() => `💡 Lo sapevi? I prodotti in freezer durano molto più a lungo della data di scadenza.`);
    facts.push(() => `💡 Il pane congelato mantiene la fragranza per settimane.`);
    facts.push(() => `💡 Le uova si conservano fino a 3-4 settimane dopo la data preferita.`);
    facts.push(() => `💡 Lo yogurt chiuso in frigo dura spesso 1-2 settimane oltre la scadenza.`);
    facts.push(() => `💡 Per evitare sprechi, usa prima i prodotti con scadenza più vicina.`);
    facts.push(() => `💡 La carne in freezer può durare fino a 6 mesi senza problemi.`);
    facts.push(() => `💡 Le verdure fresche durano di più se conservate nel cassetto del frigo.`);
    facts.push(() => `💡 Controlla regolarmente la dispensa per evitare doppioni nella spesa.`);
    facts.push(() => `💡 I latticini vanno conservati nella parte più fredda del frigo.`);
    facts.push(() => `💡 Non ricongelare mai un alimento già scongelato. Cucinalo subito!`);
    facts.push(() => `💡 Un frigo ordinato ti fa risparmiare tempo e denaro.`);
    facts.push(() => `💡 Le conserve aperte vanno in frigo e consumate in pochi giorni.`);

    // --- Brand-based facts ---
    const brands = inv.filter(i => i.brand).map(i => i.brand);
    if (brands.length > 0) {
        const brandCount = {};
        brands.forEach(b => { brandCount[b] = (brandCount[b] || 0) + 1; });
        const topBrand = Object.entries(brandCount).sort((a, b) => b[1] - a[1])[0];
        facts.push(() => `Il marca più presente nella tua dispensa è ${topBrand[0]} con ${topBrand[1]} prodotti.`);
    }

    // --- Specific food combo facts ---
    if (byCategory['pasta'] && byCategory['condimenti']) {
        facts.push(() => `Hai pasta e condimenti: sei pronto per un primo piatto! 🍝`);
    }
    if (byCategory['pane'] && byCategory['carne']) {
        facts.push(() => `Pane e carne: un panino veloce è sempre una buona idea! 🥪`);
    }
    if (byCategory['verdura'] && byCategory['carne']) {
        facts.push(() => `Verdura e carne: hai tutto per un piatto equilibrato! 🥗🥩`);
    }

    // --- Empty states ---
    if (inv.length === 0) {
        facts.push(() => `La dispensa è vuota! Fai una bella spesa. 🛒`);
        facts.push(() => `Nessun prodotto registrato. Scansiona qualcosa per iniziare!`);
    }

    // --- Location distribution ---
    const locCount = Object.keys(byLocation).length;
    if (locCount > 1) {
        facts.push(() => {
            const parts = Object.entries(byLocation).map(([loc, items]) => 
                `${LOCATIONS[loc]?.icon || '📦'} ${items.length}`
            );
            return `Distribuzione: ${parts.join('  ·  ')}`;
        });
    }

    // Pick a random fact
    if (facts.length === 0) {
        return `${greeting}! La tua Dispensa ti aspetta.`;
    }
    return facts[Math.floor(Math.random() * facts.length)]();
}

// ===== SPESA MODE (long-press camera for continuous scanning) =====
let _spesaMode = false;
let _longPressTimer = null;
let _spesaSession = []; // { name, qty, unit } per ogni prodotto aggiunto

function initSpesaMode() {
    const btn = document.getElementById('btn-header-scan');
    if (!btn) return;

    btn.addEventListener('pointerdown', (e) => {
        _longPressTimer = setTimeout(() => {
            _longPressTimer = null;
            startSpesaMode();
        }, 600);
    });
    btn.addEventListener('pointerup', () => {
        if (_longPressTimer) {
            clearTimeout(_longPressTimer);
            _longPressTimer = null;
            // Short press — normal scan
            showPage('scan');
        }
    });
    btn.addEventListener('pointerleave', () => {
        if (_longPressTimer) {
            clearTimeout(_longPressTimer);
            _longPressTimer = null;
        }
    });
}

function startSpesaMode() {
    _spesaMode = true;
    _spesaSession = [];
    showToast('🛒 Modalità Spesa attivata!', 'success');
    showPage('scan');
    updateSpesaBanner();
}

function endSpesaMode() {
    _spesaMode = false;
    updateSpesaBanner();
    stopScanner();
    showPage('dashboard');
}

function updateSpesaBanner() {
    const banner = document.getElementById('spesa-mode-banner');
    if (!banner) return;
    banner.style.display = _spesaMode ? 'flex' : 'none';
    const statEl = banner.querySelector('.spesa-stat');
    if (statEl) statEl.textContent = _spesaBannerStat();
}

// Called after successful add — returns true if spesa mode handled navigation
function spesaModeAfterAdd() {
    if (!_spesaMode) return false;
    // Track this product in the session
    if (currentProduct) {
        _spesaSession.push({ name: currentProduct.name, category: currentProduct.category || '' });
        updateSpesaBanner();
    }
    showPage('scan');
    return true;
}

function _spesaBannerStat() {
    const n = _spesaSession.length;
    if (n === 0) return '🛒 Nessun prodotto ancora';
    const cats = {};
    _spesaSession.forEach(p => { const c = p.category || 'altro'; cats[c] = (cats[c]||0)+1; });
    const topCat = Object.entries(cats).sort((a,b)=>b[1]-a[1])[0];
    const names = _spesaSession.map(p => p.name);
    const unique = [...new Set(names)];
    const dupes = names.length - unique.length;
    const phrases = [
        n === 1 ? `Primo prodotto: ${_spesaSession[0].name}!` : null,
        n >= 2 && n < 5 ? `${n} prodotti — stai scaldando i motori 🚀` : null,
        n >= 5 && n < 10 ? `${n} prodotti — ottimo ritmo! 💪` : null,
        n >= 10 && n < 20 ? `${n} prodotti — quasi un recordman 🏆` : null,
        n >= 20 ? `${n} prodotti — spesa epica! 🛒🔥` : null,
        dupes > 0 ? `${dupes} bis ${dupes===1?'(stessa cosa due volte)':'(roba presa più volte)'}` : null,
        topCat && topCat[1] > 1 ? `Categoria top: ${topCat[0]} (${topCat[1]}×)` : null,
    ].filter(Boolean);
    return phrases[n % phrases.length] || `${n} prodott${n===1?'o':'i'} aggiunti`;
}

function _initScreensaverShortcutBtn(btnId, targetPage, longPressFn) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    let ssLongPress = null;
    btn.addEventListener('pointerdown', (e) => {
        e.stopPropagation();
        if (longPressFn) {
            ssLongPress = setTimeout(() => {
                ssLongPress = null;
                dismissScreensaver(targetPage);
                setTimeout(longPressFn, 500);
            }, 600);
        }
    });
    btn.addEventListener('pointerup', (e) => {
        e.stopPropagation();
        if (longPressFn && ssLongPress) {
            clearTimeout(ssLongPress);
            ssLongPress = null;
        }
        dismissScreensaver(targetPage);
    });
    btn.addEventListener('pointerleave', (e) => {
        e.stopPropagation();
        if (ssLongPress) {
            clearTimeout(ssLongPress);
            ssLongPress = null;
        }
    });
    ['click', 'touchstart', 'touchend'].forEach(evt => {
        btn.addEventListener(evt, (e) => e.stopPropagation(), { passive: false });
    });
}

function initScreensaverShortcuts() {
    _initScreensaverShortcutBtn('screensaver-scan-btn', 'scan', () => startSpesaMode());
    _initScreensaverShortcutBtn('screensaver-recipe-btn', 'recipe', null);
}

function initInactivityWatcher() {
    const events = ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'];
    events.forEach(evt => {
        document.addEventListener(evt, () => {
            if (_screensaverActive) {
                dismissScreensaver();
            } else {
                resetInactivityTimer();
            }
        }, { passive: true });
    });
    resetInactivityTimer();
}

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', () => {
    // Migrate old session-based flags to time-based
    if (sessionStorage.getItem('_autoAddedCritical')) {
        sessionStorage.removeItem('_autoAddedCritical');
    }
    // One-time reset of bg sync timestamp so first load always triggers a sync
    if (!localStorage.getItem('_bgBringSyncReset_v1')) {
        localStorage.removeItem('_bgBringSyncTs');
        localStorage.setItem('_bgBringSyncReset_v1', '1');
    }
    syncSettingsFromDB();
    showPage('dashboard');
    initInactivityWatcher();
    initSpesaMode();
    initScreensaverShortcuts();
    startBgShoppingRefresh();

    // Auto-refresh Bring list when user returns to the browser tab (e.g. was in the Bring app)
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && _currentPageId === 'shopping') {
            loadShoppingList();
        }
    });

    // Silent background sync: update urgency specs on Bring and add missing critical items
    // Runs once at startup (time-gated: max every 10 min) without affecting the UI
    _backgroundBringSync();
});

/**
 * Background sync at startup:
 * 1. Fetches Bring list + smart shopping in parallel
 * 2. Adds any critical items missing from Bring
 * 3. Updates urgency specs for items already on Bring that need it
 * Fully silent — no toasts, no loading spinners.
 */
async function _backgroundBringSync() {
    const lastRun = parseInt(localStorage.getItem('_bgBringSyncTs') || '0');
    if (Date.now() - lastRun < 10 * 60 * 1000) return;
    localStorage.setItem('_bgBringSyncTs', String(Date.now()));

    try {
        const [bringData, smartData] = await Promise.all([
            api('bring_list').catch(() => null),
            api('smart_shopping').catch(() => null),
        ]);

        if (!bringData?.success || !smartData?.success) return;

        const listUUID = bringData.listUUID;
        const bringItems = bringData.purchase || [];
        const smartItems = smartData.items || [];

        if (!listUUID || !smartItems.length) return;

        // Update local smart cache so other functions can use it
        if (!smartShoppingItems.length) {
            smartShoppingItems = smartItems;
            _smartShoppingLastFetch = Date.now();
        }
        if (!shoppingListUUID) shoppingListUUID = listUUID;
        if (!shoppingItems.length) shoppingItems = bringItems;

        const toAdd = [];    // new items not yet on Bring
        const toUpdate = []; // items on Bring that need spec updated

        for (const si of smartItems) {
            if (si.urgency === 'none' || si.urgency === 'low') continue;
            const expectedSpec = _urgencyToSpec(si.urgency, '');
            const bringMatch = bringItems.find(bi => {
                const biL = bi.name.toLowerCase();
                const siL = si.name.toLowerCase();
                if (biL === siL) return true;
                const biFirst = _nameTokens(bi.name)[0];
                const siFirst = _nameTokens(si.name)[0];
                return biFirst && biFirst === siFirst;
            });

            if (!bringMatch) {
                // Not on Bring — add if critical AND not recently purchased
                if (si.urgency === 'critical' && !_isBringPurchased(si.name)) {
                    toAdd.push({ name: si.name, specification: expectedSpec });
                }
            } else {
                // On Bring — update spec if urgency marker is missing/wrong
                const currentSpec = (bringMatch.specification || '').toLowerCase();
                const hasUrgencyMarker = currentSpec.includes('urgente') || currentSpec.includes('presto');
                if (!hasUrgencyMarker && expectedSpec) {
                    toUpdate.push({ name: si.name, specification: expectedSpec, update_spec: true });
                    bringMatch.specification = expectedSpec; // update local copy
                }
            }
        }

        const allChanges = [...toAdd, ...toUpdate];
        if (allChanges.length === 0) return;

        await api('bring_add', {}, 'POST', { items: allChanges, listUUID });
        logOperation('bg_bring_sync', { added: toAdd.map(i=>i.name), updated: toUpdate.map(i=>i.name) });
    } catch (e) { /* silent — best effort */ }
}

// ===== DUPLICLICK (SPESA ONLINE) =====

function selectSpesaProvider(btn, provider) {
    document.querySelectorAll('.provider-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const s = getSettings();
    s.spesa_provider = provider;
    saveSettingsToStorage(s);
}

async function spesaLogin() {
    const email = document.getElementById('setting-spesa-email').value.trim();
    const password = document.getElementById('setting-spesa-password').value.trim();
    const s = getSettings();
    const provider = s.spesa_provider || 'dupliclick';

    if (!email || !password) {
        showToast('Inserisci email e password', 'error');
        return;
    }

    const btn = document.getElementById('spesa-login-btn');
    const statusEl = document.getElementById('spesa-login-status');
    const resultEl = document.getElementById('spesa-login-result');

    btn.disabled = true;
    btn.innerHTML = '⏳ Accesso in corso...';
    statusEl.style.display = 'none';
    resultEl.style.display = 'none';

    try {
        const res = await api(`${provider}_login`, {}, 'POST', { email, password });

        if (res.error) {
            statusEl.className = 'dupliclick-status error';
            statusEl.innerHTML = `❌ <strong>Errore:</strong> ${escapeHtml(res.error)}`;
            statusEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '🔐 Accedi';
            return;
        }

        // Save credentials and session data persistently
        s.spesa_email = email;
        s.spesa_password = password;
        s.spesa_token = res.token_full || '';
        s.spesa_provider = provider;
        s.spesa_logged_in = true;
        s.spesa_user = res.user || (res.data && res.data.user) || {};
        s.spesa_data = res.data || {};
        // Save AI prompt too
        const promptEl = document.getElementById('setting-spesa-ai-prompt');
        if (promptEl) s.spesa_ai_prompt = promptEl.value.trim();
        saveSettingsToStorage(s);

        statusEl.className = 'dupliclick-status success';
        const welcomeMsg = (res.infos && res.infos[0]) ? res.infos[0].info : 'Login effettuato!';
        statusEl.innerHTML = `✅ <strong>${escapeHtml(welcomeMsg)}</strong>`;
        statusEl.style.display = 'block';

        // Display key info only
        const user = res.user || (res.data && res.data.user) || {};
        const data = res.data || {};
        const shipping = data.shippingAddress || {};
        const points = user.userPoints || data.userPoints || {};
        const fidelityPts = Array.isArray(points) ? points[0] : points['0'];
        
        let html = '<div class="dupliclick-data">';
        html += '<div class="dupliclick-data-grid">';
        
        if (user.firstName) html += `<div class="data-row"><span class="data-label">👤 Nome</span><span class="data-value">${escapeHtml(user.firstName)} ${escapeHtml(user.lastName || '')}</span></div>`;
        if (user.fidelityCard) html += `<div class="data-row"><span class="data-label">💳 Tessera</span><span class="data-value">${escapeHtml(user.fidelityCard)}</span></div>`;
        if (shipping.addressName) html += `<div class="data-row"><span class="data-label">🏪 Punto Ritiro</span><span class="data-value">${escapeHtml(shipping.addressName)}</span></div>`;
        if (fidelityPts) html += `<div class="data-row"><span class="data-label">⭐ Punti Fedeltà</span><span class="data-value">${fidelityPts.value || 0}</span></div>`;
        
        html += '</div></div>';
        resultEl.innerHTML = html;
        resultEl.style.display = 'block';

    } catch (e) {
        statusEl.className = 'dupliclick-status error';
        statusEl.innerHTML = `❌ <strong>Errore di rete:</strong> ${escapeHtml(e.message)}`;
        statusEl.style.display = 'block';
    }

    btn.disabled = false;
    btn.innerHTML = '🔐 Accedi';
}

function loadSpesaSettings() {
    const s = getSettings();
    const emailEl = document.getElementById('setting-spesa-email');
    const passEl = document.getElementById('setting-spesa-password');
    const promptEl = document.getElementById('setting-spesa-ai-prompt');
    if (emailEl) emailEl.value = s.spesa_email || s.dupliclick_email || '';
    if (passEl) passEl.value = s.spesa_password || s.dupliclick_password || '';
    if (promptEl) promptEl.value = s.spesa_ai_prompt || DEFAULT_SPESA_AI_PROMPT;
    
    // Show saved login state
    if (s.spesa_logged_in && s.spesa_user) {
        const statusEl = document.getElementById('spesa-login-status');
        const resultEl = document.getElementById('spesa-login-result');
        const loginBtn = document.getElementById('spesa-login-btn');
        
        if (loginBtn) {
            loginBtn.innerHTML = '✅ Connesso — Riaccedi';
            loginBtn.className = 'btn btn-large btn-secondary full-width mt-2';
        }
        if (statusEl) {
            statusEl.className = 'dupliclick-status success';
            statusEl.innerHTML = `✅ <strong>Connesso come ${escapeHtml(s.spesa_user.firstName || '')} ${escapeHtml(s.spesa_user.lastName || '')}</strong>`;
            statusEl.style.display = 'block';
        }
        if (resultEl) {
            const user = s.spesa_user;
            const shipping = (s.spesa_data && s.spesa_data.shippingAddress) || {};
            const points = user.userPoints || (s.spesa_data && s.spesa_data.userPoints) || {};
            const fidelityPts = Array.isArray(points) ? points[0] : points['0'];
            
            let html = '<div class="dupliclick-data"><div class="dupliclick-data-grid">';
            if (user.firstName) html += `<div class="data-row"><span class="data-label">👤 Nome</span><span class="data-value">${escapeHtml(user.firstName)} ${escapeHtml(user.lastName || '')}</span></div>`;
            if (user.fidelityCard) html += `<div class="data-row"><span class="data-label">💳 Tessera</span><span class="data-value">${escapeHtml(user.fidelityCard)}</span></div>`;
            if (shipping.addressName) html += `<div class="data-row"><span class="data-label">🏪 Punto Ritiro</span><span class="data-value">${escapeHtml(shipping.addressName)}</span></div>`;
            if (fidelityPts) html += `<div class="data-row"><span class="data-label">⭐ Punti Fedeltà</span><span class="data-value">${fidelityPts.value || 0}</span></div>`;
            html += '</div></div>';
            resultEl.innerHTML = html;
            resultEl.style.display = 'block';
        }
    }
}
