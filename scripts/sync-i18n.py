#!/usr/bin/env python3
"""Sync translation files: ensure all locales have the same keys as it.json (reference)."""
from __future__ import annotations

import copy
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / 'translations'
REF = 'it.json'
LOCALES = ['it.json', 'en.json', 'de.json', 'fr.json', 'es.json']

# New keys added across all locales (nested path -> value per locale)
NEW_KEYS: dict[str, dict[str, str]] = {
    'dashboard.banner_prediction_confirmed': {
        'it': '✅ Confermato — il sistema ricalcolerà le previsioni dalle prossime registrazioni',
        'en': '✅ Confirmed — forecasts will recalculate from your next entries',
        'de': '✅ Bestätigt — Prognosen werden aus den nächsten Einträgen neu berechnet',
        'fr': '✅ Confirmé — les prévisions seront recalculées à partir de vos prochains enregistrements',
        'es': '✅ Confirmado — las previsiones se recalcularán con tus próximos registros',
    },
    'dashboard.banner_anomaly_explain_fail': {
        'it': 'Impossibile ottenere spiegazione AI',
        'en': 'Could not get AI explanation',
        'de': 'KI-Erklärung konnte nicht abgerufen werden',
        'fr': 'Impossible d\'obtenir l\'explication IA',
        'es': 'No se pudo obtener la explicación de IA',
    },
    'dashboard.banner_anomaly_dismissed': {
        'it': 'Anomalia ignorata',
        'en': 'Anomaly dismissed',
        'de': 'Anomalie ignoriert',
        'fr': 'Anomalie ignorée',
        'es': 'Anomalía descartada',
    },
    'error.copy_failed': {
        'it': 'Copia negli appunti non riuscita',
        'en': 'Copy to clipboard failed',
        'de': 'Kopieren in die Zwischenablage fehlgeschlagen',
        'fr': 'Échec de la copie dans le presse-papiers',
        'es': 'Error al copiar al portapapeles',
    },
    'error.invalid_quantity': {
        'it': 'Quantità non valida',
        'en': 'Invalid quantity',
        'de': 'Ungültige Menge',
        'fr': 'Quantité invalide',
        'es': 'Cantidad no válida',
    },
    'dashboard.banner_finished_restore_prompt': {
        'it': 'Quante {unit} di {name} hai ancora? (stima sistema: {qty})',
        'en': 'How many {unit} of {name} do you still have? (system estimate: {qty})',
        'de': 'Wie viele {unit} {name} hast du noch? (Systemschätzung: {qty})',
        'fr': 'Combien de {unit} de {name} vous reste-t-il ? (estimation : {qty})',
        'es': '¿Cuántas {unit} de {name} te quedan? (estimación del sistema: {qty})',
    },
    'time.just_now': {
        'it': 'adesso', 'en': 'just now', 'de': 'gerade eben', 'fr': 'à l\'instant', 'es': 'ahora',
    },
    'time.seconds_ago': {
        'it': '{n}s fa', 'en': '{n}s ago', 'de': 'vor {n}s', 'fr': 'il y a {n}s', 'es': 'hace {n}s',
    },
    'time.minutes_ago': {
        'it': '{n} min fa', 'en': '{n} min ago', 'de': 'vor {n} min', 'fr': 'il y a {n} min', 'es': 'hace {n} min',
    },
    'time.hours_ago': {
        'it': '{n} h fa', 'en': '{n} h ago', 'de': 'vor {n} h', 'fr': 'il y a {n} h', 'es': 'hace {n} h',
    },
    'time.days_ago': {
        'it': '{n} gg fa', 'en': '{n} d ago', 'de': 'vor {n} T', 'fr': 'il y a {n} j', 'es': 'hace {n} d',
    },
    'use.locations_short': {
        'it': 'posti', 'en': 'places', 'de': 'Orte', 'fr': 'emplacements', 'es': 'ubicaciones',
    },
    'move.moved_simple': {
        'it': '📦 Spostato in {location}',
        'en': '📦 Moved to {location}',
        'de': '📦 Nach {location} verschoben',
        'fr': '📦 Déplacé vers {location}',
        'es': '📦 Movido a {location}',
    },
    'product.history_badge': {
        'it': '📊 storico', 'en': '📊 history', 'de': '📊 Verlauf', 'fr': '📊 historique', 'es': '📊 historial',
    },
    'ai.conservation_hint': {
        'it': '🤖 AI: conserva in {location}',
        'en': '🤖 AI: store in {location}',
        'de': '🤖 KI: lagere in {location}',
        'fr': '🤖 IA : conserve dans {location}',
        'es': '🤖 IA: conserva en {location}',
    },
    'settings.kiosk_update_required': {
        'it': '⚠️ Aggiorna il kiosk per usare questa funzione',
        'en': '⚠️ Update the kiosk app to use this feature',
        'de': '⚠️ Aktualisiere die Kiosk-App, um diese Funktion zu nutzen',
        'fr': '⚠️ Mettez à jour l\'application kiosk pour utiliser cette fonction',
        'es': '⚠️ Actualiza la app kiosk para usar esta función',
    },
    'shopping.bring_names_migrated': {
        'it': '🔄 {n} nomi generalizzati in Bring!',
        'en': '🔄 {n} names generalized in Bring!',
        'de': '🔄 {n} Namen in Bring! verallgemeinert',
        'fr': '🔄 {n} noms généralisés dans Bring !',
        'es': '🔄 {n} nombres generalizados en Bring!',
    },
    'scan.mode_shopping_activated': {
        'it': '🛒 Modalità Spesa attivata!',
        'en': '🛒 Shopping mode activated!',
        'de': '🛒 Einkaufsmodus aktiviert!',
        'fr': '🛒 Mode courses activé !',
        'es': '🛒 ¡Modo compras activado!',
    },
    'settings.scale.discover_scanning': {
        'it': '🔍 Scansione rete locale per gateway bilancia…',
        'en': '🔍 Scanning local network for scale gateway…',
        'de': '🔍 Lokales Netz wird nach Waagen-Gateway durchsucht…',
        'fr': '🔍 Recherche du gateway balance sur le réseau local…',
        'es': '🔍 Buscando pasarela de báscula en la red local…',
    },
    'settings.scale.discover_found': {
        'it': '✅ Gateway trovato: {url}{more}',
        'en': '✅ Gateway found: {url}{more}',
        'de': '✅ Gateway gefunden: {url}{more}',
        'fr': '✅ Gateway trouvé : {url}{more}',
        'es': '✅ Pasarela encontrada: {url}{more}',
    },
    'settings.scale.discover_not_found': {
        'it': '❌ Nessun gateway su {subnet}. Avvia l\'app Android sulla stessa Wi-Fi.',
        'en': '❌ No gateway found on {subnet}. Make sure the Android app is running and on the same Wi-Fi.',
        'de': '❌ Kein Gateway in {subnet}. Android-App auf demselben WLAN starten.',
        'fr': '❌ Aucun gateway sur {subnet}. Lancez l\'app Android sur le même Wi-Fi.',
        'es': '❌ Ninguna pasarela en {subnet}. Inicia la app Android en la misma Wi-Fi.',
    },
    'settings.scale.discover_failed': {
        'it': '❌ Ricerca fallita: {error}',
        'en': '❌ Discovery failed: {error}',
        'de': '❌ Suche fehlgeschlagen: {error}',
        'fr': '❌ Échec de la recherche : {error}',
        'es': '❌ Búsqueda fallida: {error}',
    },
    'settings.scale.discover_auto': {
        'it': '🔍 Auto', 'en': '🔍 Auto', 'de': '🔍 Auto', 'fr': '🔍 Auto', 'es': '🔍 Auto',
    },
    'settings.scale.unknown_device': {
        'it': 'Dispositivo sconosciuto',
        'en': 'Unknown device',
        'de': 'Unbekanntes Gerät',
        'fr': 'Appareil inconnu',
        'es': 'Dispositivo desconocido',
    },
    'product.from_history': {
        'it': ' (da storico)', 'en': ' (from history)', 'de': ' (aus Verlauf)', 'fr': ' (historique)', 'es': ' (del historial)',
    },
    'recipes.ing_stock_line': {
        'it': 'Hai {have} · restano {remain} dopo l\'uso',
        'en': 'You have {have} · {remain} left after use',
        'de': 'Du hast {have} · {remain} bleiben nach Gebrauch',
        'fr': 'Vous avez {have} · il reste {remain} après usage',
        'es': 'Tienes {have} · quedan {remain} después del uso',
    },
    'recipes.ing_use_all_note': {
        'it': 'uso totale (<5% della confezione intera)',
        'en': 'use all (<5% of full package left)',
        'de': 'alles verwenden (<5% der Vollpackung)',
        'fr': 'tout utiliser (<5% du conditionnement entier)',
        'es': 'usar todo (<5% del envase completo)',
    },
}

