# logs/

Questa cartella contiene i log di runtime di EverShelf.

I file vengono generati automaticamente da `api/logger.php` e hanno la forma:

```
evershelf_YYYY-MM-DD_HH.log
```

La cartella è inclusa in git (tramite questo README) ma i file `.log` sono ignorati via `.gitignore`.

## Configurazione (`.env`)

| Variabile | Default | Descrizione |
|---|---|---|
| `LOG_LEVEL` | `INFO` | Livello minimo: `DEBUG`, `INFO`, `WARN`, `ERROR` |
| `LOG_ROTATE_HOURS` | `24` | Ore per file prima di ruotare |
| `LOG_MAX_FILES` | `14` | Numero massimo di file da conservare |

## Formato

```
[2026-05-18 14:23:11] [INFO ] [rid=a1b2c3d4] [action] Messaggio {"ctx":"value"}
```

## Inspection remota

```
GET /api/?action=get_logs&lines=100&level=WARN
```
