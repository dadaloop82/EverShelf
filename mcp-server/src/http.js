#!/usr/bin/env node
/**
 * EverShelf MCP — Streamable HTTP transport (remote access).
 *
 * Env:
 *   MCP_HTTP_PORT   — listen port (default 8787)
 *   MCP_HTTP_TOKEN  — Bearer token required on /mcp (recommended)
 *   EVERSHELF_URL   — EverShelf base URL
 *   EVERSHELF_TOKEN — EverShelf API token
 */

import http from 'node:http';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { createEverShelfApi, createMcpServer } from './create-server.js';

const port = parseInt(process.env.MCP_HTTP_PORT || '8787', 10);
const httpToken = process.env.MCP_HTTP_TOKEN || process.env.MCP_HTTP_SECRET || '';

const api = createEverShelfApi();
const server = createMcpServer(api);

const transport = new StreamableHTTPServerTransport({
  sessionIdGenerator: undefined,
});

await server.connect(transport);

const requestListener = async (req, res) => {
  if (req.url !== '/mcp' && req.url !== '/mcp/') {
    if (req.url === '/health' || req.url === '/') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true, service: 'evershelf-mcp', transport: 'streamable-http' }));
      return;
    }
    res.writeHead(404);
    res.end('Not found');
    return;
  }

  if (httpToken) {
    const auth = req.headers.authorization || '';
    const bearer = auth.startsWith('Bearer ') ? auth.slice(7) : '';
    if (bearer !== httpToken) {
      res.writeHead(401, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'unauthorized' }));
      return;
    }
  }

  let parsedBody;
  if (req.method === 'POST' || req.method === 'PUT' || req.method === 'PATCH') {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const raw = Buffer.concat(chunks).toString('utf8');
    if (raw) {
      try {
        parsedBody = JSON.parse(raw);
      } catch {
        res.writeHead(400);
        res.end('Invalid JSON');
        return;
      }
    }
  }

  await transport.handleRequest(req, res, parsedBody);
};

http.createServer(requestListener).listen(port, () => {
  console.error(`[evershelf-mcp-http] Listening on :${port}/mcp → ${api.baseUrl}`);
  if (!httpToken) {
    console.error('[evershelf-mcp-http] WARNING: MCP_HTTP_TOKEN not set — endpoint is open');
  }
});
