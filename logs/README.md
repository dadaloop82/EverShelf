# logs/

This directory contains EverShelf runtime log files.

Files are generated automatically by `api/logger.php` and follow the naming pattern:

```
evershelf_YYYY-MM-DD_HH.log
```

The directory is tracked in git (via this README) but `.log` files are ignored via `.gitignore`.

For project overview and features (including Corporate UI), see the root [README.md](../README.md).

## Configuration (`.env`)

| Variable | Default | Description |
|---|---|---|
| `LOG_LEVEL` | `INFO` | Minimum log level: `DEBUG`, `INFO`, `WARN`, `ERROR` |
| `LOG_ROTATE_HOURS` | `24` | Hours per file before rotating |
| `LOG_MAX_FILES` | `14` | Maximum number of rotated files to keep |

## Format

```
[2026-05-18 14:23:11] [INFO ] [rid=a1b2c3d4] [action] Message {"ctx":"value"}
```

## Remote inspection

```
GET /api/?action=get_logs&lines=100&level=WARN
```
