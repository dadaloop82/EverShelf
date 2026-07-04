import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { EverShelfApi } from './evershelf-api.js';

export const TOOLS = [
  {
    name: 'get_inventory',
    description: 'List pantry inventory (all locations). Optional filter by location: dispensa, frigo, freezer.',
    inputSchema: {
      type: 'object',
      properties: {
        location: { type: 'string', description: 'dispensa | frigo | freezer (optional)' },
      },
    },
  },
  {
    name: 'get_expiring_soon',
    description: 'Products expiring within N days (default 7). Returns stats and expiring item lists.',
    inputSchema: {
      type: 'object',
      properties: {
        days: { type: 'number', description: 'Days ahead (default 7)' },
      },
    },
  },
  {
    name: 'get_shopping_list',
    description: 'Current shopping list (Bring or internal mode).',
    inputSchema: { type: 'object', properties: {} },
  },
  {
    name: 'get_smart_shopping',
    description: 'AI smart shopping predictions (urgency, suggested quantities, restock needs).',
    inputSchema: {
      type: 'object',
      properties: {
        plan_days: { type: 'number', description: 'Planning horizon in days (optional)' },
      },
    },
  },
  {
    name: 'add_shopping_items',
    description: 'Add one or more items to the shopping list.',
    inputSchema: {
      type: 'object',
      properties: {
        items: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              name: { type: 'string' },
              specification: { type: 'string' },
            },
            required: ['name'],
          },
          description: 'Items to add',
        },
      },
      required: ['items'],
    },
  },
  {
    name: 'use_inventory_item',
    description: 'Record consumption — remove quantity from pantry (recipe cooked, item used).',
    inputSchema: {
      type: 'object',
      properties: {
        product_id: { type: 'number' },
        quantity: { type: 'number' },
        use_all: { type: 'boolean', description: 'Use entire stock' },
        location: { type: 'string', description: 'dispensa | frigo | freezer' },
        notes: { type: 'string' },
      },
      required: ['product_id'],
    },
  },
  {
    name: 'suggest_recipe',
    description: 'AI recipe suggestion from current pantry (uses Gemini on the EverShelf server).',
    inputSchema: {
      type: 'object',
      properties: {
        location: { type: 'string', description: 'Prefer ingredients from this location' },
        meal: { type: 'string', description: 'breakfast | lunch | dinner | snack' },
      },
    },
  },
  {
    name: 'get_pantry_stats',
    description: 'Dashboard stats: totals, expired, expiring soon, shopping count.',
    inputSchema: { type: 'object', properties: {} },
  },
  {
    name: 'mealie_list_recipes',
    description: 'List recipes from connected Mealie instance (requires MEALIE_URL + MEALIE_API_TOKEN on server).',
    inputSchema: {
      type: 'object',
      properties: {
        query: { type: 'string', description: 'Optional search query' },
        limit: { type: 'number', description: 'Max results (default 20)' },
      },
    },
  },
  {
    name: 'mealie_import_recipe',
    description: 'Import a Mealie recipe into EverShelf archive by slug or id.',
    inputSchema: {
      type: 'object',
      properties: {
        slug: { type: 'string', description: 'Mealie recipe slug' },
        id: { type: 'string', description: 'Mealie recipe id (alternative to slug)' },
      },
    },
  },
];

export function createEverShelfApi() {
  return new EverShelfApi({
    baseUrl: process.env.EVERSHELF_URL || 'http://localhost',
    apiToken: process.env.EVERSHELF_TOKEN || process.env.API_TOKEN || '',
  });
}

export async function handleTool(api, name, args) {
  switch (name) {
    case 'get_inventory': {
      const data = await api.get('inventory_list');
      let items = data.inventory || [];
      if (args?.location) {
        const loc = String(args.location).toLowerCase();
        items = items.filter((i) => String(i.location || '').toLowerCase() === loc);
      }
      return { inventory: items, count: items.length };
    }
    case 'get_expiring_soon': {
      const days = args?.days ?? 7;
      return api.get('ha_sensor', { sensor: 'expiring', expiry_days: days });
    }
    case 'get_shopping_list':
      return api.get('shopping_list');
    case 'get_smart_shopping': {
      const params = {};
      if (args?.plan_days != null) params.plan_days = args.plan_days;
      return api.get('smart_shopping', params);
    }
    case 'add_shopping_items':
      return api.post('shopping_add', { items: args?.items || [] });
    case 'use_inventory_item':
      return api.post('inventory_use', {
        product_id: args.product_id,
        quantity: args.quantity ?? 1,
        use_all: !!args.use_all,
        location: args.location || 'dispensa',
        notes: args.notes || '',
      });
    case 'suggest_recipe': {
      const params = {};
      if (args?.location) params.location = args.location;
      if (args?.meal) params.meal = args.meal;
      return api.get('ha_suggest_recipe', params);
    }
    case 'get_pantry_stats':
      return api.get('stats');
    case 'mealie_list_recipes':
      return api.get('mealie_list', {
        query: args?.query || '',
        limit: args?.limit ?? 20,
      });
    case 'mealie_import_recipe':
      return api.post('mealie_import', {
        slug: args?.slug || '',
        id: args?.id || '',
      });
    default:
      throw new Error(`Unknown tool: ${name}`);
  }
}

export function createMcpServer(api) {
  const server = new Server(
    { name: 'evershelf', version: '1.1.0' },
    { capabilities: { tools: {} } },
  );

  server.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: TOOLS }));

  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: args } = request.params;
    try {
      const result = await handleTool(api, name, args || {});
      return {
        content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
      };
    } catch (err) {
      return {
        content: [{ type: 'text', text: `Error: ${err.message}` }],
        isError: true,
      };
    }
  });

  return server;
}
