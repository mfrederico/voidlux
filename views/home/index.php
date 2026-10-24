<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VoidLux — build the arcade games of your dreams</title>
<meta name="description" content="VoidLux: describe the arcade game you have always wanted, and a forge of AI builders plans it, builds it, playtests it and ships it to its own URL. You play, point at things, and say what should change.">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    background: #060608;
    color: #e8e8e8;
    font-family: 'Courier New', monospace;
    min-height: 100vh;
    overflow-x: hidden;
}

/* ── Header ── */
.header {
    background: linear-gradient(135deg, #0d0015, #060008);
    border-bottom: 1px solid rgba(204,102,0,0.3);
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 50;
    backdrop-filter: blur(10px);
}
.header h1 {
    font-size: 1.3rem;
    background: linear-gradient(90deg, #ff6600, #ffcc00, #ff6600);
    background-size: 200% auto;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: shimmer 3s linear infinite;
    letter-spacing: 3px;
    text-transform: uppercase;
}
.header h1 a {
    text-decoration: none;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background: inherit;
    background-size: inherit;
    animation: inherit;
}
@keyframes shimmer { to { background-position: 200% center; } }
.nav { display: flex; gap: 24px; align-items: center; }
.nav a {
    color: #b0b0b0; text-decoration: none; font-size: 0.75rem;
    letter-spacing: 2px; text-transform: uppercase;
    transition: color 0.3s;
}
.nav a:hover { color: #e07700; }
.nav .gh-link {
    display: flex; align-items: center; gap: 6px;
    color: #c0c0c0; font-size: 0.75rem;
}
.nav .gh-link:hover { color: #e07700; }
.nav .gh-link svg { width: 16px; height: 16px; fill: currentColor; }

/* ── Hero ── */
.hero {
    text-align: center;
    padding: 120px 24px 100px;
    position: relative;
    overflow: hidden;
    min-height: 90vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}
.hero canvas {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    pointer-events: none;
}
.hero-content { position: relative; z-index: 2; }
.hero-glyph {
    font-size: 3rem;
    color: rgba(204,102,0,0.15);
    margin-bottom: 20px;
    letter-spacing: 20px;
    animation: glyphpulse 6s ease-in-out infinite;
}
@keyframes glyphpulse {
    0%, 100% { opacity: 0.3; }
    50% { opacity: 0.8; color: rgba(204,102,0,0.3); }
}
.hero-title {
    font-size: 4rem;
    font-weight: bold;
    background: linear-gradient(90deg, #ff660088, #ffcc00, #ff660088);
    background-size: 300% auto;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: shimmer 5s linear infinite;
    margin-bottom: 16px;
    letter-spacing: 6px;
}
.hero-sub {
    font-size: 0.9rem;
    color: #bbb;
    letter-spacing: 4px;
    text-transform: uppercase;
    margin-bottom: 50px;
}
.hero-whisper {
    font-size: 0.85rem;
    color: #b0b0b0;
    max-width: 640px;
    line-height: 1.8;
    margin: 0 auto 60px;
    font-style: italic;
}
.hero-whisper strong { color: #e07700; font-weight: normal; font-style: normal; }
.hero-actions { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; }
.btn-primary {
    background: rgba(204,102,0,0.15);
    color: #e07700;
    border: 1px solid rgba(224,119,0,0.4);
    padding: 14px 32px;
    border-radius: 2px;
    cursor: pointer;
    font-family: inherit;
    font-size: 0.8rem;
    letter-spacing: 3px;
    text-transform: uppercase;
    transition: all 0.3s;
    text-decoration: none;
}
.btn-primary:hover {
    background: rgba(224,119,0,0.25);
    border-color: #e07700;
    box-shadow: 0 0 30px rgba(204,102,0,0.15);
}
.btn-ghost {
    background: transparent;
    color: #bbb;
    border: 1px solid #555;
    padding: 14px 32px;
    border-radius: 2px;
    cursor: pointer;
    font-family: inherit;
    font-size: 0.8rem;
    letter-spacing: 3px;
    text-transform: uppercase;
    transition: all 0.3s;
    text-decoration: none;
}
.btn-ghost:hover { border-color: #888; color: #e0e0e0; }

/* ── Divider ── */
.divider {
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(204,102,0,0.2), transparent);
    margin: 0;
}

/* ── Signal Banner ── */
.signal-banner {
    padding: 24px;
    display: flex;
    justify-content: center;
    gap: 40px;
    flex-wrap: wrap;
    background: #08080a;
}
.signal {
    text-align: center;
    min-width: 100px;
}
.signal-value {
    font-size: 1.6rem;
    font-weight: bold;
    color: #e07700;
    text-shadow: 0 0 20px rgba(224,119,0,0.3);
}
.signal-label {
    font-size: 0.6rem;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-top: 4px;
}

/* ── Main ── */
.main { max-width: 1000px; margin: 0 auto; padding: 60px 24px; }
.section { margin-bottom: 80px; }
.section h2 {
    font-size: 0.8rem;
    color: #e07700;
    letter-spacing: 4px;
    text-transform: uppercase;
    margin-bottom: 24px;
    padding-bottom: 10px;
    border-bottom: 1px solid #1a1a1a;
}
.section p {
    color: #c0c0c0;
    line-height: 1.9;
    font-size: 0.88rem;
    margin-bottom: 20px;
}
.section p strong { color: #e8b888; font-weight: normal; }

/* ── Cards ── */
.card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}
.card {
    background: rgba(255,255,255,0.02);
    border: 1px solid #151515;
    border-radius: 4px;
    padding: 24px;
    transition: border-color 0.4s, background 0.4s;
}
.card:hover {
    border-color: rgba(204,102,0,0.3);
    background: rgba(204,102,0,0.02);
}
.card-glyph {
    font-size: 1.2rem;
    color: rgba(224,119,0,0.6);
    margin-bottom: 12px;
}
.card-title {
    font-size: 0.9rem;
    color: #e0e0e0;
    margin-bottom: 10px;
    letter-spacing: 1px;
}
.card-desc {
    font-size: 0.8rem;
    color: #b8b8b8;
    line-height: 1.6;
}

/* ── The Void (concept block) ── */
.void-block {
    position: relative;
    padding: 60px 40px;
    margin: 40px 0;
    text-align: center;
    border: 1px solid #111;
    border-radius: 4px;
    overflow: hidden;
}
.void-block::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at center, rgba(204,102,0,0.04) 0%, transparent 70%);
    animation: voidpulse 8s ease-in-out infinite;
}
@keyframes voidpulse {
    0%, 100% { opacity: 0.3; }
    50% { opacity: 1; }
}
.void-text {
    position: relative;
    font-size: 0.85rem;
    color: #c8c8c8;
    line-height: 2;
    max-width: 600px;
    margin: 0 auto;
}
.void-text .highlight { color: #e07700; }

/* ── Flow Diagram ── */
.flow {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    flex-wrap: wrap;
    margin: 40px 0;
    padding: 20px 0;
}
.flow-node {
    background: rgba(255,255,255,0.02);
    border: 1px solid #1a1a1a;
    border-radius: 4px;
    padding: 18px 22px;
    text-align: center;
    min-width: 110px;
    transition: border-color 0.3s;
}
.flow-node:hover { border-color: rgba(204,102,0,0.4); }
.flow-node-label {
    font-size: 0.55rem;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 6px;
}
.flow-node-name { font-size: 0.8rem; color: #ccc; }
.flow-node.primary { border-color: rgba(204,102,0,0.3); }
.flow-node.primary .flow-node-name { color: #e07700; }
.flow-arrow { color: #888; font-size: 1rem; padding: 0 10px; }

/* ── Terminal ── */
.terminal {
    background: #08080a;
    border: 1px solid #151515;
    border-radius: 4px;
    overflow: hidden;
    margin: 24px 0;
}
.terminal-bar {
    background: #0d0d0f;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    gap: 6px;
    border-bottom: 1px solid #111;
}
.terminal-dot { width: 8px; height: 8px; border-radius: 50%; }
.terminal-dot.r { background: #442222; }
.terminal-dot.y { background: #443322; }
.terminal-dot.g { background: #224422; }
.terminal-title { font-size: 0.65rem; color: #999; margin-left: 10px; letter-spacing: 1px; }
.terminal-body {
    padding: 20px 24px;
    font-size: 0.8rem;
    line-height: 1.8;
    min-height: 160px;
}
.terminal-body .p { color: #d0d0d0; }
.terminal-body .c { color: #888; }
.terminal-body .g { color: #7cc47c; }
.terminal-body .b { color: #7cb4cc; }
.terminal-body .o { color: #d4a020; }
.terminal-body .d { color: #a0a0a0; }

/* ── Phases ── */
.phases { position: relative; padding-left: 30px; margin: 30px 0; }
.phases::before {
    content: '';
    position: absolute;
    left: 6px; top: 0; bottom: 0;
    width: 1px;
    background: linear-gradient(to bottom, rgba(204,102,0,0.4), transparent);
}
.phase { position: relative; margin-bottom: 32px; }
.phase::before {
    content: '';
    position: absolute;
    left: -28px; top: 5px;
    width: 8px; height: 8px;
    border-radius: 50%;
    background: rgba(204,102,0,0.5);
    box-shadow: 0 0 10px rgba(204,102,0,0.2);
}
.phase-label {
    font-size: 0.6rem;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 3px;
    margin-bottom: 6px;
}
.phase-text { font-size: 0.85rem; color: #c0c0c0; line-height: 1.6; }

/* ── Log ── */
.log-panel {
    background: #08080a;
    border: 1px solid #111;
    border-radius: 4px;
    padding: 14px;
    max-height: 220px;
    overflow-y: auto;
    font-size: 0.75rem;
}
.log-entry { padding: 3px 0; color: #999; transition: color 0.3s; }
.log-entry.gn { color: #7cc47c; }
.log-entry.bl { color: #7cb4cc; }
.log-entry.or { color: #d4a020; }
.log-entry.rd { color: #cc7070; }

/* ── Self-build callout ── */
.self-build {
    background: rgba(204,102,0,0.03);
    border: 1px solid rgba(204,102,0,0.15);
    border-radius: 4px;
    padding: 30px 36px;
    margin: 40px 0;
    position: relative;
}
.self-build::before {
    content: '';
    position: absolute;
    top: -1px; left: 20%; right: 20%;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(204,102,0,0.5), transparent);
}
.self-build-title {
    font-size: 0.7rem;
    color: #e07700;
    text-transform: uppercase;
    letter-spacing: 3px;
    margin-bottom: 14px;
}
.self-build-text {
    font-size: 0.85rem;
    color: #c0c0c0;
    line-height: 1.8;
}
.self-build-text code {
    background: rgba(255,255,255,0.05);
    padding: 2px 6px;
    border-radius: 2px;
    color: #e07700;
    font-size: 0.82rem;
}

/* ── Dots ── */
.dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 6px; }
.dot-glow { background: #e07700; box-shadow: 0 0 8px rgba(224,119,0,0.6); animation: dotpulse 3s ease-in-out infinite; }
@keyframes dotpulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

/* ── Footer ── */
.footer {
    border-top: 1px solid #111;
    padding: 40px 24px;
    text-align: center;
    color: #888;
    font-size: 0.7rem;
    letter-spacing: 2px;
}
.footer a { color: #aaa; text-decoration: none; transition: color 0.3s; }
.footer a:hover { color: #e07700; }
.footer .footer-links { margin-top: 12px; display: flex; justify-content: center; gap: 24px; }

/* ── Responsive ── */
@media (max-width: 768px) {
    .hero-title { font-size: 2.5rem; letter-spacing: 3px; }
    .hero { padding: 80px 24px 60px; min-height: 70vh; }
    .flow { flex-direction: column; }
    .flow-arrow { transform: rotate(90deg); padding: 8px 0; }
    .card-grid { grid-template-columns: 1fr; }
    .signal-banner { gap: 20px; }
    .nav { gap: 14px; }
    .nav a { font-size: 0.65rem; }
}

/* ── Fade ── */
.fade-in {
    opacity: 0;
    transform: translateY(16px);
    transition: opacity 0.8s ease, transform 0.8s ease;
}
.fade-in.visible { opacity: 1; transform: translateY(0); }
</style>
</head>
<body>

<div class="header">
    <h1><a href="#">VoidLux</a></h1>
    <div class="nav">
        <a href="#how">How it works</a>
        <a href="#anatomy">The forge</a>
        <a href="#play">Play</a>
        <a href="#start">Start</a>
        <?php if (!empty($loggedIn)): ?><a href="/forge">Enter the forge</a><?php else: ?><a href="/auth/login">Sign in</a><?php endif; ?>
    </div>
</div>

<!-- Hero -->
<div class="hero" id="hero">
    <div class="hero-content">
        <div class="hero-glyph">&#9673; &#9673; &#9673;</div>
        <div class="hero-title">THE FORGE</div>
        <div class="hero-sub">build the arcade games of your dreams</div>
        <div class="hero-whisper">
            The twin-stick shooter you doodled in a notebook. The cow-abducting saucer. The snake that becomes its own arsenal.
            Describe it in plain words and a forge of AI builders <strong>plans</strong> it, <strong>builds</strong> it level by level,
            <strong>playtests</strong> it with its own bots, and has an independent <strong>reviewer</strong> judge the result.
            <br><br>
            You play. You pause, point at a thing on the screen and say what should change. It changes.
            When it is good, it ships to its own address, ready for players.
        </div>
        <div class="hero-actions">
            <?php if (!empty($loggedIn)): ?><a href="/forge/summon" class="btn-primary">Summon a game</a><a href="/forge" class="btn-ghost">My games</a>
            <?php else: ?><a href="/auth/register" class="btn-primary">Start forging</a><a href="#play" class="btn-ghost">Play one now</a><?php endif; ?>
        </div>
    </div>
</div>

<div class="divider"></div>

<div class="signal-banner">
    <div class="signal"><div class="signal-value">1</div><div class="signal-label">Brief in plain words</div></div>
    <div class="signal"><div class="signal-value">AI</div><div class="signal-label">Planned and built</div></div>
    <div class="signal"><div class="signal-value">60</div><div class="signal-label">fps canvas, all code</div></div>
    <div class="signal"><div class="signal-value">&#9998;</div><div class="signal-label">Point and say</div></div>
    <div class="signal"><div class="signal-value">URL</div><div class="signal-label">Shipped to its own</div></div>
</div>

<div class="divider"></div>

<div class="main">

    <div class="section fade-in" id="how">
        <h2>What Dwells in the Void</h2>
        <p>
            VoidLux is a game forge. You write a <strong>brief</strong>: who the player is, what they do every ten seconds, how a run
            ends, what they collect or build, what makes it fun. A <strong>planner</strong> turns that into a design, a list of
            checkable promises and an ordered set of build tasks. <strong>Builders</strong> take the tasks one at a time, in the
            game's own folder, on an engine pulled from a finished and polished arcade game: pooled-voice sound, a chip tracker, a
            sprite atlas, touch controls, a bot harness.
        </p>
        <p>
            After every task the game is <strong>playtested</strong>: it plays itself with its bots while every error is caught.
            Then a <strong>reviewer</strong> that never saw the build checks each promise with evidence. Fail? Rework. Pass?
            A checkpoint, and on to the next. Nothing vague survives.
        </p>

        <div class="self-build">
            <div class="self-build-title">You are the producer</div>
            <div class="self-build-text">
                Play the game as it grows. Press the note button, point at the cow that is too small or the boss that is too fast,
                and say so. Your note, with a screenshot and what you pointed at, goes to the builder. Teammates vote on notes,
                add their two cents, and the whole batch is refined before it is sent. Kids, friends, a whole family can forge
                one game together on one account.
            </div>
        </div>

        <div class="void-block">
            <div class="void-text">
                the forge does not sleep<br>
                it <span class="highlight">plans</span>, <span class="highlight">builds</span>, <span class="highlight">playtests</span>, <span class="highlight">reviews</span><br>
                and hands you a game
            </div>
        </div>
    </div>

    <div class="section fade-in" id="anatomy">
        <h2>Anatomy of the Forge</h2>
        <div class="card-grid">
            <div class="card"><div class="card-glyph">&#9670;</div><div class="card-title">The Planner</div><div class="card-desc">Reads your brief and writes the concept: the fantasy, the loop, the enemies, the levels, the sounds, and what sells it. Then the promises and the tasks.</div></div>
            <div class="card"><div class="card-glyph">&#9679;</div><div class="card-title">The Builders</div><div class="card-desc">AI coders working one task at a time in the game's own folder, in a sandbox of their own, on plain canvas JavaScript with no build step and no dependencies.</div></div>
            <div class="card"><div class="card-glyph">&#9733;</div><div class="card-title">The Reviewer</div><div class="card-desc">A fresh mind that never saw the build judges every promise with evidence from the code and the playtest. Pass or fail, with reasons. Fails go back as rework.</div></div>
            <div class="card"><div class="card-glyph">&#9998;</div><div class="card-title">Note Mode</div><div class="card-desc">Pause the game, point at anything on the screen, say what should change. Notes carry a screenshot and the thing you pointed at. Vote, comment, refine, send.</div></div>
            <div class="card"><div class="card-glyph">&#9835;</div><div class="card-title">Sound &amp; Music</div><div class="card-desc">Every sound the game plays is a slot in the sound editor, designed as layers. An 8-track chip tracker writes the music straight into the game. All synthesized, no files.</div></div>
            <div class="card"><div class="card-glyph">&#9813;</div><div class="card-title">Ship</div><div class="card-desc">One click builds the game and serves it at its own address, straight from disk, phone and desktop, with touch controls and fullscreen built in. Every step on the way is a checkpoint you can roll back to.</div></div>
        </div>
    </div>

    <div class="section fade-in" id="flow">
        <h2>How a Game Is Forged</h2>
        <div class="flow">
            <div class="flow-node primary"><div class="flow-node-label">Write</div><div class="flow-node-name">Brief</div></div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node"><div class="flow-node-label">AI</div><div class="flow-node-name">Plan</div></div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node"><div class="flow-node-label">AI</div><div class="flow-node-name">Build</div></div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node"><div class="flow-node-label">Bots</div><div class="flow-node-name">Playtest</div></div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node"><div class="flow-node-label">AI</div><div class="flow-node-name">Review</div></div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node primary"><div class="flow-node-label">Ship</div><div class="flow-node-name">URL</div></div>
        </div>
        <div class="phases">
            <div class="phase"><div class="phase-label">Brief</div><div class="phase-text">A paragraph. "A side scroller: you are an alien saucer abducting cows, and the farmers build a defence network against you. Upgrade the ship between nights." That is enough.</div></div>
            <div class="phase"><div class="phase-label">Plan</div><div class="phase-text">The planner writes the concept and splits it into tasks small enough to finish, each with a check the reviewer can run. You can edit any of it before a line of code exists.</div></div>
            <div class="phase"><div class="phase-label">Build</div><div class="phase-text">A builder takes a task, writes the code, checks it, playtests it, ticks it off and commits. You watch the timeline, or go play what is there so far.</div></div>
            <div class="phase"><div class="phase-label">Playtest</div><div class="phase-text">The game plays itself with its own bots, from the title through game over, while every exception is caught. It runs in your browser while you have the game open, so you see the bots play.</div></div>
            <div class="phase"><div class="phase-label">Review &amp; Ship</div><div class="phase-text">The reviewer checks every promise. Rework what fails. Tune the sound and the music. Publish, and the game is live at its own address for anyone with the link.</div></div>
        </div>
    </div>

    <div class="section fade-in" id="play">
        <h2>Play What the Forge Has Made</h2>
        <?php if (!empty($games)): ?>
        <div class="card-grid">
            <?php foreach ($games as $g): ?>
            <a class="card" href="<?= htmlspecialchars($g['url']) ?>" target="_blank" style="text-decoration:none">
                <div class="card-glyph">&#9654;</div>
                <div class="card-title"><?= htmlspecialchars($g['title']) ?></div>
                <div class="card-desc"><?= htmlspecialchars($g['brief']) ?><?php if ($g['price'] !== null): ?><br><strong>$<?= number_format((float) $g['price'], 2) ?></strong><?php endif; ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p>The first games are in the forge now. Check back soon, or sign in and forge your own.</p>
        <?php endif; ?>
    </div>

    <div class="section fade-in" id="start">
        <h2>Start Forging</h2>
        <div class="phases">
            <div class="phase"><div class="phase-label">1 · Sign in</div><div class="phase-text">Make an account, or accept a teammate's invite. A team shares its games: everyone on it can play, note, build and ship.</div></div>
            <div class="phase"><div class="phase-label">2 · Summon</div><div class="phase-text">Title, a slug for the address, the brief. Start from a small arena shooter on the engine, or from the whole of Centipede, a finished game, and bend it into something new.</div></div>
            <div class="phase"><div class="phase-label">3 · Build the game</div><div class="phase-text">One button runs the loop: plan, build, playtest, review. Watch the timeline, or play along and leave notes. Chat with the builder any time.</div></div>
            <div class="phase"><div class="phase-label">4 · Ship</div><div class="phase-text">Publish. Send the link. Put a price on the card when it is worth one.</div></div>
        </div>
        <p style="margin-top:24px;">
            <?php if (!empty($loggedIn)): ?><a href="/forge/summon" class="btn-primary">Summon a game</a><?php else: ?><a href="/auth/register" class="btn-primary">Create an account</a> <a href="/auth/login" class="btn-ghost">Sign in</a><?php endif; ?>
        </p>
    </div>

</div>

<div class="footer">
    VOIDLUX &mdash; THE GAME FORGE
    <div class="footer-links">
        <a href="/forge">The forge</a>
        <a href="/centipede/" target="_blank">Centipede</a>
        <a href="/terms">Terms</a>
        <a href="/privacy">Privacy</a>
    </div>
</div>

<script>
// ── Particle mesh in hero ──
(function() {
    const hero = document.getElementById('hero');
    const canvas = document.createElement('canvas');
    canvas.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:1;';
    hero.insertBefore(canvas, hero.firstChild);
    const ctx = canvas.getContext('2d');
    let particles = [];
    const COUNT = 80;

    function resize() {
        canvas.width = hero.offsetWidth;
        canvas.height = hero.offsetHeight;
    }
    resize();
    window.addEventListener('resize', resize);

    for (let i = 0; i < COUNT; i++) {
        particles.push({
            x: Math.random() * canvas.width,
            y: Math.random() * canvas.height,
            vx: (Math.random() - 0.5) * 0.3,
            vy: (Math.random() - 0.5) * 0.3,
            r: Math.random() * 1.5 + 0.5,
            a: Math.random() * 0.4 + 0.1,
        });
    }

    function draw() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        for (let i = 0; i < particles.length; i++) {
            for (let j = i + 1; j < particles.length; j++) {
                const dx = particles[i].x - particles[j].x;
                const dy = particles[i].y - particles[j].y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < 140) {
                    const alpha = (1 - dist / 140) * 0.08;
                    ctx.beginPath();
                    ctx.moveTo(particles[i].x, particles[i].y);
                    ctx.lineTo(particles[j].x, particles[j].y);
                    ctx.strokeStyle = 'rgba(204, 102, 0, ' + alpha + ')';
                    ctx.lineWidth = 0.5;
                    ctx.stroke();
                }
            }
        }

        particles.forEach(p => {
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(204, 102, 0, ' + p.a + ')';
            ctx.fill();
            p.x += p.vx;
            p.y += p.vy;
            if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
            if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
        });

        requestAnimationFrame(draw);
    }
    draw();
})();
// ── Scroll fade-in ──
const obs = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
}, { threshold: 0.08 });
document.querySelectorAll('.fade-in').forEach(el => obs.observe(el));
// ── Smooth scroll ──
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        const t = document.querySelector(a.getAttribute('href'));
        if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
</script>
</script>
</body>
</html>
