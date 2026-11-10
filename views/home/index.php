<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VoidLux — make a game, learn to work with AI</title>
<meta name="description" content="VoidLux: describe a game in plain words, watch an AI planner, builder, playtester and reviewer make it, then change anything by pointing at it. Learn to work with AI by shipping a game.">
<link rel="canonical" href="https://voidlux.com/">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="512x512" href="/icon-512.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#060608">
<meta property="og:type" content="website">
<meta property="og:site_name" content="VoidLux">
<meta property="og:title" content="VoidLux — make a game, learn to work with AI">
<meta property="og:description" content="Write a brief and VoidLux forges the game: an AI planner designs it, a builder builds it, a playtester plays it, a reviewer judges it, and it ships to its own URL. Then change anything by pointing at it.">
<meta property="og:url" content="https://voidlux.com/">
<meta property="og:image" content="https://voidlux.com/og-voidlux.png?v=2">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="The VoidLux VL monogram with the words: Write a brief. Ship a game.">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="VoidLux — make a game, learn to work with AI">
<meta name="twitter:description" content="Describe a game in plain words. Watch an AI planner, builder, playtester and reviewer make it. Change anything by pointing at it.">
<meta name="twitter:image" content="https://voidlux.com/og-voidlux.png?v=2">
<style>
/* The front door, rebuilt around the mission: make a game, and learn to work with AI by making it.
   Sections: hero (the promise, two doors) · the loop (say it, see what it takes, watch it build, play it) ·
   what you learn · what it costs (real figures) · the shelf (games made here) · footer. The void's black and ember. */
