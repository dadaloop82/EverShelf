<?php
/**
 * EverShelf Health / Fuel Mode — daily activity snapshots + meal budget for recipes.
 * Optional: phone Health Bridge can POST via X-Health-Token; UI can save manually.
 */

/** Ensure health_* tables exist (called from migrateDB). */
function healthEnsureTables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS health_daily (
        date TEXT PRIMARY KEY,
        source TEXT NOT NULL DEFAULT 'manual',
        burned_kcal REAL,
        active_kcal REAL,
        steps INTEGER,
        exercise_min INTEGER,
        exercise_types TEXT,
        sleep_hours REAL,
        hydration_ml INTEGER,
        resting_hr REAL,
        weight_kg REAL,
        raw_json TEXT,
        synced_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS health_profile (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        sex TEXT,
        birth_year INTEGER,
        height_cm REAL,
        weight_kg REAL,
        activity_default TEXT DEFAULT 'moderate',
        goal TEXT DEFAULT 'maintain',
        daily_kcal_override INTEGER,
        enabled INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS health_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_hash TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL DEFAULT 'Health Bridge',
        created_at TEXT NOT NULL,
        last_used_at TEXT,
        revoked_at TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS health_meals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        meal TEXT NOT NULL DEFAULT 'pranzo',
        title TEXT NOT NULL DEFAULT '',
        kcal REAL,
        protein_g REAL,
        carbs_g REAL,
        fat_g REAL,
        servings REAL NOT NULL DEFAULT 1,
        source TEXT NOT NULL DEFAULT 'recipe',
        created_at TEXT NOT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_health_meals_date ON health_meals(date)");
    try {
        $cols = $db->query("PRAGMA table_info(health_meals)")->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('source', $names, true)) {
            $db->exec("ALTER TABLE health_meals ADD COLUMN source TEXT NOT NULL DEFAULT 'recipe'");
        }
    } catch (Throwable $e) { /* ignore */ }
}

function healthTodayDate(): string {
    try {
        $tz = env('APP_TIMEZONE', '');
        if ($tz !== '') {
            $dt = new DateTime('now', new DateTimeZone($tz));
            return $dt->format('Y-m-d');
        }
    } catch (Throwable $e) {
        // fall through
    }
    return date('Y-m-d');
}

function healthGetProfile(PDO $db): array {
    healthEnsureTables($db);
    $row = $db->query('SELECT * FROM health_profile WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'sex' => null,
            'birth_year' => null,
            'height_cm' => null,
            'weight_kg' => null,
            'activity_default' => 'moderate',
            'goal' => 'maintain',
            'daily_kcal_override' => null,
            'enabled' => 1,
        ];
    }
    return [
        'sex' => $row['sex'] ?: null,
        'birth_year' => $row['birth_year'] !== null ? (int)$row['birth_year'] : null,
        'height_cm' => $row['height_cm'] !== null ? (float)$row['height_cm'] : null,
        'weight_kg' => $row['weight_kg'] !== null ? (float)$row['weight_kg'] : null,
        'activity_default' => $row['activity_default'] ?: 'moderate',
        'goal' => $row['goal'] ?: 'maintain',
        'daily_kcal_override' => $row['daily_kcal_override'] !== null ? (int)$row['daily_kcal_override'] : null,
        'enabled' => (int)($row['enabled'] ?? 1),
    ];
}

