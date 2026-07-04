# MCP (Model Context Protocol)

EverShelf exposes pantry data to AI agents via a companion MCP server in [`mcp-server/`](../../mcp-server/).

## Quick start

```bash
cd mcp-server
npm install
export EVERSHELF_URL=https://your-host
export EVERSHELF_TOKEN=your-api-token   # if API_TOKEN is set on server
node src/index.js
```

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

## Roadmap

- [ ] Streamable HTTP transport for remote deployment
- [ ] Mealie recipe integration (discussion #94)
- [ ] Price history tools (#81)

See [Discussion #94](https://github.com/dadaloop82/EverShelf/discussions/94) for community feature voting.
