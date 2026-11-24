# EverShelf on Synology NAS

EverShelf is a self-hosted pantry manager: PHP 8 + SQLite, one container, no
external database. This page is the Synology-specific part of the installation
(DSM Docker / Container Manager).

## Install (Container Manager)

1. Create a shared folder path for the data, e.g. `/volume1/docker/evershelf/data`.
2. Copy `.env.example` from the repo to `/volume1/docker/evershelf/.env` and edit
   what you need (at least `TZ`).
3. In *Container Manager* → *Project* (or a compose stack), use:

```yaml
services:
  evershelf:
    image: ghcr.io/dadaloop82/evershelf:latest
    container_name: evershelf
    ports:
      - "8080:80"
    volumes:
      - /volume1/docker/evershelf/data:/var/www/html/data
      - /volume1/docker/evershelf/.env:/var/www/html/.env:ro
    restart: unless-stopped
    environment:
      - TZ=Europe/Rome
```

4. Start the project. Open `http://<nas-ip>:8080`.

> Prefer a **named Docker volume** for `data` when you can
> (`evershelf_data:/var/www/html/data`). Bind mounts work, but DSM owns the
> host folder and Apache inside the image runs as UID **33** (`www-data`).

## “Data directory / Disk write test / Database connection” on the splash

This is almost always a **permission** problem on the bind-mounted folder.

The image runs the web server as **UID 33 / GID 33**. If
`/volume1/docker/evershelf/data` is owned by your DSM user, the container cannot
create `evershelf.db`.

### Fix (SSH on the NAS)

```bash
sudo mkdir -p /volume1/docker/evershelf/data
sudo chown -R 33:33 /volume1/docker/evershelf/data
# optional — let Settings write .env directly (otherwise overrides stay in SQLite):
# sudo chown 33:33 /volume1/docker/evershelf/.env
sudo docker restart evershelf
```

From EverShelf **2.2.12** the container entrypoint also tries to repair ownership
(and, as a last resort, mode) on every start. A stuck splash after an update is
still worth fixing once on the host with the `chown` above — Synology shared-folder
ACLs can refuse an in-container `chown`.

### File Station alternative

*Control Panel* → *Shared Folder* → the folder → *Edit* → *Permissions*: grant
**Read/Write** to everyone for that docker path, or set the folder owner so UID 33
can write. SSH `chown` is clearer and survives DSM upgrades better.

## Pairing

On first start the UI asks for a **pairing code**. Find it in the container log:

```bash
sudo docker logs evershelf 2>&1 | grep -i "pairing code" | tail -1
```

## Updates

```bash
sudo docker pull ghcr.io/dadaloop82/evershelf:latest
sudo docker restart evershelf
# or recreate the compose project after pull
```

The data folder is on the host — updates do not wipe the pantry.

## See also

- [Installation](Installation) — generic Docker compose
- [FAQ](FAQ) — bind-mount permission errors
- [Unraid](Unraid) — the same UID 33 rule on another NAS
- [Configuration](Configuration) — `.env` keys
