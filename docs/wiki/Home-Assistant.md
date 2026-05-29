# Home Assistant Integration

EverShelf integrates natively with [Home Assistant](https://www.home-assistant.io/) to bring your pantry data into your smart-home automations.

**Capabilities:**
- 📡 **REST sensors** — expose pantry counts as HA sensor entities (expiring, expired, shopping list, total items)
- 🔔 **Webhooks** — trigger HA automations on pantry events (expiry alerts, shopping additions, stock updates)
- 📣 **Push notifications** — send alerts to your phone via any HA `notify.*` service
- 🔊 **TTS on smart speakers** — read recipe steps aloud on any HA `media_player` entity
- ⚙️ **In-app config panel** — configure everything from Settings → 🏠 tab (no need to edit `.env` manually)

---

## Quick Setup

1. **Generate a Long-Lived Access Token** in Home Assistant:
   - Open HA → your **Profile** (bottom-left avatar) → **Security** → **Long-Lived Access Tokens** → **Create Token**
   - Copy the generated token — you won't see it again.

2. **Open EverShelf Settings** → tab **🏠 Home Assistant**.

3. Fill in **Home Assistant URL** (e.g. `http://homeassistant.local:8123`) and paste the token.

4. Click **Test connection** — you should see ✅.

5. Enable the features you want (TTS, Webhooks, REST Sensors) and click **Save HA settings**.

---

## REST Sensors

Add EverShelf pantry data as native HA sensor entities that update automatically.

### Endpoints

| URL | Returns | Sensor |
|-----|---------|--------|
| `/api/?action=ha_sensor` | Items expiring soon (≤`HA_EXPIRY_DAYS` days) | `sensor.evershelf_overview` |
| `/api/?action=ha_sensor&sensor=expired` | Expired items count | `sensor.evershelf_expired` |
| `/api/?action=ha_sensor&sensor=shopping` | Shopping list item count | `sensor.evershelf_shopping` |
| `/api/?action=ha_sensor&sensor=total` | Total pantry items | `sensor.evershelf_total` |
| `/api/?action=ha_sensor&sensor=product` | Full inventory — all items with complete details | `sensor.evershelf_products` |
| `/api/?action=ha_sensor&sensor=product&id=42` | Full details for inventory row `id=42` | — |
| `/api/?action=ha_sensor&sensor=product&name=milk` | Full details for items whose name contains "milk" | — |
| `/api/?action=ha_sensor&sensor=product&location=frigo` | All items in a specific location | — |

### Generate & Copy YAML

In Settings → 🏠 Home Assistant → **REST Sensors** card, click **Copy YAML** to get a ready-to-paste `configuration.yaml` block that already contains your EverShelf URL.

### Manual YAML example

```yaml
# configuration.yaml
sensor:
  - platform: rest
    name: "EverShelf Overview"
    unique_id: evershelf_overview
    resource: "http://YOUR_EVERSHELF_URL/api/?action=ha_sensor"
    scan_interval: 300        # seconds
    value_template: "{{ value_json.state }}"
    json_attributes:
      - expiring_soon
      - expiring_3d
      - expired_items
      - total_items
      - shopping_items
      - expiring_list      # full product details for expiring items
      - expired_list       # full product details for expired items
      - low_stock_list     # full product details for items with quantity ≤ 1
      - next_expiry_name
      - next_expiry_date
      - days_to_next_expiry
      - last_updated
    unit_of_measurement: "items"

  - platform: rest
    name: "EverShelf Shopping Count"
    unique_id: evershelf_shopping
    resource: "http://YOUR_EVERSHELF_URL/api/?action=ha_sensor&sensor=shopping"
    scan_interval: 180
    value_template: "{{ value_json.state }}"
    unit_of_measurement: "items"

  # Full product inventory — each item includes all details (location, brand, category, …)
  - platform: rest
    name: "EverShelf Products"
    unique_id: evershelf_products
    resource: "http://YOUR_EVERSHELF_URL/api/?action=ha_sensor&sensor=product"
    scan_interval: 600
    value_template: "{{ value_json.state }}"
    json_attributes:
      - items
      - last_updated
    unit_of_measurement: "items"
```

Restart Home Assistant after editing `configuration.yaml`.

Every product entry inside `expiring_list`, `expired_list`, `low_stock_list`, and `sensor=product` responses follows the same schema:

```json
{
  "product_id":       42,
  "inventory_id":     7,
  "name":             "Latte intero",
  "brand":            "Parmalat",
  "category":         "Lattiero-caseari",
  "quantity":         2.0,
  "unit":             "conf",
  "default_quantity": 1000.0,
  "package_unit":     "ml",
  "location":         "frigo",
  "expiry_date":      "2025-06-15",
  "days_remaining":   3,
  "opened_at":        "2025-06-10",
  "vacuum_sealed":    false
}
```

Field details:

| Field | Type | Description |
|-------|------|-------------|
| `product_id` | int | Products table ID |
| `inventory_id` | int | Inventory row ID |
| `name` | string | Product name |
| `brand` | string\|null | Brand (if set) |
| `category` | string\|null | Category (if set) |
| `quantity` | float | Current quantity in inventory |
| `unit` | string | Unit (`conf`, `g`, `ml`, `pz`, …) |
| `default_quantity` | float | Default package size (e.g. 1000 for 1-litre carton) |
| `package_unit` | string\|null | Unit of the default package (`g`, `ml`) |
| `location` | string\|null | Storage location (`frigo`, `freezer`, `dispensa`, …) |
| `expiry_date` | string\|null | ISO date `YYYY-MM-DD` |
| `days_remaining` | int\|null | Days until expiry (negative = already expired) |
| `opened_at` | string\|null | ISO date when the package was opened |
| `vacuum_sealed` | bool | Whether the item is vacuum-sealed |

---

## Webhook Automations

EverShelf fires an HTTP POST to your HA webhook URL when pantry events occur.

### Create the HA Webhook Automation

1. HA → **Settings** → **Automations & Scenes** → **Create Automation**
2. Click **Add Trigger** → choose **Webhook**
3. HA generates a **Webhook ID** — copy it
4. Paste the ID into **Settings → 🏠 Home Assistant → Webhook ID**
5. Select which events should trigger the webhook

### Supported Events

| Event key | When it fires |
|-----------|--------------|
| `expiry` | Daily cron — items expiring within `HA_EXPIRY_DAYS` days |
| `shopping_add` | Item added to the shopping list |
| `stock_update` | Inventory quantity changed |
| `barcode_scan` | (reserved for future use) |

### Webhook Payload (POST body)

```json
{
  "event": "expiry_alert",
  "timestamp": "2025-06-12T08:00:00+00:00",
  "data": {
    "type": "expiring_soon",
    "count": 3,
    "days": 3,
    "summary": "Milk, Yogurt, Butter",
    "items": [
      {
        "product_id": 42,
        "inventory_id": 7,
        "name": "Milk",
        "brand": "Parmalat",
        "category": "Dairy",
        "quantity": 2.0,
        "unit": "conf",
        "default_quantity": 1000.0,
        "package_unit": "ml",
        "location": "frigo",
        "expiry_date": "2025-06-14",
        "days_remaining": 2,
        "opened_at": "2025-06-10",
        "vacuum_sealed": false
      }
    ]
  }
}
```

### Example: Expiry Alert → Telegram

```yaml
alias: EverShelf Expiry Alert
trigger:
  - platform: webhook
    webhook_id: "evershelf_webhook_abc123"   # ← your Webhook ID
action:
  - service: notify.telegram_bot
    data:
      message: >
        🥫 EverShelf: {{ trigger.json.data.count }} product(s) expiring soon
        {% for item in trigger.json.data.items %}
        — {{ item.name }}{% if item.brand %} ({{ item.brand }}){% endif %} ·
          {{ item.quantity }} {{ item.unit }} · 📍 {{ item.location }} ·
          expires {{ item.expiry_date }} ({{ item.days_remaining }} days)
        {% endfor %}
```

### Example: Automation on location

You can filter by location in the automation template to only alert for fridge items:

```yaml
condition:
  - condition: template
    value_template: >
      {{ trigger.json.data.items | selectattr('location','eq','frigo') | list | length > 0 }}
```

---

## Push Notifications

If you prefer to receive push alerts without using webhooks, configure a **HA notify service** directly:

1. Find your notify service name in HA: **Developer Tools → Services** → search `notify`
2. Paste it into **Settings → 🏠 → Notify service** (e.g. `notify.mobile_app_my_phone`)
3. Save

EverShelf will call this service from the cron job whenever expiry alerts fire.

---

## TTS on Smart Speakers

Read recipe steps aloud on an Amazon Echo, Google Home, Sonos, or any HA `media_player`.

### Configuration

1. Enter the **Entity ID** of your media player (e.g. `media_player.kitchen_display`)
   - Find it in HA: **Developer Tools → States**
2. Click **Apply HA preset to TTS tab** — this auto-fills the TTS tab with the correct HA endpoint and auth headers
3. Save settings

### How it Works

When recipe step TTS is triggered, EverShelf calls:

```
POST /api/services/tts/speak
Authorization: Bearer <HA_TOKEN>
{
  "entity_id": "media_player.kitchen_display",
  "message": "Add 200 g of flour and mix well."
}
```

The request is proxied through the EverShelf PHP backend (avoids CORS / mixed-content issues).

---

## Environment Variables Reference

All settings are configurable from `.env` or from the in-app Settings panel.

| Variable | Default | Description |
|----------|---------|-------------|
| `HA_ENABLED` | `false` | Master switch for all HA features |
| `HA_URL` | _(empty)_ | Base URL of HA instance, no trailing slash |
| `HA_TOKEN` | _(empty)_ | Long-Lived Access Token |
| `HA_TTS_ENTITY` | _(empty)_ | `media_player` entity for TTS |
| `HA_WEBHOOK_ID` | _(empty)_ | Webhook trigger ID from HA automation |
| `HA_WEBHOOK_EVENTS` | `expiry,shopping_add,stock_update` | Comma-separated list of events |
| `HA_NOTIFY_SERVICE` | _(empty)_ | HA notify service (e.g. `notify.mobile_app_phone`) |
| `HA_EXPIRY_DAYS` | `3` | Days before expiry to trigger the daily alert |

---

## Troubleshooting

**Test shows ❌ "Connection failed"**
- Verify the URL is reachable from the EverShelf server (not just your browser)
- If using HTTPS with a self-signed certificate, the server-side cURL request may fail — use HTTP on the local network instead
- Check that port 8123 (or your custom port) is open on the HA host

**Test shows ❌ "bad_token"**
- The Long-Lived Access Token may have expired or been revoked — generate a new one in HA Profile

**Webhook not firing**
- Confirm HA_ENABLED=true and the Webhook ID is exactly as shown in HA
- Check the EverShelf cron is running (`/api/cron_smart_shopping.php` every 5 minutes)
- For shopping/stock events: verify the event name is in `HA_WEBHOOK_EVENTS`

**TTS not speaking**
- Ensure the media player entity is online in HA (check its state in Developer Tools)
- Try the "Apply HA preset to TTS tab" button and send a test from the TTS tab
- Check HA logs for `tts.speak` errors (some platforms require `tts_options`)

**Sensors show unavailable in HA**
- The EverShelf URL must be reachable from the HA host
- If running EverShelf behind a reverse proxy, ensure `/api/` is accessible
- Use `scan_interval` ≥ 60 to avoid hammering the server