# fr/es gaps filled with proper translations (flat key -> value)
FR_FILL: dict[str, str] = {
    'action.related_stock_title': 'Aussi à la maison',
    'dashboard.banner_expired_action_modify': 'Modifier',
    'dashboard.banner_expired_action_vacuum': 'Mettre sous vide',
    'recipes.stream_interrupted': 'Génération interrompue (réponse serveur incomplète). Vérifiez les logs ou réessayez.',
    'scan.stock_in_pantry': 'Déjà à la maison :',
    'scanner.expiry_found': 'Date trouvée',
    'scanner.expiry_raw_label': 'Lu',
    'scanner.expiry_read_fail': 'Impossible de lire la date.',
    'settings.info.act_new_products': 'Nouveaux produits',
    'settings.info.act_restock': 'Réapprovisionnements',
    'settings.info.act_title': 'Activité mensuelle',
    'settings.info.act_tx_month': 'Mouvements',
    'settings.info.act_tx_year': 'Mouvements annuels',
    'settings.info.act_use': 'Utilisations',
    'settings.info.ai_calls': 'Appels',
    'settings.info.ai_hint': 'Consommation mensuelle et coût estimé pour la clé API actuelle.',
    'settings.info.ai_overview': 'Aperçu IA, inventaire et état du système',
    'settings.info.ai_title': 'Gemini AI — Utilisation des tokens',
    'settings.info.bring_days': 'jeton expire dans {n} jours',
    'settings.info.bring_expired': 'jeton expiré',
    'settings.info.by_action': 'Répartition par fonction',
    'settings.info.by_model': 'Répartition par modèle',
    'settings.info.cache_entries': 'produits',
    'settings.info.calls_unit': 'appels',
    'settings.info.currency_hint': 'Devise utilisée pour tous les coûts et prix dans l\'app.',
    'settings.info.currency_title': 'Devise',
    'settings.info.db_size': 'Base de données',
    'settings.info.est_cost': 'Coût est.',
    'settings.info.input_tok': 'Tokens entrée',
    'settings.info.inv_active': 'Actifs',
    'settings.info.inv_expired': 'Expirés',
    'settings.info.inv_expiring': 'Expirent (7j)',
    'settings.info.inv_finished': 'Terminés',
    'settings.info.inv_products': 'Produits totaux',
    'settings.info.inv_title': 'Inventaire',
    'settings.info.last_backup': 'Dernière sauvegarde',
    'settings.info.loading': 'Chargement…',
    'settings.info.log_level': 'Niveau de log',
    'settings.info.log_size': 'Logs',
    'settings.info.output_tok': 'Tokens sortie',
    'settings.info.price_cache': 'Cache prix',
    'settings.info.pricing_note': 'Tarifs Gemini : 2.5-flash $0.15/M in · $0.60/M out — 2.0-flash $0.10/M in · $0.40/M out.',
    'settings.info.system_title': 'Système',
    'settings.info.tab': 'Info',
    'settings.info.total_tokens': 'Tokens totaux',
    'settings.info.year_label': 'Année {year}',
    'settings.tab_general': 'Général',
    'settings.tts.test_sound_btn': '🔔 Test sonore',
    'shopping.pantry_hint': 'Déjà à la maison : {qty}',
    'startup.check_db_legacy': 'Ancienne BD (dispensa.db)',
    'startup.check_scale': 'Passerelle balance',
    'startup.check_tts': 'URL synthèse vocale',
    'startup.critical_error_intro': 'L\'application ne peut pas démarrer en raison des problèmes suivants :',
    'startup.error_network_detail': 'Le navigateur ne peut pas joindre le serveur PHP.\n\nCauses possibles :\n• Apache/PHP n\'est pas démarré\n• Problème réseau ou pare-feu\n• URL incorrecte\n\nDémarrez le serveur et réessayez.',
    'toast.vacuum_sealed': '{name} enregistré sous vide',
}

