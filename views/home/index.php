<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VoidLux — games forged in the void</title>
<meta name="description" content="VoidLux: arcade games summoned from the void. Play what the void has made, or summon a game of your own.">
<style>
/* The front door: a hero with two doors (summon, play) and the shelf of public games, in the void's black and ember. */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: #060608; color: #e8e8e8; font-family: 'Courier New', monospace; min-height: 100vh; overflow-x: hidden; }
a { color: inherit; }
.header { background: linear-gradient(135deg, #0d0015, #060008); border-bottom: 1px solid rgba(204,102,0,0.3); padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 50; backdrop-filter: blur(10px); }
.header h1 { font-size: 1.3rem; letter-spacing: 3px; text-transform: uppercase; }
.header h1 a { text-decoration: none; background: linear-gradient(90deg, #ff6600, #ffcc00, #ff6600); background-size: 200% auto; -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: shimmer 3s linear infinite; }
@keyframes shimmer { to { background-position: 200% center; } }
.nav { display: flex; gap: 24px; align-items: center; }
.nav a { color: #b0b0b0; text-decoration: none; font-size: 0.75rem; letter-spacing: 2px; text-transform: uppercase; transition: color 0.3s; }
.nav a:hover { color: #e07700; }

.hero { text-align: center; padding: 100px 24px 80px; position: relative; overflow: hidden; min-height: 72vh; display: flex; flex-direction: column; justify-content: center; align-items: center; }
.hero-content { position: relative; z-index: 2; }
.hero-glyph { font-size: 3rem; color: rgba(204,102,0,0.15); margin-bottom: 20px; letter-spacing: 20px; animation: glyphpulse 6s ease-in-out infinite; }
@keyframes glyphpulse { 0%, 100% { opacity: 0.3; } 50% { opacity: 0.8; color: rgba(204,102,0,0.3); } }
.hero-title { font-size: 4rem; font-weight: bold; background: linear-gradient(90deg, #ff660088, #ffcc00, #ff660088); background-size: 300% auto; -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: shimmer 5s linear infinite; margin-bottom: 16px; letter-spacing: 6px; }
.hero-sub { font-size: 0.9rem; color: #bbb; letter-spacing: 4px; text-transform: uppercase; margin-bottom: 48px; }
.hero-actions { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; }
.btn { display: inline-block; padding: 16px 36px; border-radius: 2px; font-family: inherit; font-size: 0.85rem; letter-spacing: 3px; text-transform: uppercase; text-decoration: none; transition: all 0.3s; cursor: pointer; }
.btn-primary { background: rgba(204,102,0,0.15); color: #e07700; border: 1px solid rgba(224,119,0,0.4); }
.btn-primary:hover { background: rgba(224,119,0,0.25); border-color: #e07700; box-shadow: 0 0 30px rgba(204,102,0,0.15); }
.btn-ghost { background: transparent; color: #ccc; border: 1px solid #555; }
.btn-ghost:hover { border-color: #e07700; color: #e07700; }
.hero-whisper { font-size: 0.8rem; color: #888; letter-spacing: 2px; margin-top: 28px; }

.divider { height: 1px; background: linear-gradient(90deg, transparent, rgba(204,102,0,0.2), transparent); }

/* the shelf: covers like a store front, each a door into a game */
.main { max-width: 1100px; margin: 0 auto; padding: 56px 24px 72px; }
.shelf-head { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; padding-bottom: 10px; border-bottom: 1px solid #1a1a1a; }
.shelf-head h2 { font-size: 0.8rem; color: #e07700; letter-spacing: 4px; text-transform: uppercase; }
.shelf-head span { font-size: 0.7rem; color: #777; letter-spacing: 2px; }
.shelf { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px; }
.game { display: block; text-decoration: none; background: #0a0a0d; border: 1px solid #161616; border-radius: 4px; overflow: hidden; transition: border-color 0.3s, transform 0.3s, box-shadow 0.3s; }
.game:hover { border-color: rgba(224,119,0,0.5); transform: translateY(-3px); box-shadow: 0 12px 40px rgba(204,102,0,0.12); }
.cover { position: relative; aspect-ratio: 16 / 9; background: #000 radial-gradient(ellipse at center, rgba(204,102,0,0.12), transparent 70%); display: grid; place-items: center; overflow: hidden; }
.cover img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.5s; }
.game:hover .cover img { transform: scale(1.04); }
.cover .no { font-size: 0.6rem; color: #444; letter-spacing: 4px; text-transform: uppercase; }
.cover .play { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(6,6,8,0.0); transition: background 0.3s; }
.cover .play span { font-size: 0.7rem; letter-spacing: 4px; text-transform: uppercase; color: #ffcc00; opacity: 0; transition: opacity 0.3s; text-shadow: 0 0 16px rgba(255,204,0,0.6); }
.game:hover .cover .play { background: rgba(6,6,8,0.35); } .game:hover .cover .play span { opacity: 1; }
.meta { padding: 14px 16px 16px; display: grid; gap: 6px; }
.title { font-size: 0.95rem; color: #eee; letter-spacing: 1px; display: flex; justify-content: space-between; gap: 10px; align-items: baseline; }
.price { font-size: 0.75rem; color: #e07700; white-space: nowrap; }
.price.free { color: #7cc47c; }
.brief { font-size: 0.75rem; color: #999; line-height: 1.5; }
.tags { display: flex; gap: 6px; flex-wrap: wrap; }
.tag { font-size: 0.6rem; color: #888; border: 1px solid #222; border-radius: 2px; padding: 2px 6px; letter-spacing: 1px; text-transform: uppercase; }
.empty { color: #888; font-size: 0.85rem; line-height: 1.8; padding: 40px 0; text-align: center; }

.footer { border-top: 1px solid #111; padding: 36px 24px; text-align: center; color: #777; font-size: 0.7rem; letter-spacing: 2px; }
.footer a { color: #999; text-decoration: none; transition: color 0.3s; } .footer a:hover { color: #e07700; }
.footer .footer-links { margin-top: 12px; display: flex; justify-content: center; gap: 24px; flex-wrap: wrap; }
@media (max-width: 768px) { .hero-title { font-size: 2.5rem; letter-spacing: 3px; } .hero { padding: 70px 24px 56px; min-height: 60vh; } .nav { gap: 14px; } .nav a { font-size: 0.65rem; } .btn { padding: 14px 24px; font-size: 0.78rem; } .main { padding: 40px 16px 56px; } }
</style>
</head>
<body>

<div class="header">
    <h1><a href="/">VoidLux</a></h1>
    <div class="nav">
        <a href="#play">Play</a>
        <?php if (!empty($loggedIn)): ?><a href="/forge">The forge</a><?php else: ?><a href="/auth/login">Sign in</a><?php endif; ?>
    </div>
</div>

<div class="hero" id="hero">
    <div class="hero-content">
        <div class="hero-glyph">&#9673; &#9673; &#9673;</div>
        <div class="hero-title">VOIDLUX</div>
        <div class="hero-sub">games forged in the void</div>
        <div class="hero-actions">
            <a href="<?= !empty($loggedIn) ? '/forge/summon' : '/auth/register' ?>" class="btn btn-primary">Summon a game</a>
            <a href="#play" class="btn btn-ghost">Play a game</a>
        </div>
        <div class="hero-whisper">describe it · the void builds it · you play it</div>
    </div>
</div>

<div class="divider"></div>

<div class="main" id="play">
    <div class="shelf-head"><h2>Play what the void has made</h2><span><?= count($games) ?> game<?= count($games) === 1 ? '' : 's' ?> · free to play in the browser</span></div>
    <?php if (!empty($games)): ?>
    <div class="shelf">
        <?php foreach ($games as $g): ?>
        <a class="game" href="<?= h($g['url']) ?>" target="_blank" rel="noopener">
            <div class="cover">
                <?php if ($g['cover']): ?><img src="/home/cover/<?= h($g['slug']) ?>" alt="" loading="lazy"<?php if ($g['gif']): ?> data-anim="/home/cover/<?= h($g['slug']) ?>?anim=1"<?php endif; ?>><?php else: ?><span class="no">no cover yet</span><?php endif; ?>
                <div class="play"><span>&#9654; Play</span></div>
            </div>
            <div class="meta">
                <div class="title"><span><?= h($g['title']) ?></span><span class="price <?= $g['price'] === null || $g['price'] <= 0 ? 'free' : '' ?>"><?= $g['price'] === null || $g['price'] <= 0 ? 'free' : '$' . number_format((float) $g['price'], 2) ?></span></div>
                <?php if ($g['brief'] !== ''): ?><div class="brief"><?= h($g['brief']) ?></div><?php endif; ?>
                <div class="tags"><span class="tag" title="last updated">updated <?= h(date('M j', $g['updated'])) ?></span></div>
                <?php if ($g['tags']): ?><div class="tags"><?php foreach (array_slice($g['tags'], 0, 4) as $t): ?><span class="tag"><?= h($t) ?></span><?php endforeach; ?></div><?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty">The void is still forging. The first public games appear here.<br><a href="<?= !empty($loggedIn) ? '/forge/summon' : '/auth/register' ?>" class="btn btn-primary" style="margin-top:20px">Summon the first</a></div>
    <?php endif; ?>
</div>

<div class="footer">
    VOIDLUX &mdash; THE GAME FORGE
    <div class="footer-links">
        <a href="/forge">The forge</a>
        <a href="/terms">Terms</a>
        <a href="/privacy">Privacy</a>
    </div>
</div>

<script>
// the mesh of embers behind the hero
(function() {
    const hero = document.getElementById('hero'), canvas = document.createElement('canvas');
    canvas.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:1;';
    hero.insertBefore(canvas, hero.firstChild);
    const ctx = canvas.getContext('2d'), P = [], N = 70;
    function resize() { canvas.width = hero.offsetWidth; canvas.height = hero.offsetHeight; }
    resize(); window.addEventListener('resize', resize);
    for (let i = 0; i < N; i++) P.push({ x: Math.random() * canvas.width, y: Math.random() * canvas.height, vx: (Math.random() - 0.5) * 0.3, vy: (Math.random() - 0.5) * 0.3, r: Math.random() * 1.5 + 0.5, a: Math.random() * 0.4 + 0.1 });
    function draw() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        for (let i = 0; i < P.length; i++) for (let j = i + 1; j < P.length; j++) { const dx = P[i].x - P[j].x, dy = P[i].y - P[j].y, d = Math.hypot(dx, dy); if (d < 140) { ctx.beginPath(); ctx.moveTo(P[i].x, P[i].y); ctx.lineTo(P[j].x, P[j].y); ctx.strokeStyle = 'rgba(204,102,0,' + ((1 - d / 140) * 0.08) + ')'; ctx.lineWidth = 0.5; ctx.stroke(); } }
        for (const p of P) { ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2); ctx.fillStyle = 'rgba(204,102,0,' + p.a + ')'; ctx.fill(); p.x += p.vx; p.y += p.vy; if (p.x < 0 || p.x > canvas.width) p.vx *= -1; if (p.y < 0 || p.y > canvas.height) p.vy *= -1; }
        requestAnimationFrame(draw);
    }
    draw();
})();
// a cover with an animation plays its first seconds of gameplay while the pointer rests on it, and only then
for (const im of document.querySelectorAll('img[data-anim]')) { const still = im.src; const card = im.closest('.game'); card.addEventListener('mouseenter', () => { im.src = im.dataset.anim; }); card.addEventListener('mouseleave', () => { im.src = still; }); }
document.querySelectorAll('a[href^="#"]').forEach(a => a.addEventListener('click', e => { const t = document.querySelector(a.getAttribute('href')); if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }));
</script>
</body>
</html>
