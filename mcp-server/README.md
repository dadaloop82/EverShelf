# EverShelf MCP Server

[Model Context Protocol](https://modelcontextprotocol.io/) companion for [EverShelf](https://github.com/dadaloop82/EverShelf).  
Lets AI agents (Claude Desktop, Cursor, Home Assistant LLM, Open WebUI, n8n, …) query and update your pantry via natural language.

The MCP server talks to the same REST API as the web UI (Corporate UI v1.7.57+). It does not render the frontend — only API actions.

## Tools exposed

| Tool | Description |
|------|-------------|
| `get_inventory` | List stock by location |
| `get_expiring_soon` | Items expiring in N days |
| `get_pantry_stats` | Dashboard totals |
| `get_shopping_list` | Current shopping list |
| `get_smart_shopping` | AI restock predictions |
| `add_shopping_items` | Add to shopping list |
| `use_inventory_item` | Record consumption |
| `suggest_recipe` | AI recipe from pantry |

## Requirements

- Node.js **18+**
- Running EverShelf instance (self-hosted)
- `API_TOKEN` on the server if you enabled API authentication

## Install

```bash
cd mcp-server
npm install
```

## Environment

| Variable | Description |
|----------|-------------|
| `EVERSHELF_URL` | Base URL, e.g. `https://pantry.example.com` |
| `EVERSHELF_TOKEN` | Same as `API_TOKEN` in EverShelf `.env` |

## Claude Desktop

Add to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "evershelf": {
      "command": "node",
      "args": ["/path/to/EverShelf/mcp-server/src/index.js"],
      "env": {
        "EVERSHELF_URL": "https://your-evershelf-host",
        "EVERSHELF_TOKEN": "your-api-token-if-set"
      }
    }
  }
}
```

## Cursor

Settings → MCP → Add server with the same command/env.

## Example prompts

- *"What's expiring in the next 48 hours?"*
- *"Add milk and eggs to my shopping list."*
- *"What can I cook tonight with what's in the fridge?"*
- *"I used 2 eggs — update inventory."*
- *"Log half an avocado used from the fridge."* (piece fractions supported in the web app)

## Related docs

- Main project: [README.md](../README.md)
- Design system (web UI): [docs/CORPORATE-UI.md](../docs/CORPORATE-UI.md)

## License

MIT — same as EverShelf.