ES_FILL = {
    'action.related_stock_title': 'También en casa',
    'dashboard.banner_expired_action_modify': 'Editar',
    'dashboard.banner_expired_action_vacuum': 'Poner al vacío',
    'recipes.stream_interrupted': 'Generación interrumpida (respuesta del servidor incompleta). Revisa los logs o inténtalo de nuevo.',
    'scan.stock_in_pantry': 'Ya en despensa:',
    'scanner.expiry_found': 'Fecha encontrada',
    'scanner.expiry_raw_label': 'Leído',
    'scanner.expiry_read_fail': 'No se puede leer la fecha.',
    'settings.info.act_new_products': 'Productos nuevos',
    'settings.info.act_restock': 'Reabastecimientos',
    'settings.info.act_title': 'Actividad mensual',
    'settings.info.act_tx_month': 'Movimientos',
    'settings.info.act_tx_year': 'Movimientos anuales',
    'settings.info.act_use': 'Usos',
    'settings.info.ai_calls': 'Llamadas',
    'settings.info.ai_hint': 'Consumo mensual y coste estimado para la clave API actual.',
    'settings.info.ai_overview': 'Resumen de IA, inventario y estado del sistema',
    'settings.info.ai_title': 'Gemini AI — Uso de tokens',
    'settings.info.bring_days': 'token expira en {n} días',
    'settings.info.bring_expired': 'token expirado',
    'settings.info.by_action': 'Desglose por función',
    'settings.info.by_model': 'Desglose por modelo',
    'settings.info.cache_entries': 'productos',
    'settings.info.calls_unit': 'llamadas',
    'settings.info.currency_hint': 'Moneda usada para todos los costes y precios en la app.',
    'settings.info.currency_title': 'Moneda',
    'settings.info.db_size': 'Base de datos',
    'settings.info.est_cost': 'Coste est.',
    'settings.info.input_tok': 'Tokens de entrada',
    'settings.info.inv_active': 'Activos',
    'settings.info.inv_expired': 'Caducados',
    'settings.info.inv_expiring': 'Caducan (7d)',
    'settings.info.inv_finished': 'Agotados',
    'settings.info.inv_products': 'Productos totales',
    'settings.info.inv_title': 'Inventario',
    'settings.info.last_backup': 'Última copia',
    'settings.info.loading': 'Cargando…',
    'settings.info.log_level': 'Nivel de log',
    'settings.info.log_size': 'Logs',
    'settings.info.output_tok': 'Tokens de salida',
    'settings.info.price_cache': 'Caché de precios',
    'settings.info.pricing_note': 'Precios Gemini: 2.5-flash $0.15/M in · $0.60/M out — 2.0-flash $0.10/M in · $0.40/M out.',
    'settings.info.system_title': 'Sistema',
    'settings.info.tab': 'Info',
    'settings.info.total_tokens': 'Tokens totales',
    'settings.info.year_label': 'Año {year}',
    'settings.tab_general': 'General',
    'settings.tts.test_sound_btn': '🔔 Prueba de sonido',
    'shopping.pantry_hint': 'Ya en casa: {qty}',
    'startup.check_db_legacy': 'BD antigua (dispensa.db)',
    'startup.check_scale': 'Pasarela báscula',
    'startup.check_tts': 'URL texto a voz',
    'startup.critical_error_intro': 'La app no puede iniciarse por los siguientes problemas:',
    'startup.error_network_detail': 'El navegador no puede conectar con el servidor PHP.\n\nPosibles causas:\n• Apache/PHP no está en ejecución\n• Problema de red o firewall\n• URL incorrecta\n\nInicia el servidor e inténtalo de nuevo.',
    'toast.vacuum_sealed': '{name} guardado al vacío',
}


