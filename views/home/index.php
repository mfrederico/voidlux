<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VoidLux — Self-Organizing AI Agent Swarm</title>
<meta name="description" content="A self-organizing AI agent swarm that decomposes, delegates, builds, reviews, and merges code — autonomously. Give it a task and a git repo. It opens a PR.">
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
        <a href="#swarm">Swarm</a>
        <a href="#anatomy">Anatomy</a>
        <a href="#flow">Flow</a>
        <a href="#deploy">Deploy</a>
            <?php if (!empty($loggedIn)): ?><a href="/forge">Forge</a><?php else: ?><a href="/auth/login">Sign in</a><?php endif; ?>
        <a href="https://github.com/mfrederico/voidlux" target="_blank" class="gh-link">
            <svg viewBox="0 0 16 16"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
            GitHub
        </a>
    </div>
</div>

<!-- Hero -->
<div class="hero" id="hero">
    <div class="hero-content">
        <div class="hero-glyph">&#9673; &#9673; &#9673;</div>
        <div class="hero-title">THE SWARM</div>
        <div class="hero-sub">self-organizing agent intelligence</div>
        <div class="hero-whisper">
            Give it a task in plain English and a git repo.
            It <strong>decomposes</strong> the work into subtasks with dependency ordering.
            It <strong>dispatches</strong> them to AI agents in isolated git worktrees.
            It <strong>reviews</strong> results against acceptance criteria.
            It <strong>merges</strong> branches, runs tests, and opens a pull request.
            <br><br>
            It built its own Dockerfile. It added its own PR merge button. It fixes its own bugs.
        </div>
        <div class="hero-actions">
            <a href="https://github.com/mfrederico/voidlux" target="_blank" class="btn-primary">View on GitHub</a>
            <a href="#deploy" class="btn-ghost">Quick Start</a>
        </div>
    </div>
</div>

<div class="divider"></div>

<!-- Signal Banner -->
<div class="signal-banner">
    <div class="signal">
        <div class="signal-value">P2P</div>
        <div class="signal-label">Gossip Mesh</div>
    </div>
    <div class="signal">
        <div class="signal-value">LLM</div>
        <div class="signal-label">Task Planning</div>
    </div>
    <div class="signal">
        <div class="signal-value">AI</div>
        <div class="signal-label">Code Review</div>
    </div>
    <div class="signal">
        <div class="signal-value">GIT</div>
        <div class="signal-label">Worktree Isolation</div>
    </div>
    <div class="signal">
        <div class="signal-value">PR</div>
        <div class="signal-label">Auto-Merge</div>
    </div>
</div>

<div class="divider"></div>

