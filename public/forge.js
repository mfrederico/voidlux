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
  // a small markdown for the agent's words: headings, bold, italics, inline code, fenced code, lists, links, paragraphs
  md(text) {
    const esc = Forge.esc; const lines = String(text || '').replace(/\r/g, '').split('\n'); let out = '', i = 0, list = null, para = [];
    const inline = t => esc(t).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/(^|\W)\*([^*\n]+)\*(?=\W|$)/g, '$1<i>$2</i>').replace(/\[([^\]]+)\]\((https?:[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    const flush = () => { if (para.length) { out += '<p>' + inline(para.join(' ')) + '</p>'; para = []; } if (list) { out += '</' + list + '>'; list = null; } };
    while (i < lines.length) {
      const l = lines[i];
      if (/^```/.test(l)) { flush(); let code = []; i++; while (i < lines.length && !/^```/.test(lines[i])) code.push(lines[i++]); i++; out += '<pre><code>' + esc(code.join('\n')) + '</code></pre>'; continue; }
      const h = l.match(/^(#{1,4})\s+(.*)$/); if (h) { flush(); out += `<h${h[1].length + 2}>${inline(h[2])}</h${h[1].length + 2}>`; i++; continue; }
      const ul = l.match(/^\s*[-*]\s+(.*)$/), ol = l.match(/^\s*\d+[.)]\s+(.*)$/);
      if (ul || ol) { const kind = ul ? 'ul' : 'ol'; if (para.length) { out += '<p>' + inline(para.join(' ')) + '</p>'; para = []; } if (list && list !== kind) { out += '</' + list + '>'; list = null; } if (!list) { out += '<' + kind + '>'; list = kind; } const item = (ul || ol)[1].replace(/^\[( |x)\]\s*/i, (m, c) => c.toLowerCase() === 'x' ? '✓ ' : '○ '); out += '<li>' + inline(item) + '</li>'; i++; continue; }
      if (/^\s*$/.test(l)) { flush(); i++; continue; }
      if (list) { out += '</' + list + '>'; list = null; }
      para.push(l.trim()); i++;
    }
    flush(); return out;
  },
  slugFromTitle() {
    const t = document.querySelector('[name=title]'), s = document.querySelector('[name=slug]'); if (!t || !s) return;
    t.addEventListener('input', () => { if (!s.dataset.touched) s.value = Forge.slugify(t.value); });
    s.addEventListener('input', () => { s.dataset.touched = '1'; });
  },
};