def flatten(obj: dict, prefix: str = '') -> dict[str, str]:
    out: dict[str, str] = {}
    for k, v in obj.items():
        key = f'{prefix}.{k}' if prefix else k
        if isinstance(v, dict):
            out.update(flatten(v, key))
        else:
            out[key] = v
    return out


def set_nested(root: dict, dotted: str, value: str) -> None:
    parts = dotted.split('.')
    d = root
    for p in parts[:-1]:
        d = d.setdefault(p, {})
    d[parts[-1]] = value


def main() -> None:
    ref = json.loads((ROOT / REF).read_text(encoding='utf-8'))
    ref_flat = flatten(ref)
    en_flat = flatten(json.loads((ROOT / 'en.json').read_text(encoding='utf-8')))

    for fname in LOCALES:
        lang = fname.replace('.json', '')
        path = ROOT / fname
        data = json.loads(path.read_text(encoding='utf-8'))
        flat = flatten(data)

        # Fill missing keys from reference (Italian text as last resort via en)
        for key, ref_val in ref_flat.items():
            if key not in flat:
                if lang == 'fr' and key in FR_FILL:
                    val = FR_FILL[key]
                elif lang == 'es' and key in ES_FILL:
                    val = ES_FILL[key]
                elif lang == 'en':
                    val = en_flat.get(key, ref_val)
                else:
                    val = en_flat.get(key, ref_val)
                set_nested(data, key, val)
                flat[key] = val

        # Inject new keys
        for key, per_lang in NEW_KEYS.items():
            set_nested(data, key, per_lang[lang if lang in per_lang else 'en'])

        path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
        print(f'Updated {fname}')


if __name__ == '__main__':
    main()