<!-- Main -->
<div class="main">

    <!-- The Swarm -->
    <div class="section fade-in" id="swarm">
        <h2>What Dwells in the Void</h2>
        <p>
            VoidLux orchestrates AI coding agents &mdash; Claude Code, OpenCode, or
            anything that runs in a terminal &mdash; across a decentralized P2P mesh.
            An <strong>emperor</strong> node drives the swarm through a web dashboard.
            An <strong>LLM planner</strong> decomposes your request into subtasks with
            dependency ordering, work instructions, and acceptance criteria. Agents claim
            work, execute in isolated <strong>git worktrees</strong>, and report back
            through the mesh.
        </p>
        <p>
            When a subtask completes, an <strong>LLM reviewer</strong> checks the result against
            acceptance criteria. Reject? The task is requeued with feedback, up to three times.
            When all subtasks pass, VoidLux <strong>merges all branches</strong> into an
            integration branch, runs your test suite, and creates a single pull request.
            Merge conflicts? It requeues the conflicting subtasks. Test failures? It requeues everything.
        </p>

        <div class="self-build">
            <div class="self-build-title">The Swarm Builds Itself</div>
            <div class="self-build-text">
                VoidLux regularly modifies its own codebase through swarm tasks. Its
                <code>Dockerfile</code>, <code>docker-compose.yml</code>, PR merge button,
                and auto-merge toggle were all implemented by the swarm &mdash; submitted as
                tasks, decomposed into subtasks, dispatched to agents, reviewed, merged, and
                shipped. The swarm that builds software is itself built by a swarm.
            </div>
        </div>

        <div class="void-block">
            <div class="void-text">
                the swarm does not sleep<br>
                it does not wait for permission<br>
                it <span class="highlight">plans</span>, <span class="highlight">dispatches</span>, <span class="highlight">reviews</span>, <span class="highlight">merges</span><br>
                and returns to the void
            </div>
        </div>
    </div>

    <!-- Anatomy -->
    <div class="section fade-in" id="anatomy">
        <h2>Anatomy of the Swarm</h2>
        <div class="card-grid">
            <div class="card">
                <div class="card-glyph">&#9813;</div>
                <div class="card-title">The Emperor</div>
                <div class="card-desc">
                    Dashboard, AI planner, task dispatcher, code reviewer.
                    Elected through consensus. Mortal by design &mdash;
                    run regicide and watch another rise.
                </div>
            </div>
            <div class="card">
                <div class="card-glyph">&#9670;</div>
                <div class="card-title">The Planner</div>
                <div class="card-desc">
                    LLM decomposes high-level requests into subtasks with
                    dependency ordering, work instructions, and acceptance
                    criteria. Claude or Ollama &mdash; your choice.
                </div>
            </div>
            <div class="card">
                <div class="card-glyph">&#9679;</div>
                <div class="card-title">The Agents</div>
                <div class="card-desc">
                    Autonomous minds in isolated tmux sessions, each in its
                    own git worktree branch. Powered by Claude Code,
                    OpenCode, or whatever you wire in.
                </div>
            </div>
            <div class="card">
                <div class="card-glyph">&#9733;</div>
                <div class="card-title">The Reviewer</div>
                <div class="card-desc">
                    LLM evaluates completed work against acceptance criteria.
                    Pass or fail. Rejected tasks are requeued with feedback.
                    No vague claims survive the review.
                </div>
            </div>
            <div class="card">
                <div class="card-glyph">&#9876;</div>
                <div class="card-title">The Merge Loop</div>
                <div class="card-desc">
                    All subtask branches merge into an integration branch.
                    Tests run. Conflicts requeue the offending subtasks.
                    Failures requeue everything. Up to 3 attempts. Then a PR.
                </div>
            </div>
            <div class="card">
                <div class="card-glyph">&#8734;</div>
                <div class="card-title">The Mesh</div>
                <div class="card-desc">
                    P2P gossip over TCP with Lamport timestamps.
                    UDP LAN discovery. Peer exchange. Anti-entropy sync.
                    Leader election. No central coordinator. No SPOF.
                </div>
            </div>
        </div>
    </div>

    <!-- Flow -->
    <div class="section fade-in" id="flow">
        <h2>How a Task Dissolves</h2>

        <div class="flow">
            <div class="flow-node primary">
                <div class="flow-node-label">Submit</div>
                <div class="flow-node-name">Task</div>
            </div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node">
                <div class="flow-node-label">LLM</div>
                <div class="flow-node-name">Plan</div>
            </div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node">
                <div class="flow-node-label">Dispatch</div>
                <div class="flow-node-name">Agents</div>
            </div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node">
                <div class="flow-node-label">LLM</div>
                <div class="flow-node-name">Review</div>
            </div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node">
                <div class="flow-node-label">Merge</div>
                <div class="flow-node-name">+ Test</div>
            </div>
            <div class="flow-arrow">&#10230;</div>
            <div class="flow-node primary">
                <div class="flow-node-label">Ship</div>
                <div class="flow-node-name">PR</div>
            </div>
        </div>

        <div class="phases">
            <div class="phase">
                <div class="phase-label">Planning</div>
                <div class="phase-text">
                    An LLM analyzes your request and the project structure. It decomposes
                    the task into subtasks with dependency ordering, work instructions,
                    and acceptance criteria. Dependencies resolve in order &mdash; blocked
                    tasks wait until prerequisites complete.
                </div>
            </div>
            <div class="phase">
                <div class="phase-label">Dispatch</div>
                <div class="phase-text">
                    Push-based, event-driven dispatch via Swoole channels. Each agent
                    gets a git worktree with its own branch. The task prompt includes
                    work instructions and results from completed prerequisite tasks.
                </div>
            </div>
            <div class="phase">
                <div class="phase-label">Execution</div>
                <div class="phase-text">
                    Agents work autonomously in isolated tmux sessions. Progress streams
                    back through MCP tools and WebSocket. The dashboard shows live output.
                    Agents communicate completion via Model Context Protocol.
                </div>
            </div>
            <div class="phase">
                <div class="phase-label">Review</div>
                <div class="phase-text">
                    An LLM evaluates each subtask's result against its acceptance criteria.
                    Rejected tasks are requeued with specific feedback. Up to three
                    review cycles before failure. No vague "looks good" survives.
                </div>
            </div>
            <div class="phase">
                <div class="phase-label">Integration</div>
                <div class="phase-text">
                    All subtask branches merge into a single integration branch. Your test
                    command runs. Merge conflicts requeue the conflicting subtasks. Test
                    failures requeue all subtasks. Pass? Push the branch and create a PR.
                    Auto-merge if you toggled it.
                </div>
            </div>
        </div>
    </div>

    <!-- Deploy -->
    <div class="section fade-in" id="deploy">
        <h2>Summoning</h2>

        <div class="terminal">
            <div class="terminal-bar">
                <div class="terminal-dot r"></div>
                <div class="terminal-dot y"></div>
                <div class="terminal-dot g"></div>
                <div class="terminal-title">bare metal</div>
            </div>
            <div class="terminal-body" id="term-body"></div>
        </div>

        <div class="terminal">
            <div class="terminal-bar">
                <div class="terminal-dot r"></div>
                <div class="terminal-dot y"></div>
                <div class="terminal-dot g"></div>
                <div class="terminal-title">docker</div>
            </div>
            <div class="terminal-body">
                <div class="p">$ git clone https://github.com/mfrederico/voidlux.git</div>
                <div class="p">$ cd voidlux</div>
                <div class="p">$ export ANTHROPIC_API_KEY=sk-ant-...</div>
                <div class="p">$ docker compose up -d</div>
                <div class="g">[+] Running 1/1</div>
                <div class="g"> &#10003; Container voidlux  Started</div>
                <div class="d"></div>
                <div class="p">$ bash scripts/register-agents.sh 3 claude git@github.com:you/repo.git</div>
                <div class="g">[swarm] 3 agents registered</div>
                <div class="d"></div>
                <div class="c"># open http://localhost:9090</div>
            </div>
        </div>

        <p style="margin-top:24px;">
            Full documentation, API reference, and wire protocol spec in the
            <a href="https://github.com/mfrederico/voidlux" target="_blank" style="color:#e07700;text-decoration:underline;">GitHub README</a>.
        </p>
    </div>

    <!-- Live Stream -->
    <div class="section fade-in">
        <h2><span class="dot dot-glow"></span> Swarm Murmur</h2>
        <div class="log-panel" id="log-panel"></div>
    </div>

