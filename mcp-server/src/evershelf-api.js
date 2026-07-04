/**
 * Thin HTTP client for the EverShelf REST API.
 */

export class EverShelfApi {
  /**
   * @param {{ baseUrl: string, apiToken?: string }} opts
   */
  constructor(opts) {
    this.baseUrl = (opts.baseUrl || 'http://localhost').replace(/\/+$/, '');
    this.apiToken = opts.apiToken || '';
  }

  /** @param {string} action */
  async get(action, params = {}) {
    const url = new URL(`${this.baseUrl}/api/index.php`);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null && v !== '') {
        url.searchParams.set(k, String(v));
      }
    }
    return this._fetch(url.toString(), { method: 'GET' });
  }

  /** @param {string} action @param {object} body */
  async post(action, body = {}) {
    const url = `${this.baseUrl}/api/index.php?action=${encodeURIComponent(action)}`;
    return this._fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  }

  async _fetch(url, init) {
    const headers = { ...(init.headers || {}) };
    if (this.apiToken) {
      headers['X-API-Token'] = this.apiToken;
    }
    const res = await fetch(url, { ...init, headers });
    const text = await res.text();
    let data;
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      throw new Error(`EverShelf API returned non-JSON (${res.status}): ${text.slice(0, 200)}`);
    }
    if (!res.ok && !data.success) {
      throw new Error(data.error || data.message || `HTTP ${res.status}`);
    }
    return data;
  }
}
