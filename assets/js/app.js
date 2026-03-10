/**
 * Dispensa Manager - Main Application JS
 * Complete pantry management with barcode scanning and AI identification
 */

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
    // Match multi-pack patterns like "6 x 1l", "4 x 125g" → total weight
    const multiMatch = q.match(/(\d+)\s*x\s*([\d.,]+)\s*(ml|l|g|kg|cl)/i);
    if (multiMatch) {
        const count = parseInt(multiMatch[1]);
        let perUnitVal = parseFloat(multiMatch[2].replace(',', '.'));
        let perUnitUnit = multiMatch[3].toLowerCase();
        if (perUnitUnit === 'cl') { perUnitUnit = 'ml'; perUnitVal *= 10; }
        const totalVal = count * perUnitVal;
        return { unit: perUnitUnit, quantity: totalVal, weightInfo: quantityInfo };
    }
    // Match single package patterns like "500 g", "1 l", "750 ml", "1.5 kg"
    const match = q.match(/([\d.,]+)\s*(kg|g|l|ml|cl)/i);
    if (match) {
        let unit = match[2].toLowerCase();
        let val = parseFloat(match[1].replace(',', '.'));
        if (unit === 'cl') { unit = 'ml'; val *= 10; }
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
function estimateExpiryDays(product) {
    const name = (product.name || '').toLowerCase();
    const cat = (product.category || '').toLowerCase();
    
    // Specific product overrides
    if (/latte\s+(fresco|intero|parzial|scremato)/.test(name)) return 7;
    if (/latte\s+uht|latte\s+a\s+lunga/.test(name)) return 90;
    if (/yogurt/.test(name)) return 21;
    if (/mozzarella|burrata|stracciatella/.test(name)) return 5;
    if (/formaggio\s+(fresco|ricotta|mascarpone|stracchino|crescenza)/.test(name)) return 10;
    if (/parmigiano|grana|pecorino|provolone/.test(name)) return 60;
    if (/prosciutto\s+cotto|mortadella|wurstel/.test(name)) return 7;
    if (/prosciutto\s+crudo|salame|bresaola|speck/.test(name)) return 30;
    if (/uova/.test(name)) return 28;
    if (/pane\s+fresco|pane\s+in\s+cassetta/.test(name)) return 5;
    if (/pane\s+confezionato|pan\s+carr|pancarrè/.test(name)) return 14;
    if (/insalata|rucola|spinaci\s+freschi/.test(name)) return 5;
    if (/pollo|tacchino|maiale|manzo|vitello/.test(name)) return 3;
    if (/salmone|tonno\s+fresco|pesce/.test(name) && !/tonno\s+in\s+scatola|tonno\s+rio/.test(name)) return 2;
    if (/tonno\s+in\s+scatola|tonno\s+rio|sgombro\s+in/.test(name)) return 1095;
    if (/surgelat|frozen|findus|4\s*salti/.test(name)) return 180;
    if (/gelato/.test(name)) return 365;
    if (/succo|spremuta/.test(name)) return 7;
    if (/birra|vino/.test(name)) return 365;
    if (/acqua/.test(name)) return 365;
    if (/biscott|cracker|grissini|fette\s+biscott/.test(name)) return 180;
    if (/nutella|marmellata|miele/.test(name)) return 365;
    if (/passata|pelati|pomodor/.test(name)) return 730;
    if (/olio|aceto/.test(name)) return 548;
    
    // Fallback to category
    for (const [key, days] of Object.entries(EXPIRY_DAYS)) {
        if (cat.includes(key)) return days;
    }
    return 180; // generic default
}

function formatEstimatedExpiry(days) {
    if (days <= 7) return `~${days} giorni`;
    if (days <= 30) return `~${Math.round(days / 7)} settimane`;
    if (days <= 365) return `~${Math.round(days / 30)} mesi`;
    return `~${Math.round(days / 365)} anni`;
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
let currentLocation = '';
let scannerStream = null;
let quaggaRunning = false;
let aiStream = null;

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
    return res.json();
}

// ===== PAGE NAVIGATION =====
function showPage(pageId, param = null) {
    // Hide all pages
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    // Show target page
    const page = document.getElementById(`page-${pageId}`);
    if (page) page.classList.add('active');
    
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
        case 'scan': initScanner(); break;
        case 'products': loadAllProducts(); break;
        case 'ai': initAICamera(); break;
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
        const [summaryData, statsData, invData] = await Promise.all([
            api('inventory_summary'),
            api('stats'),
            api('inventory_list')
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
        document.getElementById('stat-total').textContent = total || summary.reduce((a, s) => a + s.product_count, 0);
        
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
                else { badgeText = `${days} giorni`; badgeClass = 'expiring'; }
                return `
                <div class="alert-item">
                    <div class="alert-item-info">
                        <span class="alert-item-name">${escapeHtml(item.name)}</span>
                        ${item.brand ? `<span class="alert-item-brand">${escapeHtml(item.brand)}</span>` : ''}
                    </div>
                    <span class="alert-item-badge ${badgeClass}">${badgeText}</span>
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
                let badgeText;
                if (days === 0) badgeText = 'Oggi';
                else if (days === 1) badgeText = 'Da ieri';
                else badgeText = `Da ${days} giorni`;
                return `
                <div class="alert-item">
                    <div class="alert-item-info">
                        <span class="alert-item-name">${escapeHtml(item.name)}</span>
                        ${item.brand ? `<span class="alert-item-brand">${escapeHtml(item.brand)}</span>` : ''}
                    </div>
                    <span class="alert-item-badge expired">${badgeText}</span>
                </div>`;
            }).join('');
        } else {
            expiredSection.style.display = 'none';
        }
        
        // Full inventory grouped by location, then by category within each location
        const allItems = invData.inventory || [];
        const grouped = { dispensa: [], frigo: [], freezer: [], altro: [] };
        allItems.forEach(item => {
            const loc = grouped[item.location] !== undefined ? item.location : 'altro';
            grouped[loc].push(item);
        });
        
        for (const [loc, items] of Object.entries(grouped)) {
            const section = document.getElementById(`dash-section-${loc}`);
            const container = document.getElementById(`dash-inv-${loc}`);
            if (items.length === 0) {
                section.style.display = 'none';
            } else {
                section.style.display = 'block';
                container.innerHTML = renderGroupedByCategory(items, true);
            }
        }

    } catch (err) {
        console.error('Dashboard load error:', err);
    }
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
    const qtyDisplay = formatQuantity(item.quantity, item.unit);
    
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
            <span class="inv-qty-value">${qtyDisplay}</span>
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

function formatQuantity(qty, unit) {
    if (!qty && qty !== 0) return '';
    const n = parseFloat(qty);
    const unitLabels = { 'pz': 'pz', 'kg': 'kg', 'g': 'g', 'l': 'L', 'ml': 'ml', 'conf': 'conf' };
    const label = unitLabels[unit] || unit || 'pz';
    // Format nicely
    if (n === Math.floor(n)) return `${Math.floor(n)} ${label}`;
    return `${n.toFixed(1)} ${label}`;
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
    const qtyDisplay = formatQuantity(item.quantity, item.unit);
    
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
                <span class="inv-badge badge-qty">${qtyDisplay}</span>
                ${expiryBadge}
            </div>
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
                <span class="modal-detail-value">${item.quantity} ${item.unit}</span>
            </div>
            ${item.expiry_date ? `
            <div class="modal-detail-row">
                <span class="modal-detail-label">📅 Scadenza</span>
                <span class="modal-detail-value">${formatDate(item.expiry_date)}</span>
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
        loadInventory();
    }
}

function editInventoryItem(id) {
    const item = currentInventory.find(i => i.id === id);
    if (!item) {
        closeModal();
        showToast('Prodotto non trovato', 'error');
        return;
    }
    
    // Rebuild modal content for editing (don't close and reopen - just replace content)
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>Modifica ${escapeHtml(item.name)}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form class="form" onsubmit="submitEditInventory(event, ${id})">
            <div class="form-group">
                <label>📦 Quantità</label>
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="adjustQty('edit-qty', -1)">−</button>
                    <input type="number" id="edit-qty" value="${item.quantity}" min="0" step="any" class="qty-input">
                    <button type="button" class="qty-btn" onclick="adjustQty('edit-qty', 1)">+</button>
                </div>
            </div>
            <div class="form-group">
                <label>📍 Posizione</label>
                <div class="location-selector">
                    ${Object.entries(LOCATIONS).map(([k, v]) => `
                        <button type="button" class="loc-btn ${item.location === k ? 'active' : ''}" 
                            onclick="this.parentElement.querySelectorAll('.loc-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');document.getElementById('edit-loc').value='${k}'">${v.icon} ${v.label}</button>
                    `).join('')}
                </div>
                <input type="hidden" id="edit-loc" value="${item.location}">
            </div>
            <div class="form-group">
                <label>📅 Scadenza</label>
                <input type="date" id="edit-expiry" value="${item.expiry_date || ''}" class="form-input">
            </div>
            <button type="submit" class="btn btn-large btn-primary full-width">💾 Salva</button>
        </form>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

async function submitEditInventory(e, id) {
    e.preventDefault();
    const qty = parseFloat(document.getElementById('edit-qty').value);
    const loc = document.getElementById('edit-loc').value;
    const expiry = document.getElementById('edit-expiry').value || null;
    
    await api('inventory_update', {}, 'POST', { id, quantity: qty, location: loc, expiry_date: expiry });
    closeModal();
    showToast('Aggiornato!', 'success');
    loadInventory();
}

// ===== BARCODE SCANNER =====
async function initScanner() {
    const video = document.getElementById('scanner-video');
    const viewport = document.getElementById('scanner-viewport');
    
    try {
        // Stop any existing stream
        stopScanner();
        
        const stream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'environment',
                width: { ideal: 1280 },
                height: { ideal: 720 }
            }
        });
        
        scannerStream = stream;
        video.srcObject = stream;
        await video.play();
        
        // Start Quagga for barcode detection
        startQuagga(video);
        
    } catch (err) {
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

function startQuagga(videoEl) {
    if (quaggaRunning) return;
    
    const canvas = document.getElementById('scanner-canvas');
    const ctx = canvas.getContext('2d');
    
    let scanning = true;
    quaggaRunning = true;
    let lastDetected = '';
    let detectCount = 0;
    
    function scanFrame() {
        if (!scanning || !scannerStream) return;
        
        canvas.width = videoEl.videoWidth;
        canvas.height = videoEl.videoHeight;
        ctx.drawImage(videoEl, 0, 0);
        
        try {
            Quagga.decodeSingle({
                src: canvas.toDataURL('image/jpeg', 0.8),
                numOfWorkers: 0,
                inputStream: { size: 800 },
                decoder: {
                    readers: [
                        'ean_reader',
                        'ean_8_reader',
                        'code_128_reader',
                        'code_39_reader',
                        'upc_reader',
                        'upc_e_reader'
                    ]
                },
                locate: true
            }, function(result) {
                if (result && result.codeResult) {
                    const code = result.codeResult.code;
                    if (code === lastDetected) {
                        detectCount++;
                    } else {
                        lastDetected = code;
                        detectCount = 1;
                    }
                    // Require 2 consecutive reads for reliability
                    if (detectCount >= 2) {
                        scanning = false;
                        quaggaRunning = false;
                        onBarcodeDetected(code);
                        return;
                    }
                }
                if (scanning) {
                    setTimeout(scanFrame, 300);
                }
            });
        } catch (e) {
            if (scanning) setTimeout(scanFrame, 500);
        }
    }
    
    // Start scanning after a small delay
    setTimeout(scanFrame, 500);
}

function stopScanner() {
    quaggaRunning = false;
    if (scannerStream) {
        scannerStream.getTracks().forEach(t => t.stop());
        scannerStream = null;
    }
    const video = document.getElementById('scanner-video');
    if (video) video.srcObject = null;
    
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
        'frutta': { unit: 'kg', qty: 1 },
        'verdura': { unit: 'kg', qty: 0.5 },
        'pasta': { unit: 'g', qty: 500 },
        'pane': { unit: 'pz', qty: 1 },
        'surgelati': { unit: 'g', qty: 450 },
        'bevande': { unit: 'l', qty: 1 },
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

async function submitProduct(e) {
    e.preventDefault();
    showLoading(true);
    
    const productData = {
        id: document.getElementById('pf-id').value || null,
        name: document.getElementById('pf-name').value,
        brand: document.getElementById('pf-brand').value,
        category: document.getElementById('pf-category').value,
        unit: document.getElementById('pf-unit').value,
        default_quantity: parseFloat(document.getElementById('pf-defqty').value) || 1,
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
        const ingredShort = currentProduct.ingredients.length > 120 
            ? currentProduct.ingredients.substring(0, 120) + '...' 
            : currentProduct.ingredients;
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
    
    document.getElementById('action-product-preview').innerHTML = `
        ${currentProduct.image_url ?
            `<img src="${escapeHtml(currentProduct.image_url)}" alt="">` :
            `<span class="product-preview-emoji">${catIcon}</span>`
        }
        <div class="product-preview-info">
            <h3>${escapeHtml(currentProduct.name)}</h3>
            <p>${currentProduct.brand ? `<strong>${escapeHtml(currentProduct.brand)}</strong>` : ''}</p>
            ${currentProduct.barcode ? `<p style="font-size:0.75rem;color:var(--text-muted)">📊 ${currentProduct.barcode}</p>` : ''}
        </div>
    `;
    
    // Check if product needs editing (unknown name, missing info)
    const isUnknown = !currentProduct.name || 
        /sconosciuto|unknown|^$/i.test(currentProduct.name.trim()) ||
        currentProduct.name.trim().length < 2;
    const needsEdit = isUnknown || !currentProduct.brand;
    
    // Edit product info section
    let editInfoEl = document.getElementById('action-edit-info');
    if (!editInfoEl) {
        editInfoEl = document.createElement('div');
        editInfoEl.id = 'action-edit-info';
        const preview = document.getElementById('action-product-preview');
        preview.parentElement.insertBefore(editInfoEl, preview.nextSibling);
    }
    
    if (needsEdit) {
        const categoryOptions = Object.entries(CATEGORY_LABELS).map(([key, label]) => 
            `<option value="${key}" ${mapToLocalCategory(currentProduct.category, currentProduct.name) === key ? 'selected' : ''}>${label}</option>`
        ).join('');
        
        editInfoEl.innerHTML = `
            <div class="edit-unknown-card ${isUnknown ? 'highlight' : ''}">
                <h4>${isUnknown ? '⚠️ Prodotto non riconosciuto' : '✏️ Completa le informazioni'}</h4>
                <p class="edit-unknown-hint">${isUnknown ? 'Inserisci il nome e le informazioni del prodotto' : 'Puoi modificare o completare le info mancanti'}</p>
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
        editInfoEl.style.display = 'block';
        // Focus name field if unknown
        if (isUnknown) {
            setTimeout(() => document.getElementById('edit-action-name')?.focus(), 100);
        }
    } else {
        editInfoEl.style.display = 'none';
        editInfoEl.innerHTML = '';
    }
    
    // Show extra product info section below preview
    let extraInfoEl = document.getElementById('action-product-details');
    if (!extraInfoEl) {
        const container = document.getElementById('action-product-preview').parentElement;
        extraInfoEl = document.createElement('div');
        extraInfoEl.id = 'action-product-details';
        // Insert after preview, before action buttons
        const actionBtns = document.querySelector('#page-action .action-buttons');
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
    
    showPage('action');
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
    
    document.getElementById('add-quantity').value = currentProduct.default_quantity || 1;
    document.getElementById('add-quantity').dataset.manuallySet = 'false';
    
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
    const estimatedDays = estimateExpiryDays(currentProduct);
    const estimatedDate = addDays(estimatedDays);
    const estimateLabel = formatEstimatedExpiry(estimatedDays);
    
    expirySection.innerHTML = `
        <label>🛒 Questo prodotto è...</label>
        <div class="purchase-type-selector">
            <button type="button" class="purchase-type-btn active" onclick="selectPurchaseType(this, 'new', '${estimatedDate}', '${escapeHtml(estimateLabel)}')">
                🆕 Appena comprato
            </button>
            <button type="button" class="purchase-type-btn" onclick="selectPurchaseType(this, 'existing', '', '')">
                📦 Ce l'avevo già
            </button>
        </div>
        <div id="expiry-detail" class="expiry-detail">
            <div class="expiry-estimate">
                <span class="expiry-estimate-label">Scadenza stimata: <strong>${estimateLabel}</strong></span>
                <span class="expiry-estimate-date">${formatDate(estimatedDate)}</span>
            </div>
            <div class="expiry-input-row">
                <input type="date" id="add-expiry" class="form-input" value="${estimatedDate}">
                <button type="button" class="btn btn-accent btn-scan-expiry" onclick="scanExpiryWithAI()" title="Scansiona data scadenza">📷</button>
            </div>
            <p class="form-hint">📝 Puoi modificare la data o scansionarla con la fotocamera</p>
        </div>
    `;
    
    showPage('add');
}

function onAddUnitChange() {
    updateAddQtyStep();
    // If switching units, suggest a sensible quantity
    // BUT only if the user hasn't manually changed the quantity in this form
    const unit = document.getElementById('add-unit').value;
    const qtyInput = document.getElementById('add-quantity');
    if (qtyInput.dataset.manuallySet === 'true') return; // User already edited qty, don't overwrite
    
    const currentQty = parseFloat(qtyInput.value) || 1;
    
    // Convert between related units if logical
    if (unit === 'g' && currentQty <= 10) qtyInput.value = currentProduct.weight_info ? parseFloat(currentProduct.weight_info) || 250 : 250;
    if (unit === 'kg' && currentQty > 100) qtyInput.value = (currentQty / 1000).toFixed(1);
    if (unit === 'ml' && currentQty <= 10) qtyInput.value = 500;
    if (unit === 'l' && currentQty > 100) qtyInput.value = (currentQty / 1000).toFixed(1);
    if (unit === 'pz' && currentQty > 100) qtyInput.value = 1;
    if (unit === 'conf' && currentQty > 10) qtyInput.value = 1;
}

function updateAddQtyStep() {
    const qtyInput = document.getElementById('add-quantity');
    const unit = document.getElementById('add-unit').value;
    qtyInput.step = 'any';
    if (unit === 'g' || unit === 'ml') {
        qtyInput.min = '1';
    } else if (unit === 'kg' || unit === 'l') {
        qtyInput.min = '0.1';
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
    if (unit === 'kg' || unit === 'l') {
        step = val < 1 ? 0.1 : 0.5;
    } else if (unit === 'g' || unit === 'ml') {
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

function selectPurchaseType(btn, type, estimatedDate, estimateLabel) {
    btn.parentElement.querySelectorAll('.purchase-type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    const detailDiv = document.getElementById('expiry-detail');
    
    // Save current quantity before switching, so we can preserve it
    const currentQty = document.getElementById('add-quantity').value;
    
    if (type === 'new') {
        detailDiv.innerHTML = `
            <div class="expiry-estimate">
                <span class="expiry-estimate-label">Scadenza stimata: <strong>${estimateLabel}</strong></span>
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

function selectLocation(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('add-location').value = loc;
}

async function submitAdd(e) {
    e.preventDefault();
    showLoading(true);
    
    try {
        const selectedUnit = document.getElementById('add-unit').value;
        const productUnit = currentProduct.unit || 'pz';
        
        const result = await api('inventory_add', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: parseFloat(document.getElementById('add-quantity').value) || 1,
            location: document.getElementById('add-location').value,
            expiry_date: document.getElementById('add-expiry').value || null,
            unit: selectedUnit !== productUnit ? selectedUnit : null,
        });
        
        showLoading(false);
        if (result.success) {
            showToast(`✅ ${currentProduct.name} aggiunto!`, 'success');
            showPage('dashboard');
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
    document.getElementById('use-quantity').value = 1;
    document.getElementById('use-location').value = 'dispensa';
    
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

async function loadUseInventoryInfo() {
    try {
        const data = await api('inventory_list');
        const items = (data.inventory || []).filter(i => i.product_id == currentProduct.id);
        const infoEl = document.getElementById('use-inventory-info');
        
        if (items.length > 0) {
            infoEl.innerHTML = '<strong>📦 Disponibile:</strong> ' + items.map(i => {
                const loc = LOCATIONS[i.location] || { icon: '📦', label: i.location };
                return `${loc.icon} ${loc.label}: ${i.quantity} ${i.unit}`;
            }).join(' · ');
        } else {
            infoEl.innerHTML = '⚠️ Prodotto non presente nell\'inventario.';
        }
    } catch(e) {
        console.error(e);
    }
}

function selectUseLocation(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('use-location').value = loc;
}

async function submitUseAll() {
    showLoading(true);
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            use_all: true,
            location: document.getElementById('use-location').value,
        });
        showLoading(false);
        if (result.success) {
            showToast(`📤 ${currentProduct.name} terminato!`, 'success');
            showPage('dashboard');
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
        const qty = parseFloat(document.getElementById('use-quantity').value) || 1;
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: qty,
            location: document.getElementById('use-location').value,
        });
        showLoading(false);
        if (result.success) {
            showToast(`📤 Usato ${qty} di ${currentProduct.name}. Rimasti: ${result.remaining}`, 'success');
            showPage('dashboard');
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
    const analyzeBtn = document.getElementById('ai-analyze-btn');
    const retakeBtn = document.getElementById('ai-retake-btn');
    const resultDiv = document.getElementById('ai-result');
    
    captureDiv.style.display = 'block';
    previewDiv.style.display = 'none';
    captureBtn.style.display = 'block';
    analyzeBtn.style.display = 'none';
    retakeBtn.style.display = 'none';
    resultDiv.style.display = 'none';
    
    try {
        if (aiStream) {
            aiStream.getTracks().forEach(t => t.stop());
        }
        aiStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        });
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
    document.getElementById('ai-analyze-btn').style.display = 'block';
    document.getElementById('ai-retake-btn').style.display = 'block';
}

function retakePhotoAI() {
    document.getElementById('ai-result').style.display = 'none';
    initAICamera();
}

async function analyzeWithAI() {
    const resultDiv = document.getElementById('ai-result');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<p>🤖 Analisi in corso...</p><div class="loading-spinner" style="margin:12px auto"></div>';
    
    const canvas = document.getElementById('ai-canvas');
    const imageData = canvas.toDataURL('image/jpeg', 0.7);
    
    // We'll use a free approach: analyze image colors and shapes locally
    // and try to identify using image analysis heuristics
    const ctx = canvas.getContext('2d');
    const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    
    // Simple color analysis to guess product type
    let r = 0, g = 0, b = 0;
    const pixels = imgData.data;
    const count = pixels.length / 4;
    for (let i = 0; i < pixels.length; i += 16) { // sample every 4th pixel
        r += pixels[i];
        g += pixels[i + 1];
        b += pixels[i + 2];
    }
    const samples = count / 4;
    r = Math.round(r / samples);
    g = Math.round(g / samples);
    b = Math.round(b / samples);
    
    // Provide a manual identification form since free AI APIs are limited
    resultDiv.innerHTML = `
        <h4>🤖 Identificazione Prodotto</h4>
        <p style="font-size:0.85rem;color:var(--text-light);margin:8px 0">
            L'analisi automatica ha dei limiti senza API a pagamento. 
            Puoi descrivere il prodotto qui sotto e lo salveremo nel database.
        </p>
        <form class="form" onsubmit="submitAIProduct(event)" style="margin-top:12px">
            <div class="form-group">
                <label>🏷️ Che prodotto è? *</label>
                <input type="text" id="ai-product-name" class="form-input" required 
                    placeholder="Es: Yogurt greco, Pasta Barilla..." autofocus>
            </div>
            <div class="form-group">
                <label>🏢 Marca (se visibile)</label>
                <input type="text" id="ai-product-brand" class="form-input" placeholder="Es: Müller, Barilla...">
            </div>
            <div class="form-group">
                <label>📂 Categoria</label>
                <select id="ai-product-category" class="form-input">
                    <option value="">-- Seleziona --</option>
                    ${Object.entries(CATEGORY_ICONS).map(([k, v]) => `<option value="${k}">${v} ${k.charAt(0).toUpperCase() + k.slice(1)}</option>`).join('')}
                </select>
            </div>
            <button type="submit" class="btn btn-large btn-accent full-width">✅ Salva e Continua</button>
        </form>
    `;
}

async function submitAIProduct(e) {
    e.preventDefault();
    showLoading(true);
    
    const name = document.getElementById('ai-product-name').value;
    const brand = document.getElementById('ai-product-brand').value;
    const category = document.getElementById('ai-product-category').value;
    
    // Save the captured image as base64 (we could save to file, but for simplicity use image_url)
    const canvas = document.getElementById('ai-canvas');
    // For a lightweight approach, don't store the actual image data in DB
    
    try {
        const result = await api('product_save', {}, 'POST', {
            name, brand, category,
            unit: 'pz',
            default_quantity: 1,
        });
        
        if (result.success) {
            currentProduct = { id: result.id, name, brand, category, unit: 'pz', default_quantity: 1 };
            showLoading(false);
            showToast('Prodotto identificato e salvato!', 'success');
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
        expiryStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        });
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
    navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
    }).then(stream => {
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

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', () => {
    showPage('dashboard');
});
