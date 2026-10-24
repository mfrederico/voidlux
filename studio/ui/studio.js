// Shared bits for the studio pages: the API helper, escaping, slugs, the ember particle mesh behind the hero.
const Studio = {
  async api(path, body, method) {
    const r = await fetch(path, body === undefined ? { cache: 'no-store' } : { method: method || 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    let j = null; try { j = await r.json(); } catch (e) { j = { error: 'the studio answered ' + r.status }; }
    if (!r.ok) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
  },
  esc: s => String(s == null ? '' : s).replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch])),
  slugify: s => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/^[^a-z]+/, '').slice(0, 40),
  time: t => { const d = new Date(t * 1000); return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); },
  ago: iso => { if (!iso) return ''; const m = Math.round((Date.now() - new Date(iso)) / 60000); return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.round(m / 60) + ' h ago' : Math.round(m / 1440) + ' d ago'; },
  // the slow ember mesh from the VoidLux landing page: drifting points joined when close
  particles(host) {
    if (!host) return;
    const canvas = document.createElement('canvas'); host.insertBefore(canvas, host.firstChild);
    const ctx = canvas.getContext('2d'); let P = [];
    const resize = () => { canvas.width = host.offsetWidth; canvas.height = host.offsetHeight; }; resize(); window.addEventListener('resize', resize);
    for (let i = 0; i < 70; i++) P.push({ x: Math.random() * canvas.width, y: Math.random() * canvas.height, vx: (Math.random() - 0.5) * 0.3, vy: (Math.random() - 0.5) * 0.3, r: Math.random() * 1.5 + 0.5, a: Math.random() * 0.5 + 0.2 });
    const draw = () => {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      for (const p of P) { p.x += p.vx; p.y += p.vy; if (p.x < 0 || p.x > canvas.width) p.vx *= -1; if (p.y < 0 || p.y > canvas.height) p.vy *= -1; ctx.fillStyle = `rgba(255,120,20,${p.a})`; ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2); ctx.fill(); }
      for (let i = 0; i < P.length; i++) for (let j = i + 1; j < P.length; j++) { const d = Math.hypot(P[i].x - P[j].x, P[i].y - P[j].y); if (d < 110) { ctx.strokeStyle = `rgba(255,102,0,${(1 - d / 110) * 0.18})`; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(P[i].x, P[i].y); ctx.lineTo(P[j].x, P[j].y); ctx.stroke(); } }
      requestAnimationFrame(draw);
    };
    draw();
  },
};