function healthSaveProfile(PDO $db, array $input): array {
    healthEnsureTables($db);
    $cur = healthGetProfile($db);
    $sex = array_key_exists('sex', $input) ? ($input['sex'] !== null && $input['sex'] !== '' ? (string)$input['sex'] : null) : $cur['sex'];
    if ($sex !== null && !in_array($sex, ['m', 'f', 'other'], true)) {
        $sex = $cur['sex'];
    }
    $goal = array_key_exists('goal', $input) ? (string)$input['goal'] : $cur['goal'];
    if (!in_array($goal, ['maintain', 'lose', 'gain'], true)) {
        $goal = 'maintain';
    }
    $activity = array_key_exists('activity_default', $input) ? (string)$input['activity_default'] : $cur['activity_default'];
    if (!in_array($activity, ['sedentary', 'light', 'moderate', 'active'], true)) {
        $activity = 'moderate';
    }
    $birthYear = array_key_exists('birth_year', $input)
        ? ($input['birth_year'] !== null && $input['birth_year'] !== '' ? (int)$input['birth_year'] : null)
        : $cur['birth_year'];
    if ($birthYear !== null && ($birthYear < 1920 || $birthYear > (int)date('Y') - 10)) {
        $birthYear = $cur['birth_year'];
    }
    $height = array_key_exists('height_cm', $input)
        ? ($input['height_cm'] !== null && $input['height_cm'] !== '' ? (float)$input['height_cm'] : null)
        : $cur['height_cm'];
    $weight = array_key_exists('weight_kg', $input)
        ? ($input['weight_kg'] !== null && $input['weight_kg'] !== '' ? (float)$input['weight_kg'] : null)
        : $cur['weight_kg'];
    $override = array_key_exists('daily_kcal_override', $input)
        ? ($input['daily_kcal_override'] !== null && $input['daily_kcal_override'] !== '' ? (int)$input['daily_kcal_override'] : null)
        : $cur['daily_kcal_override'];
    $enabled = array_key_exists('enabled', $input) ? ((int)!empty($input['enabled'])) : $cur['enabled'];
    $now = date('c');
    $db->prepare('INSERT INTO health_profile
        (id, sex, birth_year, height_cm, weight_kg, activity_default, goal, daily_kcal_override, enabled, updated_at)
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET
            sex=excluded.sex, birth_year=excluded.birth_year, height_cm=excluded.height_cm,
            weight_kg=excluded.weight_kg, activity_default=excluded.activity_default, goal=excluded.goal,
            daily_kcal_override=excluded.daily_kcal_override, enabled=excluded.enabled, updated_at=excluded.updated_at
    ')->execute([$sex, $birthYear, $height, $weight, $activity, $goal, $override, $enabled, $now]);
    return healthGetProfile($db);
}

function healthNormalizeDailyPayload(array $input, string $defaultSource = 'manual'): array {
    $date = trim((string)($input['date'] ?? ''));
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = healthTodayDate();
    }
    $source = trim((string)($input['source'] ?? $defaultSource));
    if ($source === '') {
        $source = $defaultSource;
    }
    $allowedSources = ['manual', 'health_connect', 'google_fit', 'bridge', 'demo'];
    if (!in_array($source, $allowedSources, true)) {
        $source = $defaultSource;
    }
    $numOrNull = static function ($v): ?float {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (float)$v : null;
    };
    $intOrNull = static function ($v): ?int {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (int)$v : null;
    };
    $types = $input['exercise_types'] ?? null;
    if (is_string($types) && $types !== '') {
        $decoded = json_decode($types, true);
        $types = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode(',', $types))));
    }
    if (!is_array($types)) {
        $types = null;
    }
    return [
        'date' => $date,
        'source' => $source,
        'burned_kcal' => $numOrNull($input['burned_kcal'] ?? null),
        'active_kcal' => $numOrNull($input['active_kcal'] ?? null),
        'steps' => $intOrNull($input['steps'] ?? null),
        'exercise_min' => $intOrNull($input['exercise_min'] ?? null),
        'exercise_types' => $types,
        'sleep_hours' => $numOrNull($input['sleep_hours'] ?? null),
        'hydration_ml' => $intOrNull($input['hydration_ml'] ?? null),
        'resting_hr' => $numOrNull($input['resting_hr'] ?? null),
        'weight_kg' => $numOrNull($input['weight_kg'] ?? null),
        'synced_at' => trim((string)($input['synced_at'] ?? '')) ?: date('c'),
    ];
}