</div>

<!-- Footer -->
<div class="footer">
    VOIDLUX &mdash; SELF-ORGANIZING AI AGENT SWARM
    <div class="footer-links">
        <a href="https://github.com/mfrederico/voidlux" target="_blank">GitHub</a>
        <a href="https://github.com/mfrederico/voidlux/blob/master/README.md" target="_blank">Docs</a>
        <a href="https://github.com/mfrederico/voidlux/issues" target="_blank">Issues</a>
        <a href="https://github.com/mfrederico/voidlux/blob/master/LICENSE" target="_blank">MIT License</a>
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

// ── Typewriter terminal ──
const termLines = [
    { cls: 'p', text: '$ git clone https://github.com/mfrederico/voidlux.git', delay: 0 },
    { cls: 'p', text: '$ cd voidlux && composer install', delay: 400 },
    { cls: 'd', text: '', delay: 300 },
    { cls: 'p', text: '$ bash scripts/enter-the-void.sh', delay: 600 },
    { cls: 'g', text: '[seneschal] listening on :9090 (stable proxy)', delay: 500 },
    { cls: 'o', text: '[emperor] ascending on :9091', delay: 400 },
    { cls: 'b', text: '[mesh] P2P gossip mesh active on :7101', delay: 300 },
    { cls: 'd', text: '', delay: 400 },
    { cls: 'p', text: '$ bash scripts/register-agents.sh 3 claude git@github.com:you/repo.git', delay: 600 },
    { cls: 'g', text: '[swarm] agent-d41b3d-1 registered (starting)', delay: 300 },
    { cls: 'g', text: '[swarm] agent-d41b3d-2 registered (starting)', delay: 200 },
    { cls: 'g', text: '[swarm] agent-d41b3d-3 registered (starting)', delay: 200 },
    { cls: 'b', text: '[monitor] agent-d41b3d-1 -> idle', delay: 800 },
    { cls: 'b', text: '[monitor] agent-d41b3d-2 -> idle', delay: 200 },
    { cls: 'b', text: '[monitor] agent-d41b3d-3 -> idle', delay: 200 },
    { cls: 'd', text: '', delay: 400 },
    { cls: 'c', text: '# open http://localhost:9090 — deploy your first swarm task', delay: 500 },
    { cls: 'd', text: '', delay: 300 },
    { cls: 'o', text: '[planner] decomposing "Add JWT authentication" into 4 subtasks...', delay: 800 },
    { cls: 'b', text: '[dispatch] subtask 1/4 -> agent-d41b3d-1 (worktree: task/a3f8c21d)', delay: 400 },
    { cls: 'b', text: '[dispatch] subtask 2/4 -> agent-d41b3d-2 (worktree: task/b7e9d04a)', delay: 300 },
    { cls: 'd', text: '[agent-d41b3d-1] reading src/middleware/... reasoning...', delay: 1200 },
    { cls: 'g', text: '[mcp] agent-d41b3d-1 task_complete: "JWT middleware implemented"', delay: 1000 },
    { cls: 'o', text: '[reviewer] checking acceptance criteria... PASS', delay: 600 },
    { cls: 'b', text: '[dispatch] subtask 3/4 -> agent-d41b3d-1 (unblocked, depends on 1)', delay: 400 },
    { cls: 'g', text: '[mcp] agent-d41b3d-2 task_complete: "Auth endpoints ready"', delay: 800 },
    { cls: 'g', text: '[mcp] agent-d41b3d-1 task_complete: "User model updated"', delay: 600 },
    { cls: 'b', text: '[dispatch] subtask 4/4 -> agent-d41b3d-3 (all deps resolved)', delay: 400 },
    { cls: 'g', text: '[mcp] agent-d41b3d-3 task_complete: "Auth tests passing"', delay: 1000 },
    { cls: 'o', text: '[merge] integrating 4 branches into integrate/a3f8c21d...', delay: 500 },
    { cls: 'g', text: '[merge] all branches merged cleanly', delay: 400 },
    { cls: 'g', text: '[test] composer test -> 47 tests, 0 failures', delay: 600 },
    { cls: 'g', text: '[pr] https://github.com/you/repo/pull/42', delay: 400 },
    { cls: 'd', text: '', delay: 200 },
    { cls: 'c', text: '// the swarm waits', delay: 600 },
];

