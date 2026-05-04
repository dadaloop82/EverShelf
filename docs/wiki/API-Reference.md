# 🔌 API Reference

EverShelf exposes a single PHP endpoint: **`api/index.php`**. All actions are selected via the `action` query parameter.

> **Full OpenAPI 3.1 spec:** [`docs/openapi.yaml`](https://github.com/dadaloop82/EverShelf/blob/main/docs/openapi.yaml)

---

## Base URL

```
https://your-server/api/index.php?action=ACTION_NAME
```

GET requests pass parameters as query params; POST requests send JSON in the body.

---

## Rate Limits

| Tier | Limit | Applies to |
|------|-------|-----------|
| Standard | 120 req/min | All general endpoints |
| AI | 15 req/min | `gemini_*`, `generate_recipe*` |
| Strict | 5 req/min | `report_error` |

Exceeded limits return HTTP 429 with `{"error": "rate_limit_exceeded"}`.

---

## Products

### `search_barcode` — GET
Search for a product in the local database by barcode.

| Param | Type | Description |
|-------|------|-------------|
| `barcode` | string | EAN/UPC barcode |

### `lookup_barcode` — GET
Look up a barcode on Open Food Facts (external call).

| Param | Type | Description |
|-------|------|-------------|
| `barcode` | string | EAN/UPC barcode |

### `product_save` — POST
Create or update a product. Pass `id` to update.

```json
{
  "id": 42,
  "name": "Pasta Barilla",
  "brand": "Barilla",
  "category": "pasta",
  "unit": "g",
  "default_quantity": 500,
  "barcode": "8076800105988"
}
```

### `product_get` — GET
Get product details by `id`.

### `product_delete` — POST
Delete a product by `id`.

### `products_list` — GET
List all products.

### `products_search` — GET
Search products by name (`?q=pasta`).

---

## Inventory

### `inventory_list` — GET
List all inventory items with product details, grouped.

**Response:**
```json
{
  "inventory": [
    {
      "id": 1,
      "product_id": 42,
      "name": "Pasta Barilla",
      "quantity": 2,
      "unit": "pz",
      "location": "dispensa",
      "expiry_date": "2027-03-01",
      "opened_at": null,
      "vacuum_sealed": 0
    }
  ]
}
```

### `inventory_add` — POST
Add a product to inventory.

```json
{
  "product_id": 42,
  "quantity": 3,
  "location": "dispensa",
  "expiry_date": "2027-03-01",
  "vacuum_sealed": false
}
```

**Locations:** `dispensa`, `frigo`, `freezer`, `altro`

### `inventory_use` — POST
Consume inventory. Set `use_all: true` to consume all stock at a location.

```json
{
  "product_id": 42,
  "quantity": 1,
  "location": "dispensa"
}
```

```json
{
  "product_id": 42,
  "use_all": true,
  "location": "__all__",
  "notes": "Buttato"
}
```

### `inventory_update` — POST
Update an inventory entry by `id`.

### `inventory_delete` — POST
Remove an inventory entry by `id`.

### `inventory_summary` — GET
Returns item counts per location.

```json
{
  "dispensa": 12,
  "frigo": 5,
  "freezer": 8
}
```

---

## Transactions (Log)

### `transactions_list` — GET
Returns the operation log.

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 50 | Results per page |
| `offset` | int | 0 | Pagination offset |

### `transaction_undo` — POST
Undo a transaction within 24 hours.

```json
{ "id": 873 }
```

**Response on success:**
```json
{ "success": true, "name": "Tonno all'olio d'oliva" }
```

**Error cases:**
```json
{ "error": "...", "already_undone": true }
{ "error": "...", "too_old": true }
```

### `stats` — GET
Returns waste and consumption statistics for the last 30 days.

---

## AI / Gemini

All AI endpoints require `GEMINI_API_KEY` to be configured. Rate limit: 15 req/min.

### `gemini_expiry` — POST
Read an expiry date from a product photo.

```json
{ "image": "data:image/jpeg;base64,..." }
```

### `gemini_identify` — POST
Identify a product from a photo.

```json
{ "image": "data:image/jpeg;base64,..." }
```

### `gemini_chat` — POST
Chat with the AI kitchen assistant.

```json
{ "message": "Cosa posso fare con la pasta?", "history": [] }
```

### `generate_recipe` — POST
Generate a recipe based on current inventory.

```json
{ "persons": 2, "meal": "dinner", "preferences": {} }
```

### `generate_recipe_stream` — POST
Same as `generate_recipe` but streams output via Server-Sent Events.

### `gemini_product_hint` — POST
Get AI storage location + shelf-life hint for a new product.

### `gemini_shopping_enrich` — POST
Enrich shopping suggestions with practical tips.

### `gemini_anomaly_explain` — POST
Get a plain-language explanation for a specific inventory anomaly.

---

## Shopping List (Bring!)

Requires `BRING_EMAIL` and `BRING_PASSWORD` in `.env`.

### `bring_list` — GET
Get the current Bring! shopping list.

### `bring_add` — POST
Add items to the Bring! list.

```json
{ "items": ["Latte", "Pane"] }
```

### `bring_remove` — POST
Remove an item from the Bring! list.

```json
{ "name": "Latte" }
```

### `smart_shopping` — GET
Get smart shopping predictions based on consumption history.

---

## Settings

### `get_settings` — GET
Returns current settings as **boolean flags only** (no raw key values):

```json
{
  "gemini_key_set": true,
  "bring_configured": false,
  "tts_enabled": false,
  "scale_enabled": true,
  "demo_mode": false,
  "settings_token_set": true
}
```

### `save_settings` — POST
Update server configuration. If `SETTINGS_TOKEN` is set, requires header:

```
X-Settings-Token: your_token
```

```json
{
  "gemini_api_key": "...",
  "bring_email": "...",
  "scale_enabled": true,
  "scale_gateway_url": "ws://127.0.0.1:8765"
}
```

---

## Error Reporting

### `report_error` — POST
Submit an automatic error report (creates a GitHub Issue).

```json
{
  "type": "uncaught-error",
  "message": "...",
  "stack": "...",
  "context": {}
}
```

Only creates an issue if:
- The client is running the latest released version
- The fingerprint hasn't been seen in the last 24 hours

---

## Anomaly Detection

### `inventory_anomalies` — GET
Returns inventory rows where stored quantity significantly differs from transaction history.

### `dismiss_anomaly` — POST
Dismiss an anomaly banner without changing inventory.

---

## Scale Integration

### `scale_relay` (SSE) — GET
Relays BLE scale readings from the gateway to the browser via Server-Sent Events (avoids HTTPS→WS mixed-content issues).

### `scale_ping` — GET
Check if the Scale Gateway is reachable.

### `scale_discover` — GET
Scan the local LAN for a running Scale Gateway instance.