function healthUpsertDaily(PDO $db, array $payload, ?array $raw = null): array {
    healthEnsureTables($db);
    $now = date('c');
    $typesJson = isset($payload['exercise_types']) ? json_encode(array_values($payload['exercise_types']), JSON_UNESCAPED_UNICODE) : null;
    $rawJson = $raw !== null ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null;
    // Merge: keep previous non-null fields when new payload omits them
    $prev = healthGetDaily($db, $payload['date']);
    $merge = static function ($new, $old) {
        return $new !== null ? $new : $old;
    };
    $row = [
        'date' => $payload['date'],
        'source' => $payload['source'] ?: ($prev['source'] ?? 'manual'),
        'burned_kcal' => $merge($payload['burned_kcal'], $prev['burned_kcal'] ?? null),
        'active_kcal' => $merge($payload['active_kcal'], $prev['active_kcal'] ?? null),
        'steps' => $merge($payload['steps'], $prev['steps'] ?? null),
        'exercise_min' => $merge($payload['exercise_min'], $prev['exercise_min'] ?? null),
        'exercise_types' => $payload['exercise_types'] ?? ($prev['exercise_types'] ?? null),
        'sleep_hours' => $merge($payload['sleep_hours'], $prev['sleep_hours'] ?? null),
        'hydration_ml' => $merge($payload['hydration_ml'], $prev['hydration_ml'] ?? null),
        'resting_hr' => $merge($payload['resting_hr'], $prev['resting_hr'] ?? null),
        'weight_kg' => $merge($payload['weight_kg'], $prev['weight_kg'] ?? null),
        'synced_at' => $payload['synced_at'],
        'updated_at' => $now,
    ];
    if ($typesJson === null && !empty($row['exercise_types']) && is_array($row['exercise_types'])) {
        $typesJson = json_encode(array_values($row['exercise_types']), JSON_UNESCAPED_UNICODE);
    } elseif ($typesJson === null && is_string($row['exercise_types'] ?? null)) {
        $typesJson = $row['exercise_types'];
    }
    $db->prepare('INSERT INTO health_daily
        (date, source, burned_kcal, active_kcal, steps, exercise_min, exercise_types,
         sleep_hours, hydration_ml, resting_hr, weight_kg, raw_json, synced_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(date) DO UPDATE SET
            source=excluded.source,
            burned_kcal=excluded.burned_kcal,
            active_kcal=excluded.active_kcal,
            steps=excluded.steps,
            exercise_min=excluded.exercise_min,
            exercise_types=excluded.exercise_types,
            sleep_hours=excluded.sleep_hours,
            hydration_ml=excluded.hydration_ml,
            resting_hr=excluded.resting_hr,
            weight_kg=excluded.weight_kg,
            raw_json=COALESCE(excluded.raw_json, health_daily.raw_json),
            synced_at=excluded.synced_at,
            updated_at=excluded.updated_at
    ')->execute([
        $row['date'], $row['source'], $row['burned_kcal'], $row['active_kcal'], $row['steps'],
        $row['exercise_min'], $typesJson, $row['sleep_hours'], $row['hydration_ml'],
        $row['resting_hr'], $row['weight_kg'], $rawJson, $row['synced_at'], $row['updated_at'],
    ]);
    // Optionally update profile weight from daily
    if ($row['weight_kg'] !== null) {
        $db->prepare('INSERT INTO health_profile (id, weight_kg, updated_at) VALUES (1, ?, ?)
            ON CONFLICT(id) DO UPDATE SET weight_kg=excluded.weight_kg, updated_at=excluded.updated_at
        ')->execute([$row['weight_kg'], $now]);
    }
    return healthGetDaily($db, $row['date']) ?? $row;
}

function healthGetDaily(PDO $db, ?string $date = null): ?array {
    healthEnsureTables($db);
    $date = $date ?: healthTodayDate();
    $stmt = $db->prepare('SELECT * FROM health_daily WHERE date = ?');
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (!empty($row['exercise_types']) && is_string($row['exercise_types'])) {
        $decoded = json_decode($row['exercise_types'], true);
        $row['exercise_types'] = is_array($decoded) ? $decoded : [];
    } else {
        $row['exercise_types'] = [];
    }
    foreach (['burned_kcal', 'active_kcal', 'sleep_hours', 'resting_hr', 'weight_kg'] as $f) {
        if ($row[$f] !== null) {
            $row[$f] = (float)$row[$f];
        }
    }
    foreach (['steps', 'exercise_min', 'hydration_ml'] as $f) {
        if ($row[$f] !== null) {
            $row[$f] = (int)$row[$f];
        }
    }
    unset($row['raw_json']);
    return $row;
}

/** Mifflin–St Jeor BMR; falls back to 1650 if profile incomplete. */
function healthEstimateBmr(array $profile): int {
    $w = $profile['weight_kg'] ?? null;
    $h = $profile['height_cm'] ?? null;
    $by = $profile['birth_year'] ?? null;
    $sex = $profile['sex'] ?? null;
    if (!$w || !$h || !$by) {
        return 1650;
    }
    $age = max(15, (int)date('Y') - (int)$by);
    $bmr = (10 * (float)$w) + (6.25 * (float)$h) - (5 * $age);
    if ($sex === 'm') {
        $bmr += 5;
    } elseif ($sex === 'f') {
        $bmr -= 161;
    } else {
        $bmr -= 78; // midpoint
    }
    return (int)max(1200, round($bmr));
}

function healthEstimateTdee(array $profile, ?array $daily): int {
    if (!empty($profile['daily_kcal_override']) && (int)$profile['daily_kcal_override'] > 0) {
        return (int)$profile['daily_kcal_override'];
    }
    $bmr = healthEstimateBmr($profile);
    $mult = [
        'sedentary' => 1.2,
        'light' => 1.375,
        'moderate' => 1.55,
        'active' => 1.725,
    ];
    $base = (int)round($bmr * ($mult[$profile['activity_default'] ?? 'moderate'] ?? 1.55));
    $burned = $daily['burned_kcal'] ?? null;
    $active = $daily['active_kcal'] ?? null;
    // Wearables: "burned" may mean total daily expenditure OR active-only.
    // If burned is clearly below BMR, treat it as active kcal.
    if ($burned !== null && $burned > 0) {
        if ((float)$burned >= $bmr * 0.85) {
            return (int)round(($base * 0.35) + ((float)$burned * 0.65));
        }
        return (int)round($bmr + (float)$burned);
    }
    if ($active !== null && $active > 0) {
        return (int)round($bmr + $active);
    }
    return $base;
}

/**
 * Compute a deterministic meal budget for Fuel Mode.
 *
 * @return array{available:bool,reason?:string,intent:string,label:string,target_kcal:int,protein_g:int,carbs:string,fat:string,notes:string[],daily:?array,tdee:int,meal_share:float}
 */
function computeMealBudget(PDO $db, string $meal = 'pranzo', array $options = []): array {
    healthEnsureTables($db);
    $profile = healthGetProfile($db);
    $daily = healthGetDaily($db, healthTodayDate());

    $shares = [
        'colazione' => 0.25,
        'pranzo' => 0.35,
        'cena' => 0.35,
        'dolce' => 0.12,
        'succo' => 0.08,
    ];
    $share = $shares[$meal] ?? 0.33;

    $tdee = healthEstimateTdee($profile, $daily);
    $goal = $profile['goal'] ?? 'maintain';
    if ($goal === 'lose') {
        $tdee = (int)round($tdee * 0.9);
    } elseif ($goal === 'gain') {
        $tdee = (int)round($tdee * 1.08);
    }

    $steps = (int)($daily['steps'] ?? 0);
    $exMin = (int)($daily['exercise_min'] ?? 0);
    $active = (float)($daily['active_kcal'] ?? 0);
    $sleep = $daily['sleep_hours'] ?? null;
    $burned = $daily['burned_kcal'] ?? null;

    $intent = 'equilibrio';
    $label = 'Equilibrio';
    $notes = [];

    $highActivity = $exMin >= 30 || $active >= 350 || $steps >= 10000;
    $lowActivity = $exMin < 15 && $steps > 0 && $steps < 4000 && $active < 150;
    $noData = $daily === null
        || ($burned === null && $active <= 0 && $steps <= 0 && $exMin <= 0);

    if ($noData) {
        $intent = 'equilibrio';
        $label = 'Equilibrio (dati limitati)';
        $notes[] = 'Nessun dato salute fresco: budget stimato dal profilo. Inserisci kcal/passi o collega Health Bridge.';
    } elseif ($highActivity) {
        $intent = 'ricarica';
        $label = 'Ricarica post-attività';
        $share = min(0.42, $share + 0.05);
        $notes[] = 'Attività elevata oggi → pasto più energetico e ricco di proteine.';
    } elseif ($lowActivity) {
        $intent = 'leggero';
        $label = 'Giorno leggero';
        $share = max(0.22, $share - 0.05);
        $notes[] = 'Poca attività → densità calorica moderata, più volume da verdure.';
    }

    if ($sleep !== null && $sleep < 6) {
        $notes[] = 'Sonno basso: preferisci un piatto semplice e caldo, max ~25 min.';
        if ($intent === 'equilibrio') {
            $intent = 'comfort';
            $label = 'Comfort smart';
        }
    }

    if (in_array('pocafame', $options, true)) {
        $share *= 0.7;
        $notes[] = 'Opzione Poca Fame attiva: porzione ridotta.';
    }
    if (in_array('salutare', $options, true)) {
        $notes[] = 'Extra salutare: prediligi verdure, cereali integrali, pochi grassi saturi.';
    }

    $targetKcal = (int)max(180, round($tdee * $share));
    // Protein: ~1.6–2.0 g/kg on ricarica, else ~1.2–1.4; distribute by meal share
    $weight = (float)($profile['weight_kg'] ?? $daily['weight_kg'] ?? 70);
    $protPerKg = $intent === 'ricarica' ? 1.8 : ($intent === 'leggero' ? 1.2 : 1.4);
    $dailyProt = (int)round($weight * $protPerKg);
    $protein = (int)max(12, round($dailyProt * $share));

    $carbs = 'medi';
    $fat = 'moderati';
    if ($intent === 'ricarica') {
        $carbs = 'medi-alti';
        $fat = 'moderati';
    } elseif ($intent === 'leggero') {
        $carbs = 'moderati-bassi';
        $fat = 'bassi';
    }

    $available = !$noData || !empty($profile['weight_kg']) || !empty($profile['daily_kcal_override']);

    return [
        'available' => $available,
        'intent' => $intent,
        'label' => $label,
        'target_kcal' => $targetKcal,
        'protein_g' => $protein,
        'carbs' => $carbs,
        'fat' => $fat,
        'notes' => $notes,
        'daily' => $daily,
        'tdee' => $tdee,
        'meal_share' => round($share, 3),
        'date' => healthTodayDate(),
        'has_fresh_data' => !$noData,
        'goal' => $goal,
        'profile' => [
            'sex' => $profile['sex'] ?? null,
            'weight_kg' => $profile['weight_kg'] ?? null,
            'goal' => $goal,
            'activity_default' => $profile['activity_default'] ?? null,
        ],
    ];
}

/** Prompt block for Gemini when Fuel Mode is on. */
function healthFuelPromptBlock(array $budget): string {
    if (empty($budget)) {
        return '';
    }
    $notes = '';
    if (!empty($budget['notes'])) {
        $notes = "\n- note: " . implode(' | ', $budget['notes']);
    }
    $dailyBits = [];
    $d = $budget['daily'] ?? null;
    if (is_array($d)) {
        if ($d['burned_kcal'] !== null) {
            $dailyBits[] = 'kcal bruciate ~' . (int)$d['burned_kcal'];
        }
        if (!empty($d['steps'])) {
            $dailyBits[] = (int)$d['steps'] . ' passi';
        }
        if (!empty($d['exercise_min'])) {
            $dailyBits[] = (int)$d['exercise_min'] . ' min esercizio';
        }
        if ($d['sleep_hours'] !== null) {
            $dailyBits[] = 'sonno ' . round((float)$d['sleep_hours'], 1) . 'h';
        }
    }
    $todayLine = $dailyBits ? ("\n- oggi: " . implode(', ', $dailyBits)) : '';
    $kcal = (int)$budget['target_kcal'];
    $prot = (int)$budget['protein_g'];
    $lo = (int)round($kcal * 0.85);
    $hi = (int)round($kcal * 1.15);
    $goal = $budget['goal'] ?? 'maintain';
    $goalLine = match ($goal) {
        'lose' => 'obiettivo profilo: DIMAGRIMENTO (deficit controllato, priorità proteine e volume verdure)',
        'gain' => 'obiettivo profilo: MASSA (surplus leggero, proteine alte, carb sufficienti)',
        default => 'obiettivo profilo: MANTENIMENTO (equilibrio kcal/macro)',
    };
    return "\n\nMEAL BUDGET / A RITMO MIO (obbligatorio: ricetta guidata da profilo biologico + obiettivo + attività di oggi; rispetta ±15% sulle kcal; non inventare dati salute):\n"
        . "- {$goalLine}\n"
        . "- intent pasto oggi: {$budget['intent']} ({$budget['label']})\n"
        . "- target_kcal per porzione: {$kcal} (accettabile {$lo}–{$hi})\n"
        . "- protein_g: ≥{$prot}\n"
        . "- carbs: {$budget['carbs']}; fat: {$budget['fat']}\n"
        . "- TDEE stimato oggi: {$budget['tdee']} kcal"
        . $todayLine
        . $notes
        . "\nCostruisci il piatto ESPLICITAMENTE per questo budget (non un piatto generico)."
        . "\nObbligatorio: campo `fuel_why` (2–4 frasi nella lingua della ricetta) che spiega PERCHÉ hai scelto QUEGLI ingredienti in base a: obiettivo profilo, attività/sonno di oggi, intent del pasto, e vincoli dispensa/scadenze. Cita 2–4 ingredienti concreti e il motivo (es. proteine post-allenamento, carb per ricarica, verdure per volume a basso kcal)."
        . "\nIn nutrition_note una frase sul match kcal/macro. I valori in `nutrition` devono avvicinarsi al target.";
}

function healthHashToken(string $token): string {
    return hash('sha256', $token);
}

function healthCreateBridgeToken(PDO $db, string $label = 'Health Bridge'): array {
    healthEnsureTables($db);
    $plain = 'es_health_' . bin2hex(random_bytes(24));
    $hash = healthHashToken($plain);
    $now = date('c');
    $db->prepare('INSERT INTO health_tokens (token_hash, label, created_at) VALUES (?, ?, ?)')
        ->execute([$hash, $label !== '' ? $label : 'Health Bridge', $now]);
    $id = (int)$db->lastInsertId();
    return [
        'id' => $id,
        'token' => $plain,
        'label' => $label !== '' ? $label : 'Health Bridge',
        'created_at' => $now,
    ];
}

function healthListBridgeTokens(PDO $db): array {
    healthEnsureTables($db);
    $rows = $db->query('SELECT id, label, created_at, last_used_at, revoked_at FROM health_tokens ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function ($r) {
        return [
            'id' => (int)$r['id'],
            'label' => $r['label'],
            'created_at' => $r['created_at'],
            'last_used_at' => $r['last_used_at'],
            'revoked' => !empty($r['revoked_at']),
            'revoked_at' => $r['revoked_at'],
        ];
    }, $rows);
}