const termBody = document.getElementById('term-body');
let lineIdx = 0;
let totalDelay = 0;
termLines.forEach(line => {
    totalDelay += line.delay;
    setTimeout(() => {
        const div = document.createElement('div');
        div.className = line.cls;
        div.textContent = line.text;
        div.style.opacity = '0';
        div.style.transition = 'opacity 0.3s';
        termBody.appendChild(div);
        requestAnimationFrame(() => div.style.opacity = '1');
        termBody.scrollTop = termBody.scrollHeight;
    }, totalDelay);
});

// ── Scroll fade-in ──
const obs = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
}, { threshold: 0.08 });
document.querySelectorAll('.fade-in').forEach(el => obs.observe(el));

// ── Event murmur ──
const murmurs = [
    { c: 'gn', t: 'task dissolved: refactor auth module [3 subtasks, all passed review]' },
    { c: 'bl', t: 'agent-d41b3d-2 claimed subtask: implement login endpoint' },
    { c: 'or', t: 'planner decomposing: "add dark mode support" -> 5 subtasks' },
    { c: 'gn', t: 'reviewer PASS: acceptance criteria met for JWT middleware' },
    { c: 'bl', t: 'dispatch: subtask 3/5 -> agent-d41b3d-1 (deps resolved)' },
    { c: 'gn', t: 'merge: 4 branches integrated cleanly into integrate/a3f8c21d' },
    { c: 'rd', t: 'reviewer REJECT: missing error handling in /api/users — requeuing' },
    { c: 'bl', t: 'mcp: agent-d41b3d-3 task_complete "database migration done"' },
    { c: 'gn', t: 'test: composer test -> 89 tests, 0 failures' },
    { c: 'or', t: 'pr created: https://github.com/you/repo/pull/47' },
    { c: 'bl', t: 'agent-d41b3d-1 -> idle (worktree cleaned)' },
    { c: 'gn', t: 'auto-merged: PR #47 into master' },
    { c: 'bl', t: 'dispatch: subtask 1/3 -> agent-d41b3d-2 (worktree: task/cf567520)' },
    { c: 'gn', t: 'task dissolved: add rate limiting [2 subtasks]' },
    { c: 'or', t: 'planner decomposing: "optimize database queries" -> 3 subtasks' },
    { c: 'rd', t: 'merge conflict on src/models/User.php — requeuing subtask 2' },
    { c: 'bl', t: 'mcp: agent-d41b3d-1 task_progress "implementing caching layer"' },
    { c: 'gn', t: 'reviewer PASS: all 4 acceptance criteria satisfied' },
    { c: 'gn', t: 'task dissolved: WebSocket reconnection logic' },
    { c: 'or', t: 'emperor heartbeat: 3 agents idle, 0 pending tasks' },
    { c: 'bl', t: 'mesh sync: gossip propagated to 2 peers in 3ms' },
    { c: 'gn', t: 'test: npm test -> 127 passed, 0 failed' },
];

const logPanel = document.getElementById('log-panel');
let mIdx = 0;
function addMurmur() {
    const m = murmurs[mIdx % murmurs.length];
    const t = new Date().toLocaleTimeString();
    const div = document.createElement('div');
    div.className = 'log-entry ' + m.c;
    div.innerHTML = '<span style="color:#888">[' + t + ']</span> ' + m.t;
    logPanel.insertBefore(div, logPanel.firstChild);
    if (logPanel.children.length > 40) logPanel.removeChild(logPanel.lastChild);
    mIdx++;
}
for (let i = 0; i < 6; i++) setTimeout(addMurmur, i * 150);
setInterval(addMurmur, 2500 + Math.random() * 2000);

// ── Smooth scroll ──
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        const t = document.querySelector(a.getAttribute('href'));
        if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
</script>
</body>
</html>
