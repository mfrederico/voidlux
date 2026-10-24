// The forge's page script: JSON calls with the CSRF token, escaping, slugs, and the game page's polling and tabs.
const Forge = {
  token: (document.querySelector('meta[name="csrf-token"]') || {}).content || (window.FORGE_CSRF || ''),
  async api(path, body, method) {
    const opts = body === undefined ? { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } }
      : { method: method || 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) };
    const r = await fetch(path, opts); let j = null;
    try { j = await r.json(); } catch (e) { j = { error: 'the forge answered ' + r.status }; }
    if (!r.ok || (j && j.success === false)) throw new Error((j && (j.error || j.message)) || ('HTTP ' + r.status));
    return j && j.data !== undefined ? j.data : j;
  },
  esc: s => String(s == null ? '' : s).replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch])),
  slugify: s => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/^[^a-z]+/, '').slice(0, 40),
  time: t => new Date(t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
  slugFromTitle() {
    const t = document.querySelector('[name=title]'), s = document.querySelector('[name=slug]'); if (!t || !s) return;
    t.addEventListener('input', () => { if (!s.dataset.touched) s.value = Forge.slugify(t.value); });
    s.addEventListener('input', () => { s.dataset.touched = '1'; });
  },
};
