export class VazinCmsClient {
  constructor(baseUrl, token) { this.baseUrl = baseUrl.replace(/\/$/, ''); this.token = token; }
  async get(path, query = {}) {
    const url = new URL(this.baseUrl + path);
    for (const [k,v] of Object.entries(query)) if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
    const res = await fetch(url, {headers:{Authorization:`Bearer ${this.token}`, Accept:'application/json'}});
    const body = await res.json().catch(() => null);
    if (!res.ok) throw new Error(body?.error?.message || `HTTP ${res.status}`);
    return body;
  }
  content(q={}) { return this.get('/api/v3/content', q); }
  forms(q={}) { return this.get('/api/v3/forms', q); }
  courses(q={}) { return this.get('/api/v3/learn/courses', q); }
  connectors() { return this.get('/api/v3/connectors'); }
}