function healthRevokeBridgeToken(PDO $db, ?int $id = null): int {
    healthEnsureTables($db);
    $now = date('c');
    if ($id !== null && $id > 0) {
        $stmt = $db->prepare('UPDATE health_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
        $stmt->execute([$now, $id]);
        return $stmt->rowCount();
    }
    $stmt = $db->prepare('UPDATE health_tokens SET revoked_at = ? WHERE revoked_at IS NULL');
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

function healthBridgeTokenValid(PDO $db, string $token): bool {
    if ($token === '' || !str_starts_with($token, 'es_health_')) {
        return false;
    }
    healthEnsureTables($db);
    $hash = healthHashToken($token);
    $stmt = $db->prepare('SELECT id FROM health_tokens WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1');
    $stmt->execute([$hash]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        return false;
    }
    $db->prepare('UPDATE health_tokens SET last_used_at = ? WHERE id = ?')->execute([date('c'), $id]);
    return true;
}

function healthStatusPayload(PDO $db): array {
    $profile = healthGetProfile($db);
    $daily = healthGetDaily($db);
    $tokens = healthListBridgeTokens($db);
    $activeTokens = array_values(array_filter($tokens, static fn($t) => empty($t['revoked'])));
    $budgetPreview = computeMealBudget($db, 'pranzo', []);
    $mealsToday = healthGetMealsForDate($db, healthTodayDate());
    $eaten = healthMealsTotals($mealsToday);
    $tdee = (int)($budgetPreview['tdee'] ?? 0);
    return [
        'success' => true,
        'today' => healthTodayDate(),
        'profile' => $profile,
        'daily' => $daily,
        'bridge_linked' => count($activeTokens) > 0,
        'bridge_tokens' => $tokens,
        'budget_preview' => $budgetPreview,
        'meals_today' => $mealsToday,
        'eaten_today' => $eaten,
        'remaining_kcal' => $tdee > 0 ? max(0, $tdee - (int)round($eaten['kcal'])) : null,
    ];
}

/** Logged meals for a calendar day (newest first). */
function healthGetMealsForDate(PDO $db, string $date): array {
    healthEnsureTables($db);
    $stmt = $db->prepare('SELECT * FROM health_meals WHERE date = ? ORDER BY id DESC');
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static function (array $r): array {
        return [
            'id' => (int)$r['id'],
            'date' => $r['date'],
            'meal' => $r['meal'],
            'title' => $r['title'],
            'kcal' => $r['kcal'] !== null ? (float)$r['kcal'] : null,
            'protein_g' => $r['protein_g'] !== null ? (float)$r['protein_g'] : null,
            'carbs_g' => $r['carbs_g'] !== null ? (float)$r['carbs_g'] : null,
            'fat_g' => $r['fat_g'] !== null ? (float)$r['fat_g'] : null,
            'servings' => (float)$r['servings'],
            'source' => $r['source'] ?? 'recipe',
            'created_at' => $r['created_at'],
        ];
    }, $rows);
}

/** @param list<array> $meals */
function healthMealsTotals(array $meals): array {
    $kcal = 0.0;
    $prot = 0.0;
    foreach ($meals as $m) {
        $kcal += (float)($m['kcal'] ?? 0);
        $prot += (float)($m['protein_g'] ?? 0);
    }
    return [
        'kcal' => round($kcal, 1),
        'protein_g' => round($prot, 1),
        'count' => count($meals),
    ];
}

/**
 * Log consumed meal from EverShelf only (recipe cook or inventory use) — no manual diary.
 * Recipe: one entry per title/day. Use: additive per product use.
 * @return array{success:bool,meal?:array,error?:string,eaten_today?:array,remaining_kcal?:int|null,skipped?:bool}
 */
function healthLogMeal(PDO $db, array $input): array {
    healthEnsureTables($db);
    if (env('HEALTH_ENABLED', 'false') !== 'true') {
        return ['success' => false, 'error' => 'health_disabled'];
    }
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') {
        return ['success' => false, 'error' => 'title_required'];
    }
    $source = strtolower(trim((string)($input['source'] ?? 'recipe')));
    if (!in_array($source, ['recipe', 'use'], true)) {
        $source = 'recipe';
    }
    $meal = strtolower(trim((string)($input['meal'] ?? 'pranzo')));
    $allowed = ['colazione', 'pranzo', 'cena', 'dolce', 'succo', 'altro'];
    if (!in_array($meal, $allowed, true)) {
        $meal = 'altro';
    }
    $servings = (float)($input['servings'] ?? 1);
    if ($servings <= 0) {
        $servings = 1;
    }
    if ($servings > 10) {
        $servings = 10;
    }
    $date = trim((string)($input['date'] ?? ''));
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = healthTodayDate();
    }

    // Recipe: log once per title/day (first ingredient use wins)
    if ($source === 'recipe') {
        $dup = $db->prepare('SELECT id FROM health_meals WHERE date = ? AND source = ? AND title = ? LIMIT 1');
        $dup->execute([$date, 'recipe', mb_substr($title, 0, 200)]);
        if ($dup->fetchColumn()) {
            $meals = healthGetMealsForDate($db, $date);
            $eaten = healthMealsTotals($meals);
            $budget = computeMealBudget($db, $meal === 'altro' ? 'pranzo' : $meal, []);
            $tdee = (int)($budget['tdee'] ?? 0);
            return [
                'success' => true,
                'skipped' => true,
                'eaten_today' => $eaten,
                'remaining_kcal' => $tdee > 0 ? max(0, $tdee - (int)round($eaten['kcal'])) : null,
                'tdee' => $tdee,
            ];
        }
    }

    $kcal = isset($input['kcal']) && $input['kcal'] !== '' && $input['kcal'] !== null
        ? (float)$input['kcal'] * $servings : null;
    $prot = isset($input['protein_g']) && $input['protein_g'] !== '' && $input['protein_g'] !== null
        ? (float)$input['protein_g'] * $servings : null;
    $carbs = isset($input['carbs_g']) && $input['carbs_g'] !== '' && $input['carbs_g'] !== null
        ? (float)$input['carbs_g'] * $servings : null;
    $fat = isset($input['fat_g']) && $input['fat_g'] !== '' && $input['fat_g'] !== null
        ? (float)$input['fat_g'] * $servings : null;

    $now = date('c');
    $db->prepare('INSERT INTO health_meals
        (date, meal, title, kcal, protein_g, carbs_g, fat_g, servings, source, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$date, $meal, mb_substr($title, 0, 200), $kcal, $prot, $carbs, $fat, $servings, $source, $now]);
    $id = (int)$db->lastInsertId();
    $meals = healthGetMealsForDate($db, $date);
    $eaten = healthMealsTotals($meals);
    $budget = computeMealBudget($db, $meal === 'altro' ? 'pranzo' : $meal, []);
    $tdee = (int)($budget['tdee'] ?? 0);
    return [
        'success' => true,
        'meal' => [
            'id' => $id,
            'date' => $date,
            'meal' => $meal,
            'title' => $title,
            'kcal' => $kcal,
            'protein_g' => $prot,
            'carbs_g' => $carbs,
            'fat_g' => $fat,
            'servings' => $servings,
            'source' => $source,
            'created_at' => $now,
        ],
        'eaten_today' => $eaten,
        'remaining_kcal' => $tdee > 0 ? max(0, $tdee - (int)round($eaten['kcal'])) : null,
        'tdee' => $tdee,
    ];
}