* { margin: 0; padding: 0; box-sizing: border-box; }
:root { --ember: #e07700; --ember-hi: #ffcc00; --ink: #e8e8e8; --dim: #9a9a9a; --faint: #666; --line: #1a1a1a; --panel: #0a0a0d; }
html { scroll-behavior: smooth; }
body { background: #060608; color: var(--ink); font-family: 'Courier New', monospace; min-height: 100vh; overflow-x: hidden; line-height: 1.5; }
a { color: inherit; }
.header { background: linear-gradient(135deg, #0d0015, #060008); border-bottom: 1px solid rgba(204,102,0,0.3); padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 50; backdrop-filter: blur(10px); }
.header h1 { font-size: 1.3rem; letter-spacing: 3px; text-transform: uppercase; }
.header h1 a { text-decoration: none; display: inline-flex; align-items: center; gap: 10px; background: linear-gradient(90deg, #ff6600, #ffcc00, #ff6600); background-size: 200% auto; -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: shimmer 3s linear infinite; }
.header h1 .mark { width: 34px; height: 34px; display: block; filter: drop-shadow(0 0 6px rgba(204,102,0,0.35)); }
@keyframes shimmer { to { background-position: 200% center; } }
.nav { display: flex; gap: 24px; align-items: center; }
.nav a { color: #b0b0b0; text-decoration: none; font-size: 0.75rem; letter-spacing: 2px; text-transform: uppercase; transition: color 0.3s; }
.nav a:hover { color: var(--ember); }

.hero { text-align: center; padding: 96px 24px 72px; position: relative; overflow: hidden; min-height: 78vh; display: flex; flex-direction: column; justify-content: center; align-items: center; }
/* a slow ember glow under the title, beneath the mesh canvas (z 1) and the text (z 2) */
.hero-glow { position: absolute; z-index: 0; pointer-events: none; left: 50%; top: 50%; width: min(960px, 120vw); height: 520px; transform: translate(-50%, -56%); background: radial-gradient(ellipse at center, rgba(224,119,0,0.14), rgba(224,119,0,0.05) 42%, rgba(224,119,0,0) 70%); }
.hero-content { position: relative; z-index: 2; max-width: 1080px; }
.eyebrow { font-size: 0.74rem; color: var(--ember); opacity: 0.85; letter-spacing: 4px; text-transform: uppercase; margin-bottom: 26px; }
.hero-title { font-size: clamp(2.1rem, 4.4vw, 3rem); font-weight: bold; line-height: 1.12; letter-spacing: 0.5px; margin-bottom: 26px; text-wrap: balance; }
.hero-title .rise { display: inline-block; }
.hero-title em { font-style: normal; background: linear-gradient(90deg, #ff660088, #ffcc00, #ff660088); background-size: 300% auto; -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: shimmer 5s linear infinite; }
.hero-sub { font-size: 1.05rem; line-height: 1.6; color: #bdbdbd; max-width: 760px; margin: 0 auto 30px; text-wrap: balance; }
.hero-actions { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; }
.btn { display: inline-block; padding: 16px 36px; border-radius: 2px; font-family: inherit; font-size: 0.85rem; letter-spacing: 3px; text-transform: uppercase; text-decoration: none; transition: all 0.3s; cursor: pointer; }
.btn-primary { background: rgba(204,102,0,0.15); color: var(--ember); border: 1px solid rgba(224,119,0,0.4); }
.btn-primary:hover { background: rgba(224,119,0,0.25); border-color: var(--ember); box-shadow: 0 0 30px rgba(204,102,0,0.15); }
.btn-ghost { background: transparent; color: #ccc; border: 1px solid #555; }
.btn-ghost:hover { border-color: var(--ember); color: var(--ember); }
/* the hero's two doors: the primary is solid ember, the secondary a quiet outline */
.hero-actions .btn-primary { background: var(--ember); color: #0a0500; font-weight: bold; border-color: #ffb03a; box-shadow: 0 0 28px rgba(224,119,0,0.22); }
.hero-actions .btn-primary:hover { background: var(--ember-hi); border-color: var(--ember-hi); color: #000; box-shadow: 0 0 40px rgba(255,204,0,0.3); }
.hero-actions .btn-ghost { color: #bdbdbd; border-color: #4a4a4a; }
.hero-whisper { font-size: 0.78rem; color: #8f8f8f; letter-spacing: 2px; margin-top: 32px; }
.hero-whisper b { color: var(--ember); font-weight: normal; }
/* the arrival: title lines rise in with a short stagger, then the rest of the hero follows; the glow drifts */
@media (prefers-reduced-motion: no-preference) {
    @keyframes rise { from { opacity: 0; transform: translateY(0.55em); } to { opacity: 1; transform: none; } }
    @keyframes glow-drift { from { transform: translate(-53%, -58%) scale(0.96); opacity: 0.75; } to { transform: translate(-47%, -53%) scale(1.06); opacity: 1; } }
    .hero-title .rise { opacity: 0; animation: rise 0.9s cubic-bezier(0.2, 0.7, 0.2, 1) forwards; }
    .hero-title .rise:nth-of-type(2) { animation-delay: 0.2s; }
    .eyebrow, .hero-sub, .hero-actions, .ask, .chips, .hero-or, .hero-whisper { opacity: 0; animation: rise 0.8s cubic-bezier(0.2, 0.7, 0.2, 1) forwards; }
    .eyebrow { animation-delay: 0.05s; } .hero-sub { animation-delay: 0.5s; } .hero-actions, .ask { animation-delay: 0.65s; } .chips, .hero-or { animation-delay: 0.75s; } .hero-whisper { animation-delay: 0.85s; }
    .hero-glow { animation: glow-drift 16s ease-in-out infinite alternate; }
}

/* the gameplay loop behind the hero: muted, looping, darkened so the words and the box read over it */
.hero-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0; opacity: 0.85; }
.hero-shade { position: absolute; inset: 0; z-index: 1; pointer-events: none; background: radial-gradient(ellipse 55% 60% at center, rgba(6,6,8,0.62), rgba(6,6,8,0.25) 100%), linear-gradient(to bottom, rgba(6,6,8,0.35), rgba(6,6,8,0) 25%, rgba(6,6,8,0) 75%, rgba(6,6,8,0.95)); }
/* the prompt box: say the game here and the forge starts from it */
.ask { width: min(720px, 100%); margin: 0 auto; background: rgba(10,10,13,0.86); border: 1px solid rgba(224,119,0,0.45); border-radius: 10px; padding: 14px; text-align: left; box-shadow: 0 0 40px rgba(224,119,0,0.12); backdrop-filter: blur(6px); }
.ask label.say { display: block; font-size: 0.72rem; color: var(--ember); letter-spacing: 3px; text-transform: uppercase; margin: 2px 4px 8px; }
.ask textarea { width: 100%; min-height: 84px; resize: vertical; background: transparent; border: 0; outline: 0; color: #fff; font: inherit; font-size: 1.05rem; line-height: 1.5; padding: 4px; }
.ask textarea::placeholder { color: #777; }
.ask .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 8px; }
.ask .kind { display: inline-flex; border: 1px solid #333; border-radius: 999px; overflow: hidden; }
.ask .kind input { position: absolute; opacity: 0; pointer-events: none; }
.ask .kind label { padding: 9px 16px; font-size: 0.8rem; letter-spacing: 2px; color: #aaa; cursor: pointer; min-height: 40px; display: inline-flex; align-items: center; }
.ask .kind input:checked + label { background: var(--ember); color: #0a0500; font-weight: bold; }
.ask .kind input:focus-visible + label { outline: 2px solid var(--ember-hi); outline-offset: -2px; }
.ask .count { margin-left: auto; font-size: 0.7rem; color: #777; }
.ask button { font-family: inherit; font-size: 0.85rem; letter-spacing: 3px; text-transform: uppercase; background: var(--ember); color: #0a0500; font-weight: bold; border: 1px solid #ffb03a; border-radius: 6px; padding: 12px 22px; cursor: pointer; min-height: 44px; transition: all 0.3s; }
.ask button:hover { background: var(--ember-hi); box-shadow: 0 0 30px rgba(255,204,0,0.3); }
.chips { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin: 14px auto 0; max-width: 960px; }
.chips button { font-family: inherit; font-size: 0.75rem; color: #cfcfcf; background: rgba(10,10,13,0.7); border: 1px solid #333; border-radius: 999px; padding: 8px 14px; cursor: pointer; min-height: 36px; transition: all 0.2s; }
.chips button:hover { border-color: var(--ember); color: var(--ember-hi); }
.hero-or { margin-top: 18px; font-size: 0.8rem; } .hero-or a { color: #bdbdbd; letter-spacing: 2px; } .hero-or a:hover { color: var(--ember); }

/* watch it build: a real game's whole history, every builder run wired into the parts it changed (the Studio's wires page, filmed) */
.watch { position: relative; border: 1px solid #1d1d24; border-radius: 8px; overflow: hidden; background: #07060c; box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 40px rgba(197,138,255,0.08); }
.watch video { display: block; width: 100%; height: auto; aspect-ratio: 16 / 9; background: #07060c; }
.watch-notes { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin-top: 22px; }
.watch-notes div { font-size: 0.85rem; color: #adadad; line-height: 1.6; } .watch-notes b { display: block; color: #f2f2f2; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 6px; }
.watch-notes i { font-style: normal; color: #7de0ff; } .watch-notes em { font-style: normal; color: #ff6bd5; }

.divider { height: 1px; background: linear-gradient(90deg, transparent, rgba(204,102,0,0.2), transparent); }
.section { max-width: 1200px; margin: 0 auto; padding: 72px 24px; }
.section-head { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; flex-wrap: wrap; margin-bottom: 28px; padding-bottom: 10px; border-bottom: 1px solid var(--line); }
.section-head h2 { font-size: 0.8rem; color: var(--ember); letter-spacing: 4px; text-transform: uppercase; }
.section-head span { font-size: 0.7rem; color: #777; letter-spacing: 2px; }
.lead { font-size: 1.05rem; color: #cfcfcf; max-width: 720px; margin-bottom: 36px; }

/* the loop: four steps, numbered, in the product's own words */
.steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 18px; counter-reset: step; }
.step { background: var(--panel); border: 1px solid #161616; border-radius: 4px; padding: 26px 24px 28px; position: relative; transition: border-color 0.3s, transform 0.3s; }
.step:hover { border-color: rgba(224,119,0,0.5); transform: translateY(-3px); }
.step::before { counter-increment: step; content: counter(step, decimal-leading-zero); font-size: 0.95rem; font-weight: bold; color: var(--ember); letter-spacing: 3px; display: block; margin-bottom: 14px; }
.step h3 { font-size: 1.05rem; color: #f2f2f2; margin-bottom: 12px; letter-spacing: 1px; line-height: 1.25; }
.step p { font-size: 0.88rem; color: #adadad; line-height: 1.65; }
.step q { color: var(--ember-hi); quotes: '“' '”'; }

/* what you learn: three lessons the loop teaches, each named by the skill */
.lessons { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 28px; }
.lesson { border-left: 2px solid rgba(224,119,0,0.6); padding: 6px 0 6px 22px; }
.lesson h3 { font-size: 1.2rem; color: #f2f2f2; margin-bottom: 10px; letter-spacing: 1px; }
.lesson p { font-size: 0.9rem; color: #adadad; line-height: 1.65; }

/* what it costs: the same figures the Studio quotes, nothing invented */
.costs { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; }
.cost { background: var(--panel); border: 1px solid #161616; border-radius: 4px; padding: 26px 24px 28px; display: flex; flex-direction: column; }
.cost .what { font-size: 0.7rem; color: var(--ember); letter-spacing: 2px; text-transform: uppercase; margin-bottom: 14px; min-height: 2.4em; display: flex; align-items: flex-end; }
.cost .figure { font-size: 2.6rem; color: #fff; letter-spacing: 1px; line-height: 1; margin-bottom: 14px; }
.cost .figure .ca { font-size: 0.95rem; color: var(--dim); letter-spacing: 2px; margin-right: 8px; }
.cost .figure.free { color: #7cc47c; }
.cost .note { font-size: 0.85rem; color: #8c8c8c; line-height: 1.6; }
.costs-foot { font-size: 0.85rem; color: #8c8c8c; margin-top: 22px; max-width: 720px; line-height: 1.6; }

/* the shelf: covers like a store front, each a door into a game */
.shelf { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px; }
.game { display: block; text-decoration: none; background: var(--panel); border: 1px solid #161616; border-radius: 4px; overflow: hidden; transition: border-color 0.3s, transform 0.3s, box-shadow 0.3s; }
.game:hover { border-color: rgba(224,119,0,0.5); transform: translateY(-3px); box-shadow: 0 12px 40px rgba(204,102,0,0.12); }
.cover { position: relative; aspect-ratio: 16 / 9; background: #000 radial-gradient(ellipse at center, rgba(204,102,0,0.12), transparent 70%); display: grid; place-items: center; overflow: hidden; }
.cover img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.5s; }
.game:hover .cover img { transform: scale(1.04); }
.cover .no { font-size: 0.6rem; color: #444; letter-spacing: 4px; text-transform: uppercase; }
.cover .play { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(6,6,8,0.0); transition: background 0.3s; }
.cover .play span { font-size: 0.7rem; letter-spacing: 4px; text-transform: uppercase; color: var(--ember-hi); opacity: 0; transition: opacity 0.3s; text-shadow: 0 0 16px rgba(255,204,0,0.6); }
.game:hover .cover .play { background: rgba(6,6,8,0.35); } .game:hover .cover .play span { opacity: 1; }
.meta { padding: 14px 16px 16px; display: grid; gap: 6px; }
.title { font-size: 0.95rem; color: #eee; letter-spacing: 1px; display: flex; justify-content: space-between; gap: 10px; align-items: baseline; }
.price { font-size: 0.75rem; color: var(--ember); white-space: nowrap; }
.price.free { color: #7cc47c; }
.brief { font-size: 0.75rem; color: #999; line-height: 1.5; }
.tags { display: flex; gap: 6px; flex-wrap: wrap; }
.tag { font-size: 0.6rem; color: #888; border: 1px solid #222; border-radius: 2px; padding: 2px 6px; letter-spacing: 1px; text-transform: uppercase; }
.empty { color: #888; font-size: 0.85rem; line-height: 1.8; padding: 40px 0; text-align: center; }

.cta { text-align: center; padding: 72px 24px 80px; }
.cta h2 { font-size: 1.6rem; margin-bottom: 14px; letter-spacing: 1px; }
.cta p { color: var(--dim); font-size: 0.85rem; margin-bottom: 28px; }

/* sections drift in as they scroll into view; only once the script has said it can reveal them, so nothing is ever left hidden */
.reveal .rv { opacity: 0; transform: translateY(18px); transition: opacity 0.7s ease, transform 0.7s ease; }
.reveal .rv.in { opacity: 1; transform: none; }
@media (prefers-reduced-motion: reduce) { .reveal .rv { opacity: 1; transform: none; transition: none; } }

.footer { border-top: 1px solid #111; padding: 36px 24px; text-align: center; color: #777; font-size: 0.7rem; letter-spacing: 2px; }
.footer a { color: #999; text-decoration: none; transition: color 0.3s; } .footer a:hover { color: var(--ember); }
.footer .footer-links { margin-top: 12px; display: flex; justify-content: center; gap: 24px; flex-wrap: wrap; }
/* phones (most visitors): the header fits on one line, the hero's doors stack full width, every grid is one column */
@media (max-width: 768px) {
    .header { padding: 12px 16px; gap: 10px; }
    .header h1 { font-size: 1rem; letter-spacing: 2px; } .header h1 a { gap: 8px; } .header h1 .mark { width: 26px; height: 26px; }
    .nav { gap: 12px; } .nav a { font-size: 0.6rem; letter-spacing: 1px; white-space: nowrap; }
    .hero { padding: 56px 20px 48px; min-height: 0; } .hero-content { width: 100%; }
    .eyebrow { font-size: 0.62rem; letter-spacing: 3px; margin-bottom: 18px; text-wrap: balance; }
    .hero-title { font-size: 2rem; letter-spacing: 0; line-height: 1.15; margin-bottom: 18px; }
    .hero-sub { font-size: 0.9rem; margin-bottom: 30px; }
    .hero-actions { flex-direction: column; align-items: stretch; gap: 12px; max-width: 360px; margin: 0 auto; }
    .hero-actions .btn { width: 100%; text-align: center; }
    .ask { padding: 10px; } .ask textarea { font-size: 1rem; min-height: 96px; } .ask .row { gap: 8px; } .ask button { width: 100%; } .ask .count { margin-left: 0; }
    .hero-whisper { font-size: 0.7rem; letter-spacing: 1px; line-height: 2; margin-top: 24px; text-wrap: balance; }
    .btn { padding: 14px 24px; font-size: 0.78rem; letter-spacing: 2px; }
    .section { padding: 48px 16px; } .section-head { margin-bottom: 22px; } .lead { font-size: 0.95rem; margin-bottom: 28px; }
    .steps, .lessons, .costs, .shelf { grid-template-columns: 1fr; gap: 14px; }
    .step { padding: 18px 16px 20px; } .lesson { padding-left: 14px; }
    .cost { padding: 18px 16px; } .cost .figure { font-size: 1.6rem; overflow-wrap: anywhere; }
    .cta { padding: 56px 20px 64px; } .cta h2 { font-size: 1.3rem; text-wrap: balance; } .cta p { text-wrap: balance; }
    .footer { padding: 28px 16px; } .footer .footer-links { gap: 16px; }
}
</style>
</head>
<body>

<div class="header">
    <h1><a href="/"><img class="mark" src="/vl-mark.svg" alt="" width="34" height="34">VoidLux</a></h1>
    <div class="nav">
        <a href="#how">How it works</a>
        <a href="#play">Play</a>
        <?php if (!empty($loggedIn)): ?><a href="/forge">The forge</a><?php else: ?><a href="/auth/login">Sign in</a><?php endif; ?>
    </div>
</div>

<div class="hero" id="hero"<?= !empty($heroVideo) ? ' data-video="1"' : '' ?>>
    <?php if (!empty($heroVideo)): ?><video class="hero-video" id="heroVideo" autoplay muted loop playsinline preload="auto" poster="/assets/hero/hero-poster.jpg?v=<?= (int) $heroVideo ?>" aria-hidden="true"><source media="(max-width: 900px)" src="/assets/hero/hero-loop-small.mp4?v=<?= (int) $heroVideo ?>" type="video/mp4"><source src="/assets/hero/hero-loop.mp4?v=<?= (int) $heroVideo ?>" type="video/mp4"></video><div class="hero-shade"></div><?php endif; ?>
    <div class="hero-glow"></div>
    <div class="hero-content">
        <div class="eyebrow">a game forge for people learning to work with AI</div>
        <h2 class="hero-title"><span class="rise">Make a game.</span><br><span class="rise">Learn to work with <em>AI</em> by making it.</span></h2>
        <p class="hero-sub">Say it in plain words. AI plans it, builds it and playtests it. Then change anything by pointing at it.</p>
        <form class="ask" id="ask" action="/forge/summon" method="get">
            <label class="say" for="idea">Describe your game</label>
            <textarea id="idea" name="brief" maxlength="600" required placeholder="A ninja sneaks through a fortress at night, past sleeping guards, to reach the gate before sunrise."></textarea>
            <div class="row">
                <span class="kind" role="radiogroup" aria-label="2D or 3D"><input type="radio" name="kind" id="k2" value="2d"><label for="k2">2D</label><input type="radio" name="kind" id="k3" value="3d" checked><label for="k3">3D</label></span>
                <span class="count" id="ideaCount">0 / 600</span>
                <button type="submit">Make my game</button>
            </div>
        </form>
        <div class="chips" aria-label="Ideas to start from">
            <button type="button" data-idea="A ninja sneaks past sleeping guards to reach the gate before sunrise.">Ninja sneaking past guards</button>
            <button type="button" data-idea="A glowing centipede winds down through a neon mushroom garden while you blast it apart.">Neon centipede shooter</button>
            <button type="button" data-idea="A cozy fishing village where you catch fish, sell them at the market and fix up your boat.">Cozy fishing village</button>
            <button type="button" data-idea="A little robot races through space, dodging asteroids and collecting stars.">Robot racing through space</button>
        </div>
        <div class="hero-or"><a href="#play">or play one first &darr;</a><?php if (empty($loggedIn)): ?> &middot; <span style="color:#8f8f8f">free to start, nothing is spent until you say build</span><?php endif; ?></div>
    </div>
</div>

<div class="divider"></div>

<?php if (!empty($watchVideo)): ?>
<div class="section rv" id="watch">
    <div class="section-head"><h2>Watch it build itself</h2><span>a real game's whole history, sped up</span></div>
    <p class="lead">This is Ninja Mirage being made, from an empty template to a 3D stealth game. Each dot on the left is one time the AI builder worked on it. The lines run into the parts of the game it looked at and changed.</p>
    <div class="watch"><video id="watchVideo" muted loop playsinline preload="metadata" poster="/assets/home/watch-build.jpg?v=<?= (int) $watchVideo ?>" aria-label="Every run of the AI builder on Ninja Mirage, wired into the parts of the game it changed"><source src="/assets/home/watch-build.mp4?v=<?= (int) $watchVideo ?>" type="video/mp4"></video></div>
    <div class="watch-notes">
        <div><b>The runs</b>Each one starts from something a person asked for: "put a few rabbits in the game", "let me jump on boxes".</div>
        <div><b>The light</b><i>Cyan</i> is the builder reading a part; a part's own colour, like <em>pink</em> for the design, is the builder changing it.</div>
        <div><b>Your game too</b>Every game made here has this page in its Studio, live while the builder works.</div>
    </div>
</div>

<div class="divider"></div>
<?php endif; ?>

<div class="section rv" id="how">
    <div class="section-head"><h2>How it works</h2><span>the loop, in the forge's own words</span></div>
    <p class="lead">You never write code unless you want to. You write briefs, read plans and playtests, and decide. That is the work of working with AI, and a game is the most honest way to learn it: it either plays or it does not.</p>
    <div class="steps">
        <div class="step"><h3>Say what the game is</h3><p>Who is the player, what do they do every ten seconds, what makes a run end, what is the hook. A brief is the first skill, and the form shows you what a good one contains.</p></div>
        <div class="step"><h3>See what it takes</h3><p>Point at anything in your game and say what you want different. The Studio tells you whether that is a number, a rule, a look, or a job for the builder, and what it costs, <q>before anything runs</q>.</p></div>
        <div class="step"><h3>Watch it build</h3><p>The planner writes tasks. The builder builds each one, a playtest plays it, an independent reviewer judges it. Every run is a checkpoint you can read, keep, or undo.</p></div>
        <div class="step"><h3>Play it</h3><p>Your game ships to its own address, free to play in any browser, phone or desk. Tune its sound in the editors, then show it to someone.</p></div>
    </div>
</div>

<div class="divider"></div>

<div class="section rv" id="learn">
    <div class="section-head"><h2>What you learn</h2><span>skills that outlast the game</span></div>
    <div class="lessons">
        <div class="lesson"><h3>Briefing</h3><p>Saying what you want so a planner can design it: the player, the loop, the end of a run, the hook. Vague briefs make vague games, and you will see it on screen in minutes.</p></div>
        <div class="lesson"><h3>Scoping</h3><p>Knowing when a wish is a number to tune, a rule to write, a look to draw, or real work for a builder. The Studio sorts every request this way and shows its reasoning.</p></div>
        <div class="lesson"><h3>Judging</h3><p>Reading a playtest, a review and a cost, then keeping or undoing a change. The forge grades itself every hour and shows the numbers: what it spent, what it kept.</p></div>
    </div>
</div>

<div class="divider"></div>

<div class="section rv" id="cost">
    <div class="section-head"><h2>What it costs</h2><span>real figures from runs here, updated daily</span></div>
    <div class="costs">
        <div class="cost"><div class="what">Playing, inspecting, notes</div><div class="figure free">free</div><div class="note">Play any game. Inspect anything in yours. Save notes and screenshots. Nothing runs until you choose.</div></div>
        <?php if (!empty($costs['change'])): ?>
        <div class="cost"><div class="what">One change by the builder</div><div class="figure"><span class="ca">about</span>$<?= h(number_format((float) $costs['change']['usual'], 2)) ?></div><div class="note">usually; up to $<?= h(number_format((float) $costs['change']['high'], 2)) ?>, from the last <?= (int) $costs['change']['n'] ?> changes made here</div></div>
        <?php endif; ?>
        <?php if (!empty($costs['game'])): ?>
        <div class="cost"><div class="what">A whole game, planned and built</div><div class="figure"><span class="ca">about</span>$<?= h(number_format((float) $costs['game']['usual'], 0)) ?></div><div class="note">usually; up to $<?= h(number_format((float) $costs['game']['high'], 0)) ?>, from the <?= (int) $costs['game']['n'] ?> games built here so far. You can stop it at any time; what it built stays.</div></div>
        <?php endif; ?>
    </div>
    <p class="costs-foot">Every price you see in the forge comes from what earlier runs here actually cost, never from a guess. A job it cannot estimate says so.</p>
</div>

<div class="divider"></div>

<div class="section rv" id="play">
    <div class="section-head"><h2>Made here</h2><span><?= count($games) ?> game<?= count($games) === 1 ? '' : 's' ?> · free to play in the browser</span></div>
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
                <div class="tags"><span class="tag" title="last updated">updated <?= h(date('M j', $g['updated'])) ?></span><?php if ($g['tags']): ?><?php foreach (array_slice($g['tags'], 0, 3) as $t): ?><span class="tag"><?= h($t) ?></span><?php endforeach; ?><?php endif; ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty">The void is still forging. The first public games appear here.<br><a href="<?= !empty($loggedIn) ? '/forge/summon' : '/auth/register' ?>" class="btn btn-primary" style="margin-top:20px">Summon the first</a></div>
    <?php endif; ?>
</div>

<div class="divider"></div>

<div class="cta rv">
    <h2>Your first game is one brief away.</h2>
    <p>Sign up, describe it, and decide what it takes. Nothing is spent until you say build.</p>
    <a href="<?= !empty($loggedIn) ? '/forge/summon' : '/auth/register' ?>" class="btn btn-primary">Summon a game</a>
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
// the prompt box: a chip fills it, the count follows it, and the idea waits in this browser in case signing up comes first
(function() {
    const f = document.getElementById('ask'), t = document.getElementById('idea'), n = document.getElementById('ideaCount'); if (!f) return;
    const count = () => { n.textContent = t.value.length + ' / 600'; }; t.addEventListener('input', count); count();
    for (const b of document.querySelectorAll('.chips button')) b.addEventListener('click', () => { t.value = b.dataset.idea; count(); t.focus(); });
    f.addEventListener('submit', () => { try { localStorage.setItem('vl.idea', t.value.trim()); } catch (e) {} });
})();
// watch it build: plays while it is on screen, from the start the first time; for people who asked for less motion it waits with controls
(function() {
    const v = document.getElementById('watchVideo'); if (!v) return;
    if (matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) { v.controls = true; return; }
    new IntersectionObserver(es => { for (const e of es) { if (e.isIntersecting) v.play().catch(() => { v.controls = true; }); else v.pause(); } }, { threshold: 0.35 }).observe(v);
})();
// the gameplay loop: still (its poster) for people who asked for less motion
(function() { const v = document.getElementById('heroVideo'); if (v && matchMedia('(prefers-reduced-motion: reduce)').matches) { v.removeAttribute('autoplay'); v.pause(); } })();
// the mesh of embers behind the hero, when there is no gameplay behind it
(function() {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches || document.getElementById('hero').dataset.video) return;
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
// sections drift in as they scroll into view; people who asked for less motion, and browsers without the observer, see them at once
(function() {
    const els = document.querySelectorAll('.rv');
    if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    document.documentElement.classList.add('reveal');
    const io = new IntersectionObserver((entries) => { for (const en of entries) if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
    for (const e of els) io.observe(e);
})();
</script>
</body>
</html>
