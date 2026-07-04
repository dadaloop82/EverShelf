# MCP (Model Context Protocol)

EverShelf exposes pantry data to AI agents via a companion MCP server in [`mcp-server/`](../../mcp-server/).

## Quick start (stdio — Claude Desktop, Cursor)

```bash
cd mcp-server
npm install
export EVERSHELF_URL=https://your-host
export EVERSHELF_TOKEN=your-api-token   # if API_TOKEN is set on server
node src/index.js
```

## Remote HTTP transport

For agents that cannot spawn a local process (n8n, cloud LLM, reverse proxy):

```bash
cd mcp-server
npm install
export EVERSHELF_URL=https://your-evershelf-host
export EVERSHELF_TOKEN=your-api-token
export MCP_HTTP_PORT=8787
export MCP_HTTP_TOKEN=choose-a-long-random-secret
npm run start:http
```

Endpoint: `POST https://your-host:8787/mcp` with header `Authorization: Bearer <MCP_HTTP_TOKEN>`.

Health check: `GET /health`

## Claude Desktop

```json
{
  "mcpServers": {
    "evershelf": {
      "command": "node",
      "args": ["/path/to/EverShelf/mcp-server/src/index.js"],
      "env": {
        "EVERSHELF_URL": "https://your-evershelf-host",
        "EVERSHELF_TOKEN": ""
      }
    }
  }
}
```

## Tools

| Tool | Description |
|------|-------------|
| `get_inventory` | Stock by location |
| `get_expiring_soon` | Expiry alerts |
| `get_pantry_stats` | Dashboard totals |
| `get_shopping_list` | Current list |
| `get_smart_shopping` | AI restock predictions |
| `add_shopping_items` | Add to list |
| `use_inventory_item` | Record consumption |
| `suggest_recipe` | AI recipe from pantry |
| `mealie_list_recipes` | Search Mealie (server must have `MEALIE_URL` + `MEALIE_API_TOKEN`) |
| `mealie_import_recipe` | Import Mealie recipe into EverShelf archive |

## Mealie

Set on the **EverShelf server** `.env`:

```env
MEALIE_URL=https://mealie.example.com
MEALIE_API_TOKEN=your-token
```

API: `mealie_status`, `mealie_list`, `mealie_import` (POST `{ slug }` or `{ id }`).

See [Discussion #94](https://github.com/dadaloop82/EverShelf/discussions/94) for community feature voting.