/**
 * Estimate kcal from a pantry use (not recipe / not waste) and log silently.
 */
function healthLogInventoryUse(PDO $db, int $productId, float $qty, string $notes, ?array $prodInfo = null): void {
    if (env('HEALTH_ENABLED', 'false') !== 'true' || $productId <= 0 || $qty <= 0) {
        return;
    }
    if ($notes !== '' && (str_starts_with($notes, 'Ricetta:') || str_starts_with($notes, 'Recipe:'))) {
        return; // recipe nutrition logged separately when cooking
    }
    if (function_exists('_isWasteNotes') && _isWasteNotes($notes)) {
        return;
    }
    if (!$prodInfo) {
        $stmt = $db->prepare('SELECT name, category, unit, default_quantity, nutriments_json FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $prodInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$prodInfo) {
        return;
    }
    $est = healthEstimateProductMacros($prodInfo, $qty);
    if ($est['kcal'] === null || $est['kcal'] <= 0) {
        return;
    }
    healthLogMeal($db, [
        'title' => (string)($prodInfo['name'] ?? 'Uso dispensa'),
        'meal' => 'altro',
        'source' => 'use',
        'kcal' => $est['kcal'],
        'protein_g' => $est['protein_g'],
        'carbs_g' => $est['carbs_g'],
        'fat_g' => $est['fat_g'],
        'servings' => 1,
    ]);
}

/** @return array{kcal:?float,protein_g:?float,carbs_g:?float,fat_g:?float} */
function healthEstimateProductMacros(array $prod, float $qty): array {
    $catDefaults = [
        'frutta' => 52, 'verdura' => 30, 'carne' => 200, 'pesce' => 130, 'latticini' => 150,
        'pasta' => 350, 'pane' => 265, 'cereali' => 370, 'bevande' => 40, 'condimenti' => 150,
        'conserve' => 80, 'surgelati' => 100, 'snack' => 480, 'altro' => 150,
    ];
    $protDef = [
        'carne' => 20, 'pesce' => 20, 'latticini' => 8, 'pasta' => 12, 'pane' => 9, 'cereali' => 10, 'altro' => 4,
    ];
    $unit = $prod['unit'] ?? 'pz';
    $defQty = (float)($prod['default_quantity'] ?? 0);
    $grams = 100.0;
    if ($unit === 'g') {
        $grams = $qty;
    } elseif ($unit === 'kg') {
        $grams = $qty * 1000;
    } elseif ($unit === 'ml') {
        $grams = $qty;
    } elseif ($unit === 'l') {
        $grams = $qty * 1000;
    } elseif (in_array($unit, ['pz', 'conf'], true) && $defQty >= 20) {
        $grams = $qty * $defQty;
    } elseif (in_array($unit, ['pz', 'conf'], true)) {
        $grams = $qty * max(40, $defQty > 0 ? $defQty : 100);
    }

    $kcal100 = null;
    $prot100 = null;
    $carb100 = null;
    $fat100 = null;
    if (!empty($prod['nutriments_json'])) {
        $nm = json_decode((string)$prod['nutriments_json'], true);
        if (is_array($nm)) {
            $kcal100 = isset($nm['energy-kcal_100g']) ? (float)$nm['energy-kcal_100g']
                : (isset($nm['energy_kcal_100g']) ? (float)$nm['energy_kcal_100g'] : null);
            $prot100 = isset($nm['proteins_100g']) ? (float)$nm['proteins_100g'] : null;
            $carb100 = isset($nm['carbohydrates_100g']) ? (float)$nm['carbohydrates_100g'] : null;
            $fat100 = isset($nm['fat_100g']) ? (float)$nm['fat_100g'] : null;
        }
    }
    $cat = strtolower(trim((string)($prod['category'] ?? 'altro')));
    if ($kcal100 === null) {
        $kcal100 = (float)($catDefaults[$cat] ?? $catDefaults['altro']);
    }
    if ($prot100 === null) {
        $prot100 = (float)($protDef[$cat] ?? 4);
    }
    $factor = $grams / 100.0;
    return [
        'kcal' => round($kcal100 * $factor, 1),
        'protein_g' => round($prot100 * $factor, 1),
        'carbs_g' => $carb100 !== null ? round($carb100 * $factor, 1) : null,
        'fat_g' => $fat100 !== null ? round($fat100 * $factor, 1) : null,
    ];
}
