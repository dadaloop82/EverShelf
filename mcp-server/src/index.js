#!/usr/bin/env node
/**
 * EverShelf MCP Server — stdio transport (Claude Desktop, Cursor).
 */

import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { createEverShelfApi, createMcpServer } from './create-server.js';

const api = createEverShelfApi();
const server = createMcpServer(api);

const transport = new StdioServerTransport();
await server.connect(transport);
console.error('[evershelf-mcp] Ready —', api.baseUrl);
