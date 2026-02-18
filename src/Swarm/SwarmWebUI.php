<?php

declare(strict_types=1);

namespace VoidLux\Swarm;

class SwarmWebUI
{
    public static function render(string $nodeId, string $workbenchPath = ''): string
    {
        $nodeIdJs = json_encode($nodeId);
        if ($workbenchPath === '') {
            $workbenchPath = getcwd() . '/workbench';
        }
        $workbenchJs = json_encode($workbenchPath);
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VoidLux Swarm Emperor</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    background: #0a0a0a;
    color: #e0e0e0;
    font-family: 'Courier New', monospace;
    min-height: 100vh;
}
.header {
    background: linear-gradient(135deg, #1a0033, #0d001a);
    border-bottom: 2px solid #cc6600;
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.header h1 {
    font-size: 1.5rem;
    background: linear-gradient(90deg, #ff6600, #ffcc00, #ff6600);
    background-size: 200% auto;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: shimmer 3s linear infinite;
}
@keyframes shimmer { to { background-position: 200% center; } }
.status-bar {
    display: flex; gap: 16px; font-size: 0.8rem; color: #888;
}
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
.dot-green { background: #00ff66; box-shadow: 0 0 4px #00ff66; }
.dot-orange { background: #ff9900; box-shadow: 0 0 4px #ff9900; }

.main { max-width: 1200px; margin: 0 auto; padding: 20px 24px; }

.section { margin-bottom: 24px; }
.section h2 {
    font-size: 1.1rem; color: #cc6600; margin-bottom: 12px;
    border-bottom: 1px solid #333; padding-bottom: 6px;
}

/* Task form */
.task-form {
    display: grid; grid-template-columns: 1fr 2fr auto; gap: 10px;
    margin-bottom: 16px;
}
.task-form.expanded {
    grid-template-columns: 1fr; gap: 8px;
}
.task-form input, .task-form textarea, .task-form select {
    background: #1a1a1a; border: 1px solid #333; color: #fff;
    padding: 8px 12px; border-radius: 4px; font-family: inherit; font-size: 0.9rem;
}
.task-form input:focus, .task-form textarea:focus { outline: none; border-color: #cc6600; }
.task-form textarea { min-height: 80px; resize: vertical; }
.task-form button {
    background: #cc6600; color: #fff; border: none; padding: 8px 16px;
    border-radius: 4px; cursor: pointer; font-family: inherit; font-weight: bold;
}
.task-form button:hover { background: #dd7700; }
.toggle-btn {
    background: none; border: 1px solid #444; color: #888; padding: 4px 10px;
    border-radius: 3px; cursor: pointer; font-size: 0.75rem; font-family: inherit;
}
.toggle-btn:hover { border-color: #666; color: #ccc; }

/* Cards */
.card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.card {
    background: #111; border: 1px solid #222; border-radius: 6px;
    padding: 14px; transition: border-color 0.2s;
}
.card:hover { border-color: #444; }
.card-title { font-weight: bold; margin-bottom: 6px; font-size: 0.95rem; }
.card-meta { font-size: 0.75rem; color: #666; margin-top: 6px; }
.card-status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 0.75rem; font-weight: bold; }

.status-pending { background: #333; color: #aaa; }
.status-planning { background: #2a1a3a; color: #bb88ff; }
.status-claimed { background: #1a3a1a; color: #66cc66; }
.status-in_progress { background: #1a2a3a; color: #66aaff; }
.status-pending_review { background: #3a3a1a; color: #ddcc44; }
.status-completed { background: #1a3a1a; color: #00ff66; }
.status-failed { background: #3a1a1a; color: #ff6666; }
.status-cancelled { background: #333; color: #888; }
.status-waiting_input { background: #3a2a1a; color: #ffaa33; }
.status-merging { background: #2a2a3a; color: #aa88ff; }

.status-idle { background: #1a2a1a; color: #88cc88; }
.status-busy { background: #1a2a3a; color: #66aaff; }
.status-waiting { background: #3a3a1a; color: #cccc66; }
.status-offline { background: #2a1a1a; color: #886666; }

.card-actions { margin-top: 8px; }
.card-actions button {
    background: #222; border: 1px solid #444; color: #aaa; padding: 3px 10px;
    border-radius: 3px; cursor: pointer; font-size: 0.75rem; font-family: inherit;
    margin-right: 4px;
}
.card-actions button:hover { background: #333; color: #fff; }

/* Stats */
.stats { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 16px; }
.stat {
    background: #111; border: 1px solid #222; border-radius: 6px;
    padding: 12px 18px; text-align: center; min-width: 100px;
}
.stat-value { font-size: 1.5rem; font-weight: bold; color: #cc6600; }
.stat-label { font-size: 0.7rem; color: #666; text-transform: uppercase; margin-top: 4px; }

/* Log */
.log-panel {
    background: #0d0d0d; border: 1px solid #222; border-radius: 4px;
    padding: 10px; max-height: 200px; overflow-y: auto; font-size: 0.8rem;
}
.log-entry { padding: 2px 0; color: #888; }
.log-entry.event-task_completed { color: #00ff66; }
.log-entry.event-task_failed { color: #ff6666; }
.log-entry.event-task_assigned { color: #66aaff; }
.log-entry.event-agent_waiting { color: #cccc66; }

.archive-btn {
    background: #1a1a2a !important; border: 1px solid #334466 !important; color: #6688cc !important;
}
.archive-btn:hover { background: #2a2a3a !important; color: #88aaee !important; }
.job-log-toggle {
    background: none; border: 1px solid #333; color: #888; padding: 4px 12px;
    border-radius: 3px; cursor: pointer; font-size: 0.75rem; font-family: inherit; margin-left: 8px;
}
.job-log-toggle:hover { border-color: #666; color: #ccc; }
.job-log-card {
    background: #0d0d0d; border: 1px solid #1a1a1a; border-radius: 4px;
    padding: 10px 14px; margin-bottom: 6px;
}
.job-log-card .card-title { font-size: 0.85rem; margin-bottom: 4px; }
.job-log-card .card-meta { font-size: 0.7rem; }
/* Hero quick-task form */
.hero-card {
    background: linear-gradient(135deg, #16213e, #1a1a2e);
    border: 1px solid #0f3460;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 24px;
}
.hero-card h2 {
    font-size: 1.2rem;
    color: #cc6600;
    margin-bottom: 16px;
}
.hero-fields {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 12px;
    align-items: start;
}
.hero-fields input, .hero-fields textarea {
    background: #0d0d1a;
    border: 1px solid #0f3460;
    color: #fff;
    padding: 12px 16px;
    border-radius: 6px;
    font-family: inherit;
    font-size: 0.95rem;
    width: 100%;
}
.hero-fields input:focus, .hero-fields textarea:focus {
    outline: none;
    border-color: #cc6600;
    box-shadow: 0 0 8px rgba(204,102,0,0.3);
}
.hero-fields textarea {
    min-height: 80px;
    resize: vertical;
}
.hero-submit {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
}
.hero-submit button {
    background: linear-gradient(135deg, #cc6600, #dd7700);
    color: #fff;
    border: none;
    padding: 12px 32px;
    border-radius: 6px;
    cursor: pointer;
    font-family: inherit;
    font-size: 1rem;
    font-weight: bold;
    letter-spacing: 0.5px;
    transition: background 0.2s, box-shadow 0.2s;
}
.hero-submit button:hover {
    background: linear-gradient(135deg, #dd7700, #ee8800);
    box-shadow: 0 0 12px rgba(204,102,0,0.4);
}

.empty { text-align: center; padding: 30px; color: #444; }
.subtask-card { border-left: 3px solid #444; margin-left: 16px; }
.parent-card { border-left: 3px solid #cc6600; }
.planning-spinner { display: inline-block; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.review-badge { background: #3a3a1a; border: 1px solid #666600; border-radius: 4px; padding: 4px 8px; font-size: 0.75rem; color: #ddcc44; }
.subtask-progress { font-size: 0.8rem; color: #888; margin-left: 8px; }

/* Pipeline indicator */
.pipeline { display: flex; align-items: center; gap: 0; margin: 8px 0 6px 0; }
.pipeline-phase {
    display: flex; align-items: center; gap: 4px; font-size: 0.7rem;
    color: #555; padding: 3px 8px; position: relative;
}
.pipeline-phase .phase-dot {
    width: 8px; height: 8px; border-radius: 50%; background: #333; border: 1px solid #555;
}
.pipeline-phase.done .phase-dot { background: #1a5a1a; border-color: #338833; }
.pipeline-phase.active .phase-dot { background: #cc6600; border-color: #ff8800; box-shadow: 0 0 6px #cc6600; }
.pipeline-phase.active { color: #ff9900; font-weight: bold; }
.pipeline-phase.done { color: #448844; }
.pipeline-phase.failed .phase-dot { background: #aa2222; border-color: #ff4444; box-shadow: 0 0 6px #aa2222; }
.pipeline-phase.failed { color: #ff4444; font-weight: bold; }
.pipeline-connector { width: 16px; height: 2px; background: #333; }
.pipeline-connector.done { background: #338833; }
.pipeline-connector.active { background: #cc6600; }

/* PR contribution card */
.pr-card {
    background: linear-gradient(135deg, #0a1a0a, #0d2a0d);
    border: 2px solid #338833; border-radius: 8px;
    padding: 14px; margin-top: 10px;
}
.pr-card a.pr-link {
    display: inline-block; background: linear-gradient(135deg, #228822, #33aa33);
    color: #fff; padding: 8px 20px; border-radius: 6px; text-decoration: none;
    font-weight: bold; font-size: 0.9rem; transition: box-shadow 0.2s;
}
.pr-card a.pr-link:hover { box-shadow: 0 0 12px rgba(50,170,50,0.5); }
.pr-card .pr-summary { color: #88cc88; font-size: 0.85rem; margin-top: 8px; }
.pr-card .pr-subtask { font-size: 0.8rem; color: #668866; padding: 2px 0; }

/* Galactic marketplace */
.galactic-section h2 { color: #8866cc !important; border-bottom-color: #442266 !important; }
.galactic-card {
    background: linear-gradient(135deg, #10102a, #1a1a3a);
    border: 1px solid #333366; border-radius: 6px; padding: 14px;
}
.galactic-card:hover { border-color: #5555aa; }
.galactic-card .offering-agents {
    font-size: 1.3rem; font-weight: bold;
    background: linear-gradient(90deg, #8866cc, #aa88ff);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.galactic-card .offering-price { font-size: 0.85rem; color: #8888cc; }
.galactic-card .offering-node { font-size: 0.8rem; color: #666699; }
.wallet-badge {
    background: linear-gradient(135deg, #1a1a3a, #2a2a4a);
    border: 1px solid #444488; border-radius: 4px; padding: 4px 10px;
    font-size: 0.8rem; color: #aa88ff;
}
.tribute-row { font-size: 0.8rem; padding: 6px 0; border-bottom: 1px solid #1a1a2a; }
.tribute-status-pending { color: #cccc66; }
.tribute-status-accepted { color: #66cc66; }
.tribute-status-rejected { color: #cc6666; }
.tribute-status-completed { color: #88ccff; }

/* Bounty board */
.bounty-section h2 { color: #ff8844 !important; border-bottom-color: #442200 !important; }
.bounty-card {
    background: linear-gradient(135deg, #1a1008, #2a1a0a);
    border: 1px solid #443322; border-radius: 6px; padding: 14px;
}
.bounty-card:hover { border-color: #886644; }
.bounty-card .bounty-reward {
    font-size: 1.2rem; font-weight: bold;
    background: linear-gradient(90deg, #ff8844, #ffaa66);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.bounty-card .bounty-title { font-size: 0.95rem; color: #ddd; margin: 4px 0; }
.bounty-card .bounty-desc { font-size: 0.8rem; color: #999; max-height: 40px; overflow: hidden; }
.bounty-card .bounty-caps { font-size: 0.75rem; color: #886644; margin-top: 4px; }
.bounty-card .bounty-caps span {
    background: #1a0d00; border: 1px solid #332211; border-radius: 2px;
    padding: 1px 6px; margin-right: 4px; display: inline-block;
}
.bounty-card .bounty-meta { font-size: 0.7rem; color: #665544; margin-top: 6px; }
.bounty-status-open { color: #88cc44; }
.bounty-status-claimed { color: #66aaff; }
.bounty-status-completed { color: #44cc44; }
.bounty-status-failed { color: #cc4444; }
.bounty-status-cancelled { color: #888; }
.bounty-status-expired { color: #666; }

/* Capability/reputation cards */
.capability-section h2 { color: #44aacc !important; border-bottom-color: #1a3344 !important; }
.capability-card {
    background: linear-gradient(135deg, #081a20, #0d2a30);
    border: 1px solid #224444; border-radius: 6px; padding: 14px;
}
.capability-card:hover { border-color: #448888; }
.capability-card .cap-node {
    font-size: 1rem; font-weight: bold; color: #66cccc;
}
.capability-card .cap-swarm { font-size: 0.8rem; color: #448888; }
.capability-card .cap-agents { font-size: 0.85rem; color: #88bbbb; margin-top: 4px; }
.capability-card .cap-stats { font-size: 0.8rem; color: #668888; margin-top: 4px; }
.rep-bar {
    display: inline-block; height: 8px; border-radius: 4px; margin-left: 6px; vertical-align: middle;
}
.rep-bar-fill { height: 100%; border-radius: 4px; }
.cap-tags { font-size: 0.75rem; color: #448888; margin-top: 4px; }
.cap-tags span {
    background: #0a1a1a; border: 1px solid #224444; border-radius: 2px;
    padding: 1px 6px; margin-right: 4px; display: inline-block;
}

/* Swarm history */
.history-section h2 { color: #aa88cc !important; border-bottom-color: #332244 !important; }
.history-row {
    display: flex; align-items: center; gap: 12px; padding: 8px 12px;
    background: #0d0d1a; border: 1px solid #1a1a2a; border-radius: 4px; margin-bottom: 4px;
    font-size: 0.8rem;
}
.history-row .history-icon { font-size: 1rem; flex-shrink: 0; }
.history-row .history-info { flex: 1; color: #aaa; }
.history-row .history-time { font-size: 0.7rem; color: #555; flex-shrink: 0; }

/* Tab navigation for marketplace */
.marketplace-tabs {
    display: flex; gap: 0; margin-bottom: 16px; border-bottom: 2px solid #222;
}
.marketplace-tab {
    background: none; border: none; border-bottom: 2px solid transparent;
    color: #666; padding: 8px 16px; cursor: pointer; font-family: inherit;
    font-size: 0.85rem; margin-bottom: -2px; transition: all 0.2s;
}
.marketplace-tab:hover { color: #aaa; }
.marketplace-tab.active { color: #cc6600; border-bottom-color: #cc6600; }
.marketplace-panel { display: none; }
.marketplace-panel.active { display: block; }

/* Contributions (PR list) */
.contributions-section h2 { color: #33aa66 !important; border-bottom-color: #1a3a2a !important; }
.contribution-row {
    display: flex; align-items: center; gap: 12px; padding: 10px 14px;
    background: #0d1a0d; border: 1px solid #1a3a1a; border-radius: 6px; margin-bottom: 6px;
}
.contribution-row:hover { border-color: #338833; }
.contribution-row .pr-icon { font-size: 1.2rem; color: #33aa33; flex-shrink: 0; }
.contribution-row .pr-info { flex: 1; min-width: 0; }
.contribution-row .pr-title { font-size: 0.9rem; color: #ccc; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.contribution-row .pr-meta { font-size: 0.75rem; color: #668866; margin-top: 2px; }
.contribution-row a.pr-open {
    background: linear-gradient(135deg, #228822, #33aa33); color: #fff;
    padding: 5px 14px; border-radius: 4px; text-decoration: none;
    font-size: 0.8rem; font-weight: bold; white-space: nowrap; flex-shrink: 0;
}
.contribution-row a.pr-open:hover { box-shadow: 0 0 8px rgba(50,170,50,0.4); }

.emperor-banner {
    padding: 8px 24px; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;
}
.emperor-banner.online { background: #0a1a0a; color: #66cc66; border-bottom: 1px solid #1a3a1a; }
.emperor-banner.offline { background: #1a0a0a; color: #ff6666; border-bottom: 1px solid #3a1a1a; }
.emperor-banner.self { background: #0a0a1a; color: #66aaff; border-bottom: 1px solid #1a1a3a; }

/* Pane viewer modal */
.modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8);
    z-index: 100; justify-content: center; align-items: center;
}
.modal-overlay.active { display: flex; }
.modal {
    background: #111; border: 1px solid #444; border-radius: 8px;
    width: 80%; max-width: 900px; max-height: 80vh; overflow: hidden;
    display: flex; flex-direction: column;
}
.modal-header {
    padding: 12px 16px; border-bottom: 1px solid #333;
    display: flex; justify-content: space-between; align-items: center;
}
.modal-header h3 { font-size: 1rem; }
.modal-close { background: none; border: none; color: #888; cursor: pointer; font-size: 1.2rem; }
.modal-body {
    padding: 16px; overflow-y: auto; flex: 1;
    font-family: 'Courier New', monospace; font-size: 0.85rem;
    white-space: pre-wrap; line-height: 1.4; color: #ccc; background: #0a0a0a;
}
</style>
</head>
<body>
<div class="header">
    <h1>VoidLux Swarm Emperor</h1>
    <div class="status-bar">
        <span><span class="dot dot-orange"></span> Node: <span id="node-id"></span></span>
        <span>Tasks: <span id="task-count">0</span></span>
        <span>Agents: <span id="agent-count">0</span></span>
        <span>WS: <span id="ws-status">connecting</span></span>
        <span class="wallet-badge" id="wallet-badge">-- VOID</span>
    </div>
</div>
<div class="emperor-banner self" id="emperor-banner">
    <span class="dot dot-green"></span>
    Emperor: <span id="emperor-id">this node</span>
    <span style="margin-left:auto;display:flex;gap:8px;">
        <button onclick="clearTasks()" style="background:#1a1a2a;border:1px solid #334466;color:#6688cc;padding:4px 12px;border-radius:3px;cursor:pointer;font-family:inherit;font-size:0.75rem;">Clear Tasks</button>
        <button onclick="killPopulation()" style="background:#3a1a1a;border:1px solid #663333;color:#ff6666;padding:4px 12px;border-radius:3px;cursor:pointer;font-family:inherit;font-size:0.75rem;">Kill Population</button>
        <button onclick="regicide()" style="background:#3a0a0a;border:1px solid #882222;color:#ff3333;padding:4px 12px;border-radius:3px;cursor:pointer;font-family:inherit;font-size:0.75rem;">Regicide</button>
    </span>
</div>

<div class="main">
    <div class="hero-card">
        <h2>Quick Task <select id="hero-history" onchange="loadHeroHistory(this)" style="background:#0d0d1a;border:1px solid #0f3460;color:#888;padding:4px 8px;border-radius:4px;font-family:inherit;font-size:0.75rem;margin-left:8px;max-width:300px;"><option value="">-- recent --</option></select></h2>
        <form class="hero-fields" id="hero-form" onsubmit="deploySwarm(event)">
            <input type="text" name="repo_url" placeholder="git@github.com:user/repo.git" required />
            <textarea name="instructions" placeholder="What should the swarm do?" required></textarea>
            <div class="hero-submit"><button type="submit">Deploy Swarm</button></div>
        </form>
    </div>

    <div class="section">
        <div class="stats" id="stats">
            <div class="stat"><div class="stat-value" id="stat-pending">0</div><div class="stat-label">Pending</div></div>
            <div class="stat"><div class="stat-value" id="stat-planning">0</div><div class="stat-label">Planning</div></div>
            <div class="stat"><div class="stat-value" id="stat-active">0</div><div class="stat-label">Active</div></div>
            <div class="stat"><div class="stat-value" id="stat-review">0</div><div class="stat-label">Review</div></div>
            <div class="stat"><div class="stat-value" id="stat-merging">0</div><div class="stat-label">Merging</div></div>
            <div class="stat"><div class="stat-value" id="stat-completed">0</div><div class="stat-label">Completed</div></div>
            <div class="stat"><div class="stat-value" id="stat-failed">0</div><div class="stat-label">Failed</div></div>
            <div class="stat"><div class="stat-value" id="stat-agents">0</div><div class="stat-label">Agents</div></div>
        </div>
    </div>

    <div class="section contributions-section" id="contributions-section" style="display:none;">
        <h2>Contributions</h2>
        <div id="contributions-list"></div>
    </div>

    <div class="section">
        <h2>Create Task <button class="toggle-btn" onclick="toggleForm()">expand</button></h2>
        <form class="task-form" id="task-form" onsubmit="createTask(event)">
            <input type="text" name="title" placeholder="Task title" required />
            <input type="text" name="description" placeholder="Description" />
            <button type="submit">Create</button>
        </form>
        <div id="form-extra" style="display:none; margin-bottom:16px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px;">
                <input type="text" form="task-form" name="project_path" id="task-project-path" placeholder="Project path" style="background:#1a1a1a;border:1px solid #333;color:#fff;padding:8px 12px;border-radius:4px;font-family:inherit;" />
                <input type="text" form="task-form" name="capabilities" placeholder="Capabilities (comma-sep)" style="background:#1a1a1a;border:1px solid #333;color:#fff;padding:8px 12px;border-radius:4px;font-family:inherit;" />
                <input type="number" form="task-form" name="priority" placeholder="Priority (0)" value="0" style="background:#1a1a1a;border:1px solid #333;color:#fff;padding:8px 12px;border-radius:4px;font-family:inherit;" />
                <textarea form="task-form" name="context" placeholder="Additional context" style="background:#1a1a1a;border:1px solid #333;color:#fff;padding:8px 12px;border-radius:4px;font-family:inherit;min-height:60px;"></textarea>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Tasks <button class="archive-btn" onclick="archiveAll()" style="font-size:0.7rem;padding:3px 10px;border-radius:3px;cursor:pointer;font-family:inherit;" id="archive-all-btn">Archive All</button></h2>
        <div class="card-grid" id="task-list">
            <div class="empty">No tasks yet</div>
        </div>
    </div>

    <div class="section" id="job-log-section" style="display:none;">
        <h2>Job Log <button class="job-log-toggle" id="job-log-toggle" onclick="toggleJobLog()">show</button> <span style="font-size:0.75rem;color:#666;" id="job-log-count"></span></h2>
        <div id="job-log-list" style="display:none;"></div>
    </div>

    <div class="section">
        <h2>Register Agents</h2>
        <form class="task-form" id="bulk-agent-form" onsubmit="bulkRegister(event)" style="grid-template-columns: 80px 1fr 120px 160px auto;">
            <input type="number" name="count" value="5" min="1" max="50" title="Count" />
            <input type="text" name="project_path" id="agent-project-path" placeholder="Project path" />
            <select name="tool" onchange="toggleModelField(this)" style="background:#1a1a1a;border:1px solid #333;color:#fff;padding:8px 12px;border-radius:4px;font-family:inherit;">
                <option value="claude">claude (API)</option>
                <option value="claude-ollama">claude (Ollama)</option>
                <option value="opencode">opencode</option>
            </select>
            <input type="text" name="model" id="model-field" placeholder="Model (optional)" style="background:#1a1a1a;border:1px solid #333;color:#fff;padding:8px 12px;border-radius:4px;font-family:inherit;display:none;" />
            <button type="submit">Register</button>
        </form>
    </div>

    <div class="section">
        <h2>Agents</h2>
        <div class="card-grid" id="agent-list">
            <div class="empty">No agents registered</div>
        </div>
    </div>

    <div class="section galactic-section">
        <h2>Galactic Marketplace</h2>
        <div class="marketplace-tabs">
            <button class="marketplace-tab active" onclick="switchMarketTab('bounties')">Bounty Board</button>
            <button class="marketplace-tab" onclick="switchMarketTab('capabilities')">Capabilities</button>
            <button class="marketplace-tab" onclick="switchMarketTab('offerings')">Offerings</button>
            <button class="marketplace-tab" onclick="switchMarketTab('history')">Swarm History</button>
        </div>

        <div class="marketplace-panel active" id="panel-bounties">
            <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;">
                <select id="bounty-filter" onchange="renderBounties()" style="background:#1a1a1a;border:1px solid #333;color:#888;padding:4px 8px;border-radius:3px;font-size:0.75rem;font-family:inherit;">
                    <option value="">All Bounties</option>
                    <option value="open">Open</option>
                    <option value="claimed">Claimed</option>
                    <option value="completed">Completed</option>
                </select>
                <button class="toggle-btn" onclick="refreshBounties()" style="border-color:#443322;color:#ff8844;">Refresh</button>
                <button class="toggle-btn" onclick="showPostBountyForm()" style="border-color:#443322;color:#ff8844;">Post Bounty</button>
            </div>
            <div id="post-bounty-form" style="display:none;margin-bottom:14px;">
                <form onsubmit="postBounty(event)" class="task-form expanded">
                    <input name="title" placeholder="Bounty title..." required style="background:#1a0d00;border-color:#332211;">
                    <textarea name="description" placeholder="Description..." rows="2" style="background:#1a0d00;border-color:#332211;"></textarea>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input name="reward" type="number" min="1" value="10" placeholder="Reward" style="width:80px;background:#1a0d00;border-color:#332211;">
                        <span style="color:#886644;font-size:0.8rem;">VOID</span>
                        <input name="capabilities" placeholder="Required capabilities (comma-sep)" style="flex:1;background:#1a0d00;border-color:#332211;">
                        <input name="ttl" type="number" min="60" value="600" placeholder="TTL (sec)" style="width:100px;background:#1a0d00;border-color:#332211;">
                        <button type="submit" style="background:#663300;">Post</button>
                        <button type="button" onclick="document.getElementById('post-bounty-form').style.display='none'" style="background:#333;">Cancel</button>
                    </div>
                </form>
            </div>
            <div class="card-grid" id="bounty-list">
                <div class="empty" style="color:#443322;">No bounties available</div>
            </div>
        </div>

        <div class="marketplace-panel" id="panel-capabilities">
            <div style="margin-bottom:12px;">
                <button class="toggle-btn" onclick="refreshCapabilities()" style="border-color:#224444;color:#44aacc;">Refresh</button>
            </div>
            <div class="card-grid" id="capability-list">
                <div class="empty" style="color:#224444;">No capability profiles advertised</div>
            </div>
        </div>

        <div class="marketplace-panel" id="panel-offerings">
            <div style="margin-bottom:12px;">
                <button class="toggle-btn" onclick="makeOffering()" style="border-color:#444488;color:#8866cc;">Make Offering</button>
            </div>
            <div class="card-grid" id="offerings-list">
                <div class="empty" style="color:#444466;">No offerings from other nodes</div>
            </div>
            <div id="tributes-section" style="margin-top:12px;display:none;">
                <h3 style="font-size:0.9rem;color:#8866cc;margin-bottom:8px;">Tribute History</h3>
                <div id="tributes-list"></div>
            </div>
        </div>

        <div class="marketplace-panel" id="panel-history">
            <div id="swarm-history-list">
                <div class="empty" style="color:#332244;">No completion history yet</div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Event Log</h2>
        <div class="log-panel" id="log-panel"></div>
    </div>
</div>

<div class="modal-overlay" id="pane-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modal-title">Agent Output</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
    </div>
</div>

<script>
HTML
        . "\nconst NODE_ID = {$nodeIdJs};\nconst WORKBENCH = {$workbenchJs};\n" . <<<'JS'
document.getElementById('node-id').textContent = NODE_ID.substring(0, 8);
document.getElementById('emperor-id').textContent = NODE_ID.substring(0, 12) + ' (this node)';
document.getElementById('task-project-path').value = WORKBENCH;
document.getElementById('agent-project-path').value = WORKBENCH;

// ---- Client-side state (populated entirely via WebSocket) ----
let state = { tasks: {}, agents: {}, status: {}, offerings: [], tributes: [], wallet: {balance:0,currency:'VOID'} };

let emperorNodeId = NODE_ID;
function updateEmperorBanner(empId) {
    const banner = document.getElementById('emperor-banner');
    const label = document.getElementById('emperor-id');
    if (!empId) {
        banner.className = 'emperor-banner offline';
        banner.querySelector('.dot').className = 'dot dot-orange';
        label.textContent = 'unknown (offline?)';
        return;
    }
    emperorNodeId = empId;
    if (empId === NODE_ID) {
        banner.className = 'emperor-banner self';
        banner.querySelector('.dot').className = 'dot dot-green';
        label.textContent = empId.substring(0, 12) + ' (this node)';
    } else {
        banner.className = 'emperor-banner online';
        banner.querySelector('.dot').className = 'dot dot-green';
        label.textContent = empId.substring(0, 12);
    }
}

let expanded = false;
function toggleForm() {
    expanded = !expanded;
    document.getElementById('form-extra').style.display = expanded ? 'block' : 'none';
    document.querySelector('.toggle-btn').textContent = expanded ? 'collapse' : 'expand';
}

function escapeHtml(t) { const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

function statusBadge(status) {
    return '<span class="card-status status-'+status+'">'+status+'</span>';
}

function getPipelinePhase(t, children) {
    if (t.status === 'failed') return -1;
    if (t.status === 'completed') return 5;
    if (t.status === 'merging') return 4;
    if (children.some(c => c.status === 'pending_review')) return 3;
    if (children.some(c => ['claimed','in_progress','waiting_input'].includes(c.status))) return 2;
    if (t.status === 'planning') return 1;
    if (t.status === 'in_progress' && children.length) return 2;
    return 0;
}

function renderPipeline(phase) {
    const phases = [{n:'Planning',i:1},{n:'Working',i:2},{n:'Reviewing',i:3},{n:'Merging',i:4},{n:'Done',i:5}];
    let html = '<div class="pipeline">';
    phases.forEach((p, idx) => {
        let cls = 'pipeline-phase';
        if (phase === -1) { cls += ' failed'; }
        else if (p.i < phase) cls += ' done';
        else if (p.i === phase) cls += ' active';
        html += '<div class="'+cls+'"><span class="phase-dot"></span> '+p.n+'</div>';
        if (idx < phases.length - 1) {
            let cCls = 'pipeline-connector';
            if (phase !== -1 && p.i < phase) cCls += ' done';
            else if (phase !== -1 && p.i === phase) cCls += ' active';
            html += '<div class="'+cCls+'"></div>';
        }
    });
    if (phase === -1) html += '<div class="pipeline-phase failed"><span class="phase-dot"></span> Failed</div>';
    html += '</div>';
    return html;
}

function renderPrCard(t, children) {
    const prMatch = t.result?.match(/PR: (https?:\/\/\S+)/);
    if (!prMatch) return '';
    const prUrl = prMatch[1];
    let html = '<div class="pr-card">';
    html += '<a class="pr-link" href="'+escapeHtml(prUrl)+'" target="_blank">View Pull Request</a>';
    const summaryMatch = t.result?.match(/^(.+?)(?:\n|$)/);
    if (summaryMatch) html += '<div class="pr-summary">'+escapeHtml(summaryMatch[1])+'</div>';
    if (children.length) {
        html += '<div style="margin-top:6px;">';
        children.forEach(c => {
            const icon = c.status === 'completed' ? '&#10003;' : '&#10007;';
            const color = c.status === 'completed' ? '#66cc66' : '#cc6666';
            html += '<div class="pr-subtask"><span style="color:'+color+'">'+icon+'</span> '+escapeHtml(c.title)+'</div>';
        });
        html += '</div>';
    }
    html += '</div>';
    return html;
}

function renderTask(t, isSubtask) {
    const agent = state.agents[t.assigned_to];
    const agentName = t.assigned_to ? (agent?.name || t.assigned_to.substring(0,8)) : null;
    const isActive = t.status === 'claimed' || t.status === 'in_progress' || t.status === 'waiting_input' || t.status === 'merging';
    const children = getTaskChildren(t.id);
    const isParent = t.parent_id === null && children.length;
    const cardClass = isSubtask ? 'card subtask-card' : (isParent ? 'card parent-card' : 'card');
    let html = '<div class="'+cardClass+'" id="task-'+t.id+'">';

    if (t.status === 'planning') {
        html += '<div class="card-title">'+escapeHtml(t.title)+' '+statusBadge(t.status)+' <span class="planning-spinner" style="font-size:0.8rem;">&#9881;</span></div>';
        html += '<div style="font-size:0.85rem;color:#bb88ff;margin:4px 0;">Emperor is analyzing and decomposing this request...</div>';
    } else if (t.status === 'merging') {
        html += '<div class="card-title">'+escapeHtml(t.title)+' '+statusBadge(t.status)+' <span class="planning-spinner" style="font-size:0.8rem;">&#9881;</span></div>';
        const attempt = t.merge_attempts || 0;
        html += '<div style="font-size:0.85rem;color:#aa88ff;margin:4px 0;">Merging subtask branches and running tests...' + (attempt > 0 ? ' (attempt '+attempt+'/3)' : '') + '</div>';
    } else {
        html += '<div class="card-title">'+escapeHtml(t.title)+' '+statusBadge(t.status)+'</div>';
    }

    if (isParent && !isSubtask) {
        html += renderPipeline(getPipelinePhase(t, children));
        const done = children.filter(c => c.status === 'completed').length;
        html += '<div class="subtask-progress">Subtasks: '+done+'/'+children.length+' completed</div>';
    }

    if (t.description) html += '<div style="font-size:0.85rem;color:#aaa;margin-bottom:4px;">'+escapeHtml(t.description).substring(0,120)+'</div>';
    if (agentName) html += '<div style="font-size:0.8rem;color:#668;" title="'+t.assigned_to+'">Agent: '+escapeHtml(agentName)+'</div>';

    if (t.status === 'pending_review') {
        html += '<div style="margin:6px 0;"><span class="review-badge">Awaiting Review</span></div>';
        html += '<div class="card-actions"><button onclick="reviewTask(\''+t.id+'\',true)" style="background:#1a3a1a;border:1px solid #336633;color:#66cc66;">Accept</button>';
        html += '<button onclick="reviewTask(\''+t.id+'\',false)" style="background:#3a1a1a;border:1px solid #663333;color:#ff6666;">Reject</button></div>';
    }
    if (t.review_status === 'rejected' && t.review_feedback) {
        html += '<div style="background:#3a1a1a;border:1px solid #663333;border-radius:4px;padding:6px 10px;margin:6px 0;color:#ff8888;font-size:0.8rem;"><strong>Review Feedback:</strong> '+escapeHtml(t.review_feedback).substring(0,200)+'</div>';
    }

    if (t.status === 'waiting_input' && t.progress) {
        html += '<div style="background:#3a2a1a;border:1px solid #664400;border-radius:4px;padding:8px 12px;margin:6px 0;color:#ffaa33;font-size:0.85rem;"><strong>Needs Input:</strong> '+escapeHtml(t.progress)+'</div>';
    } else if (t.progress) {
        html += '<div style="font-size:0.8rem;color:#686;">'+escapeHtml(t.progress).substring(0,80)+'</div>';
    }
    if (t.result) {
        const preview = escapeHtml(t.result).substring(0,200);
        html += '<div class="task-result" style="font-size:0.8rem;color:#6a6;background:#0a0a0a;padding:8px;border-radius:4px;margin-top:6px;white-space:pre-wrap;max-height:120px;overflow-y:auto;">'+preview+'</div>';
    }
    if (t.error) html += '<div style="font-size:0.8rem;color:#a66;">Error: '+escapeHtml(t.error).substring(0,100)+'</div>';

    if (t.work_instructions || t.acceptance_criteria) {
        html += '<details style="margin-top:6px;font-size:0.8rem;"><summary style="cursor:pointer;color:#888;">Work Details</summary>';
        if (t.work_instructions) html += '<div style="color:#aaa;margin:4px 0;"><strong>Instructions:</strong> '+escapeHtml(t.work_instructions).substring(0,300)+'</div>';
        if (t.acceptance_criteria) html += '<div style="color:#aaa;"><strong>Criteria:</strong> '+escapeHtml(t.acceptance_criteria).substring(0,200)+'</div>';
        html += '</details>';
    }

    if (t.git_branch) html += '<div style="font-size:0.8rem;color:#8888cc;margin-top:4px;">Branch: <code>'+escapeHtml(t.git_branch)+'</code></div>';
    if (isParent && t.status === 'completed' && t.result?.match(/PR: (https?:\/\/\S+)/)) {
        html += renderPrCard(t, children);
    } else if (t.result && t.result.match(/PR: (https?:\/\/\S+)/)) {
        const prUrl = t.result.match(/PR: (https?:\/\/\S+)/)[1];
        html += '<div style="font-size:0.8rem;margin-top:4px;"><a href="'+escapeHtml(prUrl)+'" target="_blank" style="color:#66aaff;text-decoration:underline;">View Pull Request</a></div>';
    }
    html += '<div class="card-meta">ts:'+t.lamport_ts+' | '+t.created_at+'</div>';
    html += '<div class="card-actions">';
    if (isActive && t.assigned_to) {
        html += '<button onclick="viewTaskOutput(\''+t.assigned_to+'\',\''+escapeHtml(t.title).replace(/'/g,"\\'")+'\')">Live Output</button>';
    }
    if ((t.status === 'completed' || t.status === 'failed') && (t.result || t.error)) {
        html += '<button onclick="viewTaskResult(\''+t.id+'\',\''+escapeHtml(t.title).replace(/'/g,"\\'")+'\')">View Result</button>';
    }
    if (isActive || t.status === 'pending_review') {
        html += '<button onclick="cancelTask(\''+t.id+'\')">Cancel</button>';
    }
    if (t.status === 'completed' || t.status === 'failed' || t.status === 'cancelled') {
        html += '<button class="archive-btn" onclick="archiveTask(\''+t.id+'\')">Archive</button>';
    }
    html += '</div>';
    if (t.status === 'waiting_input') {
        const replyAgent = t.assigned_to || '';
        const borderColor = replyAgent ? '#664400' : '#444';
        const btnBg = replyAgent ? '#3a2a1a' : '#222';
        const btnColor = replyAgent ? '#ffaa33' : '#888';
        html += '<div class="task-refine" style="margin-top:8px;display:flex;gap:6px;">';
        html += '<input type="text" id="refine-'+t.id+'" placeholder="Reply to agent..." style="flex:1;background:#1a1a1a;border:1px solid '+borderColor+';color:#fff;padding:8px 10px;border-radius:4px;font-family:inherit;font-size:0.85rem;" onkeydown="if(event.key===\'Enter\')sendRefine(\''+replyAgent+'\',\''+t.id+'\')" />';
        html += '<button onclick="sendRefine(\''+replyAgent+'\',\''+t.id+'\')" style="background:'+btnBg+';border:1px solid '+borderColor+';color:'+btnColor+';padding:8px 14px;border-radius:4px;cursor:pointer;font-family:inherit;font-size:0.85rem;font-weight:bold;">Reply</button>';
        html += '</div>';
        if (!replyAgent) html += '<div style="font-size:0.7rem;color:#664400;margin-top:4px;">No agent assigned — reply will not be delivered</div>';
    } else if (isActive && t.assigned_to) {
        html += '<div class="task-refine" style="margin-top:8px;display:flex;gap:6px;">';
        html += '<input type="text" id="refine-'+t.id+'" placeholder="Send follow-up instructions..." style="flex:1;background:#1a1a1a;border:1px solid #333;color:#fff;padding:6px 10px;border-radius:4px;font-family:inherit;font-size:0.8rem;" onkeydown="if(event.key===\'Enter\')sendRefine(\''+t.assigned_to+'\',\''+t.id+'\')" />';
        html += '<button onclick="sendRefine(\''+t.assigned_to+'\',\''+t.id+'\')" style="background:#1a3a1a;border:1px solid #336633;color:#66cc66;padding:6px 12px;border-radius:4px;cursor:pointer;font-family:inherit;font-size:0.8rem;">Send</button>';
        html += '</div>';
    }

    if (!isSubtask && children.length) {
        html += '<div style="margin-top:8px;">';
        children.forEach(sub => { html += renderTask(sub, true); });
        html += '</div>';
    }

    html += '</div>';
    return html;
}

function getTaskChildren(parentId) {
    return Object.values(state.tasks).filter(t => t.parent_id === parentId);
}

function renderAgent(a) {
    const shortPath = a.project_path ? a.project_path.replace(/^\/home\/[^/]+\//, '~/') : '';
    let html = '<div class="card" id="agent-'+a.id+'">';
    html += '<div class="card-title" title="Agent ID: '+a.id+'">'+escapeHtml(a.name)+' '+statusBadge(a.status)+'</div>';
    html += '<div style="font-size:0.85rem;color:#aaa;">Tool: '+a.tool;
    if (a.model) html += ' | Model: '+escapeHtml(a.model);
    html += ' | <span title="Worker node running this agent\'s tmux session (full: '+a.node_id+')">Node: '+a.node_id.substring(0,8)+'</span></div>';
    if (shortPath) html += '<div style="font-size:0.8rem;color:#698;" title="'+escapeHtml(a.project_path)+'">Path: '+escapeHtml(shortPath)+'</div>';
    if (a.capabilities && a.capabilities.length) html += '<div style="font-size:0.8rem;color:#686;">Caps: '+a.capabilities.join(', ')+'</div>';
    if (a.current_task_id) html += '<div style="font-size:0.8rem;color:#668;">Task: '+a.current_task_id.substring(0,8)+'</div>';
    html += '<div class="card-meta" title="tmux session name for this agent">Session: '+(a.tmux_session_id||'none')+'</div>';
    html += '<div class="card-actions">';
    html += '<button onclick="viewOutput(\''+a.id+'\',\''+escapeHtml(a.name)+'\')">View Output</button>';
    html += '<button onclick="changeDir(\''+a.id+'\',\''+escapeHtml(a.name)+'\')" title="Send /cd to change working directory">cd</button>';
    html += '<button onclick="deregisterAgent(\''+a.id+'\')">Remove</button>';
    html += '</div></div>';
    return html;
}

function addLog(event, msg) {
    const panel = document.getElementById('log-panel');
    const time = new Date().toLocaleTimeString();
    panel.innerHTML = '<div class="log-entry event-'+event+'">['+ time +'] '+escapeHtml(msg)+'</div>' + panel.innerHTML;
    if (panel.children.length > 100) panel.removeChild(panel.lastChild);
}

// Save/restore focused input state across DOM replacement
function saveFocusState() {
    const active = document.activeElement;
    if (active && active.tagName === 'INPUT' && active.id) {
        return { id: active.id, value: active.value, selStart: active.selectionStart, selEnd: active.selectionEnd };
    }
    return null;
}
function restoreFocusState(fs) {
    if (!fs) return;
    const el = document.getElementById(fs.id);
    if (el && el.tagName === 'INPUT') {
        el.value = fs.value;
        el.focus();
        try { el.setSelectionRange(fs.selStart, fs.selEnd); } catch(e) {}
    }
}

// ---- Render everything from client-side state ----
function computeStats() {
    const tasks = Object.values(state.tasks).filter(t => !t.archived);
    let pending=0, planning=0, claimed=0, in_progress=0, waiting_input=0, pending_review=0, merging=0, completed=0, failed=0;
    tasks.forEach(t => {
        switch(t.status) {
            case 'pending': pending++; break;
            case 'planning': planning++; break;
            case 'claimed': claimed++; break;
            case 'in_progress': in_progress++; break;
            case 'waiting_input': waiting_input++; break;
            case 'pending_review': pending_review++; break;
            case 'merging': merging++; break;
            case 'completed': completed++; break;
            case 'failed': failed++; break;
        }
    });
    const agentCount = Object.keys(state.agents).length;
    document.getElementById('task-count').textContent = tasks.length;
    document.getElementById('agent-count').textContent = agentCount;
    document.getElementById('stat-pending').textContent = pending;
    document.getElementById('stat-planning').textContent = planning;
    document.getElementById('stat-active').textContent = claimed + in_progress + waiting_input;
    document.getElementById('stat-review').textContent = pending_review;
    document.getElementById('stat-merging').textContent = merging;
    document.getElementById('stat-completed').textContent = completed;
    document.getElementById('stat-failed').textContent = failed;
    document.getElementById('stat-agents').textContent = agentCount;
}

function renderAll() {
    computeStats();

    // Tasks — top-level only (subtasks rendered inline), exclude archived
    const tasks = Object.values(state.tasks);
    const topLevel = tasks.filter(t => !t.parent_id && !t.archived);
    const taskEl = document.getElementById('task-list');
    const focusState = saveFocusState();
    taskEl.innerHTML = topLevel.length ? topLevel.map(t => renderTask(t, false)).join('') : '<div class="empty">No tasks yet</div>';
    restoreFocusState(focusState);

    // Show/hide Archive All button based on whether terminal tasks exist
    const hasTerminal = tasks.some(t => !t.archived && (t.status === 'completed' || t.status === 'failed' || t.status === 'cancelled'));
    document.getElementById('archive-all-btn').style.display = hasTerminal ? '' : 'none';

    // Job Log
    renderJobLog();

    // Agents
    const agents = Object.values(state.agents);
    const agentEl = document.getElementById('agent-list');
    if (!agentEl.contains(document.activeElement)) {
        agentEl.innerHTML = agents.length ? agents.map(renderAgent).join('') : '<div class="empty">No agents registered</div>';
    }

    // Contributions (PRs)
    renderContributions();

    // Galactic marketplace
    renderGalactic();
}

function renderJobLog() {
    const archived = Object.values(state.tasks).filter(t => t.archived);
    const section = document.getElementById('job-log-section');
    const countEl = document.getElementById('job-log-count');
    const listEl = document.getElementById('job-log-list');

    if (archived.length === 0) {
        section.style.display = 'none';
        return;
    }

    section.style.display = '';
    countEl.textContent = '(' + archived.length + ')';

    if (listEl.style.display === 'none') return; // collapsed, skip rendering

    // Sort by completed_at descending (most recent first)
    archived.sort((a,b) => (b.completed_at || b.updated_at || '').localeCompare(a.completed_at || a.updated_at || ''));

    let html = '';
    archived.forEach(t => {
        html += '<div class="job-log-card">';
        html += '<div class="card-title">' + escapeHtml(t.title) + ' ' + statusBadge(t.status) + '</div>';
        if (t.result) {
            html += '<div style="font-size:0.8rem;color:#6a6;max-height:60px;overflow:hidden;white-space:pre-wrap;">' + escapeHtml(t.result).substring(0, 150) + '</div>';
        }
        if (t.error) {
            html += '<div style="font-size:0.8rem;color:#a66;">' + escapeHtml(t.error).substring(0, 100) + '</div>';
        }
        html += '<div class="card-meta">' + (t.completed_at || t.updated_at) + '</div>';
        html += '<div class="card-actions">';
        if (t.result || t.error) {
            html += '<button onclick="viewTaskResult(\'' + t.id + '\',\'' + escapeHtml(t.title).replace(/'/g, "\\'") + '\')">View Result</button>';
        }
        html += '</div></div>';
    });
    listEl.innerHTML = html;
}

let jobLogOpen = false;
function toggleJobLog() {
    jobLogOpen = !jobLogOpen;
    document.getElementById('job-log-list').style.display = jobLogOpen ? '' : 'none';
    document.getElementById('job-log-toggle').textContent = jobLogOpen ? 'hide' : 'show';
    if (jobLogOpen) renderJobLog();
}

let _renderTimer = null;
function scheduleRender() {
    if (_renderTimer) return;
    _renderTimer = setTimeout(() => { _renderTimer = null; renderAll(); }, 200);
}

// ---- User-initiated HTTP actions (no polling) ----
function createTask(e) {
    e.preventDefault();
    const f = e.target;
    const caps = (f.capabilities?.value||'').split(',').map(s=>s.trim()).filter(Boolean);
    const body = {
        title: f.title.value,
        description: f.description.value,
        priority: parseInt(f.priority?.value||'0'),
        project_path: f.project_path?.value||'',
        context: f.context?.value||'',
        required_capabilities: caps,
    };
    fetch('/api/swarm/tasks', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
    }).then(r=>r.json()).then(t => {
        f.title.value = '';
        f.description.value = '';
        addLog('task_created', 'Created task: '+t.title);
    });
}

function deploySwarm(e) {
    e.preventDefault();
    const f = e.target;
    const repoUrl = f.repo_url.value.trim();
    const instructions = f.instructions.value.trim();
    const firstSentence = instructions.match(/^[^.!?\n]+[.!?]?/)?.[0] || instructions;
    const title = firstSentence.substring(0, 80);
    const body = {
        title: title,
        description: instructions,
        project_path: repoUrl,
    };

    // Save to localStorage history
    saveHeroHistory(repoUrl, instructions);

    fetch('/api/swarm/tasks', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
    }).then(r=>r.json()).then(t => {
        f.instructions.value = '';
        addLog('task_created', 'Deployed: '+t.title);
    });
}

function getHeroHistory() {
    try { return JSON.parse(localStorage.getItem('voidlux_hero_history') || '[]'); }
    catch { return []; }
}

function saveHeroHistory(repoUrl, instructions) {
    const history = getHeroHistory();
    // Deduplicate by repo+instructions
    const exists = history.findIndex(h => h.repo === repoUrl && h.instructions === instructions);
    if (exists >= 0) history.splice(exists, 1);
    history.unshift({ repo: repoUrl, instructions: instructions, ts: new Date().toISOString() });
    // Keep last 20
    if (history.length > 20) history.length = 20;
    localStorage.setItem('voidlux_hero_history', JSON.stringify(history));
    renderHeroHistory();
}

function renderHeroHistory() {
    const sel = document.getElementById('hero-history');
    const history = getHeroHistory();
    sel.innerHTML = '<option value="">-- recent (' + history.length + ') --</option>';
    history.forEach((h, i) => {
        const label = (h.repo ? h.repo.replace(/^.*[:/]([^/]+\/[^/]+?)(?:\.git)?$/, '$1') + ': ' : '') + h.instructions.substring(0, 50);
        sel.innerHTML += '<option value="'+i+'">'+escapeHtml(label)+'</option>';
    });
}

function loadHeroHistory(sel) {
    const idx = parseInt(sel.value);
    if (isNaN(idx)) return;
    const history = getHeroHistory();
    const entry = history[idx];
    if (!entry) return;
    const form = document.getElementById('hero-form');
    form.repo_url.value = entry.repo || '';
    form.instructions.value = entry.instructions || '';
    sel.value = '';
}

// Restore last repo URL on page load and populate history dropdown
(function() {
    const history = getHeroHistory();
    if (history.length > 0) {
        document.getElementById('hero-form').repo_url.value = history[0].repo || '';
    }
    renderHeroHistory();
})();

let ollamaModels = null;
function toggleModelField(sel) {
    const modelField = document.getElementById('model-field');
    if (sel.value === 'claude-ollama') {
        modelField.style.display = '';
        if (!ollamaModels) {
            modelField.placeholder = 'Loading models...';
            fetch('/api/swarm/ollama/models').then(r=>r.json()).then(d => {
                ollamaModels = d.models || [];
                if (ollamaModels.length === 0) {
                    modelField.placeholder = 'No models found';
                    return;
                }
                const select = document.createElement('select');
                select.name = 'model';
                select.id = 'model-field';
                select.style.cssText = modelField.style.cssText;
                ollamaModels.forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m; opt.textContent = m;
                    select.appendChild(opt);
                });
                modelField.replaceWith(select);
            });
        }
    } else {
        modelField.style.display = 'none';
    }
}

function bulkRegister(e) {
    e.preventDefault();
    const f = e.target;
    const toolVal = f.tool.value;
    const isOllama = toolVal === 'claude-ollama';
    const body = {
        count: parseInt(f.count.value || '5'),
        tool: isOllama ? 'claude' : toolVal,
        project_path: f.project_path.value,
        name_prefix: 'agent',
        capabilities: [],
    };
    if (isOllama) {
        body.env = {ANTHROPIC_AUTH_TOKEN: 'ollama', ANTHROPIC_BASE_URL: 'http://localhost:11434'};
        if (f.model.value) body.model = f.model.value;
    }
    fetch('/api/swarm/agents/bulk', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
    }).then(r=>r.json()).then(agents => {
        addLog('agent_registered', 'Registered '+agents.length+' agent(s)');
    });
}

function killPopulation() {
    if (!confirm('Kill all local agent sessions? Their tasks will be requeued.')) return;
    fetch('/api/swarm/agents/kill-all', {method:'POST'}).then(r=>r.json()).then(d => {
        addLog('agent_stopped', 'Killed '+d.killed+' agent(s)');
    });
}

function clearTasks() {
    if (!confirm('Clear all tasks? They will be archived to a .txt log file.')) return;
    fetch('/api/swarm/tasks/clear', {method:'POST'}).then(r=>r.json()).then(d => {
        addLog('task_completed', 'Cleared '+d.cleared+' task(s)' + (d.log_file ? ' → '+d.log_file : ''));
        // Clear local state since tasks are gone
        state.tasks = {};
        renderAll();
    });
}

function regicide() {
    if (!confirm('Kill the emperor process? Workers will elect a new leader.')) return;
    fetch('/api/swarm/regicide', {method:'POST'}).then(r=>r.json()).then(d => {
        addLog('election', 'Regicide: '+d.message);
    }).catch(()=>{
        addLog('election', 'Emperor process killed');
    });
}

function cancelTask(id) {
    fetch('/api/swarm/tasks/'+id+'/cancel', {method:'POST'});
}

function archiveTask(id) {
    fetch('/api/swarm/tasks/'+id+'/archive', {method:'POST'}).then(r=>r.json()).then(t => {
        if (t.archived) {
            state.tasks[t.id] = t;
            addLog('task_archived', 'Archived: '+(t.title||t.id.substring(0,8)));
            renderAll();
        }
    });
}

function archiveAll() {
    fetch('/api/swarm/tasks/archive-all', {method:'POST'}).then(r=>r.json()).then(d => {
        if (d.archived > 0) {
            addLog('task_archived', 'Archived '+d.archived+' task(s)');
            // Mark them archived locally
            (d.task_ids || []).forEach(id => {
                if (state.tasks[id]) state.tasks[id].archived = true;
            });
            renderAll();
        }
    });
}

function reviewTask(id, accepted) {
    const feedback = accepted ? '' : (prompt('Rejection feedback:','') || '');
    if (!accepted && !feedback) return;
    fetch('/api/swarm/tasks/'+id+'/review', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({accepted: accepted, feedback: feedback})
    }).then(r=>r.json()).then(d => {
        addLog(accepted ? 'task_completed' : 'task_failed', (accepted ? 'Accepted' : 'Rejected')+': '+id.substring(0,8));
    });
}

function deregisterAgent(id) {
    fetch('/api/swarm/agents/'+id, {method:'DELETE'});
}

function viewTaskOutput(agentId, taskTitle) {
    const agent = state.agents[agentId];
    const name = agent ? agent.name : agentId.substring(0,8);
    document.getElementById('modal-title').textContent = 'Output: '+taskTitle+' ('+name+')';
    document.getElementById('modal-body').textContent = 'Loading...';
    document.getElementById('pane-modal').classList.add('active');
    fetch('/api/swarm/agents/'+agentId+'/output?lines=80').then(r=>r.json()).then(d => {
        document.getElementById('modal-body').textContent = d.output || '(empty)';
        const body = document.getElementById('modal-body');
        body.scrollTop = body.scrollHeight;
    });
}

function viewTaskResult(taskId, taskTitle) {
    document.getElementById('modal-title').textContent = 'Result: '+taskTitle;
    document.getElementById('modal-body').textContent = 'Loading...';
    document.getElementById('pane-modal').classList.add('active');
    fetch('/api/swarm/tasks/'+taskId).then(r=>r.json()).then(t => {
        const content = t.result || t.error || '(no output captured)';
        document.getElementById('modal-body').textContent = content;
        const body = document.getElementById('modal-body');
        body.scrollTop = body.scrollHeight;
    });
}

function sendRefine(agentId, taskId) {
    const input = document.getElementById('refine-'+taskId);
    const text = input?.value?.trim();
    if (!text) return;
    fetch('/api/swarm/agents/'+agentId+'/send', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({text: text})
    }).then(r=>r.json()).then(d => {
        if (d.sent) {
            addLog('task_assigned', 'Sent refinement to '+(state.agents[agentId]?.name||agentId.substring(0,8)));
            input.value = '';
        } else {
            addLog('task_failed', 'Failed to send refinement');
        }
    });
}

function changeDir(agentId, name) {
    const newPath = prompt('New working directory for '+name+':', '');
    if (!newPath) return;
    fetch('/api/swarm/agents/'+agentId+'/send', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({text: '/cd ' + newPath})
    }).then(r=>r.json()).then(d => {
        if (d.sent) addLog('task_assigned', 'Sent /cd '+newPath+' to '+name);
        else addLog('task_failed', 'Failed to send /cd to '+name);
    });
}

function viewOutput(agentId, name) {
    document.getElementById('modal-title').textContent = 'Output: '+name;
    document.getElementById('modal-body').textContent = 'Loading...';
    document.getElementById('pane-modal').classList.add('active');
    fetch('/api/swarm/agents/'+agentId+'/output').then(r=>r.json()).then(d => {
        document.getElementById('modal-body').textContent = d.output || '(empty)';
    });
}

function closeModal() {
    document.getElementById('pane-modal').classList.remove('active');
}

document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal(); });

// ---- Contributions (PR list) ----
function renderContributions() {
    const section = document.getElementById('contributions-section');
    const listEl = document.getElementById('contributions-list');
    const prRegex = /PR: (https?:\/\/\S+)/;

    // Scan ALL tasks (active + archived) for PR URLs
    const prs = [];
    Object.values(state.tasks).forEach(t => {
        const match = t.result?.match(prRegex);
        if (match) {
            const children = getTaskChildren(t.id);
            const subtaskCount = children.length;
            const completedCount = children.filter(c => c.status === 'completed').length;
            prs.push({
                url: match[1],
                title: t.title,
                status: t.status,
                completedAt: t.completed_at || t.updated_at,
                subtasks: subtaskCount,
                completedSubtasks: completedCount,
                taskId: t.id,
            });
        }
    });

    if (prs.length === 0) {
        section.style.display = 'none';
        return;
    }

    section.style.display = '';
    // Most recent first
    prs.sort((a,b) => (b.completedAt||'').localeCompare(a.completedAt||''));

    listEl.innerHTML = prs.map(pr => {
        let meta = pr.completedAt || '';
        if (pr.subtasks > 0) meta += ' | ' + pr.completedSubtasks + '/' + pr.subtasks + ' subtasks merged';
        return '<div class="contribution-row">'
            + '<div class="pr-icon">&#9432;</div>'
            + '<div class="pr-info">'
            + '<div class="pr-title" title="'+escapeHtml(pr.title)+'">'+escapeHtml(pr.title)+'</div>'
            + '<div class="pr-meta">'+escapeHtml(meta)+'</div>'
            + '</div>'
            + '<a class="pr-open" href="'+escapeHtml(pr.url)+'" target="_blank">Open PR</a>'
            + '</div>';
    }).join('');
}

// ---- Galactic Marketplace ----
function renderGalactic() {
    // Wallet badge
    document.getElementById('wallet-badge').textContent = state.wallet.balance + ' ' + state.wallet.currency;

    // Offerings from other nodes
    const offeringsEl = document.getElementById('offerings-list');
    const peerOfferings = state.offerings.filter(o => o.node_id !== NODE_ID);
    if (peerOfferings.length === 0) {
        offeringsEl.innerHTML = '<div class="empty" style="color:#444466;">No offerings from other nodes</div>';
    } else {
        offeringsEl.innerHTML = peerOfferings.map(o => {
            let html = '<div class="galactic-card">';
            html += '<div class="offering-agents">'+o.idle_agents+' Agent'+(o.idle_agents!==1?'s':'')+'</div>';
            html += '<div class="offering-price">'+o.price_per_task+' '+o.currency+' / task</div>';
            html += '<div class="offering-node">Node: '+o.node_id.substring(0,8)+'</div>';
            if (o.capabilities && o.capabilities.length) html += '<div style="font-size:0.75rem;color:#666699;">Caps: '+o.capabilities.join(', ')+'</div>';
            html += '<div style="font-size:0.7rem;color:#555577;">Expires: '+o.expires_at+'</div>';
            html += '<div class="card-actions"><button onclick="requestTribute(\''+o.id+'\')" style="background:#1a1a3a;border:1px solid #444488;color:#aa88ff;">Request Tribute</button></div>';
            html += '</div>';
            return html;
        }).join('');
    }

    // Tributes
    const tributesSection = document.getElementById('tributes-section');
    const tributesList = document.getElementById('tributes-list');
    if (state.tributes.length === 0) {
        tributesSection.style.display = 'none';
    } else {
        tributesSection.style.display = '';
        tributesList.innerHTML = state.tributes.map(t => {
            return '<div class="tribute-row">'
                + '<span class="tribute-status-'+t.status+'">'+t.status+'</span> '
                + t.task_count+' task(s) @ '+t.total_cost+' '+t.currency
                + ' &rarr; '+t.to_node_id.substring(0,8)
                + ' <span style="color:#555577;font-size:0.7rem;" title="'+t.tx_hash+'">tx:'+t.tx_hash.substring(0,10)+'...</span>'
                + '</div>';
        }).join('');
    }
}

function makeOffering() {
    const count = prompt('How many idle agents to offer?', '1');
    if (!count) return;
    fetch('/api/swarm/offerings', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({idle_agents: parseInt(count)})
    }).then(r=>r.json()).then(o => {
        if (o.id) {
            state.offerings.push(o);
            addLog('task_assigned', 'Created offering: '+o.idle_agents+' agents');
            renderGalactic();
        }
    });
}

function requestTribute(offeringId) {
    const count = prompt('How many tasks to request?', '1');
    if (!count) return;
    fetch('/api/swarm/tributes', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({offering_id: offeringId, task_count: parseInt(count)})
    }).then(r=>r.json()).then(t => {
        if (t.id) {
            state.tributes.push(t);
            addLog('task_assigned', 'Tribute requested: '+t.task_count+' tasks for '+t.total_cost+' '+t.currency);
            renderGalactic();
        }
    });
}

// ---- Marketplace Tabs ----
function switchMarketTab(tab) {
    document.querySelectorAll('.marketplace-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.marketplace-panel').forEach(p => p.classList.remove('active'));
    document.querySelector('.marketplace-tab[onclick*="'+tab+'"]').classList.add('active');
    document.getElementById('panel-'+tab).classList.add('active');
    if (tab === 'bounties') refreshBounties();
    if (tab === 'capabilities') refreshCapabilities();
    if (tab === 'history') renderSwarmHistory();
}

// ---- Bounty Board ----
let bountyData = { bounties: [], capabilities: [] };

function refreshBounties() {
    fetch('/api/broker/bounties').then(r => {
        if (!r.ok) throw new Error('Broker unavailable');
        return r.json();
    }).then(d => {
        bountyData.bounties = d.bounties || [];
        renderBounties();
    }).catch(() => {
        bountyData.bounties = [];
        renderBounties();
    });
}

function renderBounties() {
    const filter = document.getElementById('bounty-filter').value;
    let bounties = bountyData.bounties;
    if (filter) bounties = bounties.filter(b => b.status === filter);

    // Sort: open first, then by reward desc
    bounties.sort((a,b) => {
        if (a.status === 'open' && b.status !== 'open') return -1;
        if (b.status === 'open' && a.status !== 'open') return 1;
        return (b.reward || 0) - (a.reward || 0);
    });

    const el = document.getElementById('bounty-list');
    if (!bounties.length) {
        el.innerHTML = '<div class="empty" style="color:#443322;">No bounties available</div>';
        return;
    }

    el.innerHTML = bounties.map(b => {
        let html = '<div class="bounty-card">';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;">';
        html += '<span class="bounty-reward">' + (b.reward||0) + ' ' + (b.currency||'VOID') + '</span>';
        html += '<span class="bounty-status-' + b.status + '" style="font-size:0.75rem;font-weight:bold;">' + b.status + '</span>';
        html += '</div>';
        html += '<div class="bounty-title">' + escapeHtml(b.title || 'Untitled') + '</div>';
        if (b.description) html += '<div class="bounty-desc">' + escapeHtml(b.description).substring(0,120) + '</div>';
        if (b.required_capabilities && b.required_capabilities.length) {
            html += '<div class="bounty-caps">';
            b.required_capabilities.forEach(c => { html += '<span>' + escapeHtml(c) + '</span>'; });
            html += '</div>';
        }
        html += '<div class="bounty-meta">';
        html += 'Posted by: ' + (b.posted_by_node_id||'').substring(0,8);
        if (b.claimed_by_node_id) html += ' | Claimed by: ' + b.claimed_by_node_id.substring(0,8);
        html += ' | Expires: ' + (b.expires_at||'');
        html += '</div>';
        html += '<div class="card-actions">';
        if (b.status === 'open') {
            html += '<button onclick="claimBounty(\'' + b.id + '\')" style="background:#1a0d00;border:1px solid #663300;color:#ff8844;">Claim</button>';
        }
        html += '</div>';
        html += '</div>';
        return html;
    }).join('');
}

function showPostBountyForm() {
    document.getElementById('post-bounty-form').style.display = '';
}

function postBounty(e) {
    e.preventDefault();
    const f = e.target;
    const caps = (f.capabilities.value||'').split(',').map(s=>s.trim()).filter(Boolean);
    fetch('/api/broker/bounties', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            title: f.title.value,
            description: f.description.value,
            reward: parseInt(f.reward.value) || 10,
            required_capabilities: caps,
            ttl_seconds: parseInt(f.ttl.value) || 600,
        })
    }).then(r => r.json()).then(d => {
        if (d.bounty) {
            bountyData.bounties.push(d.bounty);
            f.reset();
            document.getElementById('post-bounty-form').style.display = 'none';
            addLog('task_created', 'Posted bounty: ' + d.bounty.title);
            renderBounties();
        }
    }).catch(() => { addLog('task_failed', 'Failed to post bounty (broker unavailable)'); });
}

function claimBounty(bountyId) {
    fetch('/api/broker/bounties/' + bountyId + '/claim', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({})
    }).then(r => r.json()).then(d => {
        if (d.bounty) {
            const idx = bountyData.bounties.findIndex(b => b.id === bountyId);
            if (idx >= 0) bountyData.bounties[idx] = d.bounty;
            addLog('task_assigned', 'Claimed bounty: ' + bountyId.substring(0,8));
            renderBounties();
        } else {
            addLog('task_failed', 'Failed to claim bounty: ' + (d.error||''));
        }
    }).catch(() => { addLog('task_failed', 'Failed to claim bounty (broker unavailable)'); });
}

// ---- Capabilities ----
function refreshCapabilities() {
    fetch('/api/broker/capabilities').then(r => {
        if (!r.ok) throw new Error('Broker unavailable');
        return r.json();
    }).then(d => {
        bountyData.capabilities = d.capabilities || [];
        renderCapabilities();
    }).catch(() => {
        bountyData.capabilities = [];
        renderCapabilities();
    });
}

function renderCapabilities() {
    const el = document.getElementById('capability-list');
    const caps = bountyData.capabilities;
    if (!caps.length) {
        el.innerHTML = '<div class="empty" style="color:#224444;">No capability profiles advertised</div>';
        return;
    }

    // Sort by acceptance rate desc, then idle agents desc
    caps.sort((a,b) => {
        if (b.acceptance_rate !== a.acceptance_rate) return b.acceptance_rate - a.acceptance_rate;
        return (b.idle_agents||0) - (a.idle_agents||0);
    });

    el.innerHTML = caps.map(c => {
        const rate = Math.round((c.acceptance_rate||0) * 100);
        const barColor = rate >= 80 ? '#44cc44' : rate >= 50 ? '#ccaa44' : '#cc4444';
        const barWidth = Math.max(4, rate);
        let html = '<div class="capability-card">';
        html += '<div class="cap-node">Node: ' + (c.node_id||'').substring(0,12) + '</div>';
        if (c.swarm_name) html += '<div class="cap-swarm">Swarm: ' + escapeHtml(c.swarm_name) + '</div>';
        html += '<div class="cap-agents">';
        html += '<span style="color:#66cccc;">' + (c.idle_agents||0) + '</span> idle / ' + (c.total_agents||0) + ' total agents';
        html += '</div>';
        html += '<div class="cap-stats">';
        html += 'Reputation: ' + rate + '%';
        html += ' <span class="rep-bar" style="width:60px;background:#222;"><span class="rep-bar-fill" style="width:'+barWidth+'%;background:'+barColor+';"></span></span>';
        html += ' | Completed: ' + (c.tasks_completed||0) + ' | Failed: ' + (c.tasks_failed||0);
        html += '</div>';
        if (c.capabilities && c.capabilities.length) {
            html += '<div class="cap-tags">';
            c.capabilities.forEach(cap => { html += '<span>' + escapeHtml(cap) + '</span>'; });
            html += '</div>';
        }
        html += '<div style="font-size:0.7rem;color:#336666;margin-top:4px;">Updated: ' + (c.updated_at||'') + '</div>';
        html += '</div>';
        return html;
    }).join('');
}

// ---- Swarm History ----
function renderSwarmHistory() {
    const el = document.getElementById('swarm-history-list');
    // Gather completed/failed tasks for history
    const history = [];
    Object.values(state.tasks).forEach(t => {
        if (t.status === 'completed' || t.status === 'failed') {
            history.push(t);
        }
    });

    if (!history.length) {
        el.innerHTML = '<div class="empty" style="color:#332244;">No completion history yet</div>';
        return;
    }

    // Sort most recent first
    history.sort((a,b) => (b.completed_at||b.updated_at||'').localeCompare(a.completed_at||a.updated_at||''));

    // Stats summary
    const completed = history.filter(t => t.status === 'completed').length;
    const failed = history.filter(t => t.status === 'failed').length;
    const total = completed + failed;
    const rate = total > 0 ? Math.round(completed / total * 100) : 0;
    const barColor = rate >= 80 ? '#44cc44' : rate >= 50 ? '#ccaa44' : '#cc4444';

    let html = '<div style="margin-bottom:14px;padding:12px;background:#0d0d1a;border:1px solid #1a1a2a;border-radius:6px;">';
    html += '<div style="font-size:0.95rem;color:#aa88cc;">This Swarm\'s Performance</div>';
    html += '<div style="display:flex;gap:24px;margin-top:8px;">';
    html += '<div><span style="font-size:1.3rem;font-weight:bold;color:#44cc44;">' + completed + '</span><div style="font-size:0.7rem;color:#668866;">Completed</div></div>';
    html += '<div><span style="font-size:1.3rem;font-weight:bold;color:#cc4444;">' + failed + '</span><div style="font-size:0.7rem;color:#886666;">Failed</div></div>';
    html += '<div><span style="font-size:1.3rem;font-weight:bold;color:#88aacc;">' + rate + '%</span><div style="font-size:0.7rem;color:#668888;">Success Rate</div></div>';
    html += '</div>';
    html += '<div style="margin-top:8px;height:6px;background:#222;border-radius:3px;"><div style="height:100%;width:'+rate+'%;background:'+barColor+';border-radius:3px;"></div></div>';
    html += '</div>';

    // Recent history entries
    html += history.slice(0, 30).map(t => {
        const icon = t.status === 'completed' ? '<span style="color:#44cc44;">&#10003;</span>' : '<span style="color:#cc4444;">&#10007;</span>';
        const time = t.completed_at || t.updated_at || '';
        const children = getTaskChildren(t.id);
        let meta = time;
        if (children.length) meta += ' | ' + children.filter(c => c.status === 'completed').length + '/' + children.length + ' subtasks';
        if (t.git_branch) meta += ' | ' + t.git_branch;
        return '<div class="history-row">'
            + '<div class="history-icon">' + icon + '</div>'
            + '<div class="history-info">'
            + '<div style="color:#ccc;">' + escapeHtml(t.title) + ' ' + statusBadge(t.status) + '</div>'
            + '<div style="font-size:0.7rem;color:#666;">' + escapeHtml(meta) + '</div>'
            + '</div>'
            + '<div class="history-time">' + escapeHtml(time) + '</div>'
            + '</div>';
    }).join('');

    el.innerHTML = html;
}

// Fetch bounty data on page load
(function() {
    setTimeout(refreshBounties, 1500);
    setTimeout(refreshCapabilities, 2000);
})();

// ---- WebSocket: the only data source ----
let ws;
function connectWs() {
    ws = new WebSocket('ws://'+location.host+'/ws');
    ws.onopen = () => {
        document.getElementById('ws-status').textContent = 'connected';
        // Fetch tributes (local state not in WS full_state)
        fetch('/api/swarm/tributes').then(r=>r.json()).then(t => {
            if (Array.isArray(t)) { state.tributes = t; renderGalactic(); }
        }).catch(()=>{});
    };
    ws.onclose = () => {
        document.getElementById('ws-status').textContent = 'reconnecting';
        setTimeout(connectWs, 2000);
    };
    ws.onmessage = (e) => {
        const msg = JSON.parse(e.data);
        switch (msg.type) {
            case 'full_state':
                state.tasks = {};
                state.agents = {};
                msg.tasks.forEach(t => { state.tasks[t.id] = t; });
                msg.agents.forEach(a => { state.agents[a.id] = a; });
                state.status = msg.status || {};
                state.offerings = msg.status?.offerings || [];
                state.wallet = msg.status?.wallet || {balance:0,currency:'VOID'};
                renderAll();
                break;
            case 'task_update':
                state.tasks[msg.task.id] = msg.task;
                addLog(msg.event, msg.event+': '+(msg.task.title||msg.task.id.substring(0,8)));
                scheduleRender();
                break;
            case 'agent_update':
                state.agents[msg.agent.id] = msg.agent;
                addLog(msg.event, msg.event+': '+(msg.agent.name||msg.agent.id.substring(0,8)));
                scheduleRender();
                break;
            case 'agent_removed':
                delete state.agents[msg.agent_id];
                addLog('agent_deregistered', 'agent_deregistered: '+msg.agent_id.substring(0,8));
                scheduleRender();
                break;
            case 'status':
                if (msg.status.emperor !== undefined) updateEmperorBanner(msg.status.emperor);
                if (msg.status.promoted) addLog('election', 'This node promoted to emperor');
                if (msg.status.offering) {
                    const o = msg.status.offering;
                    const idx = state.offerings.findIndex(x => x.id === o.id);
                    if (idx >= 0) state.offerings[idx] = o; else state.offerings.push(o);
                    addLog('task_assigned', 'Offering from '+o.node_id.substring(0,8)+': '+o.idle_agents+' agents');
                    renderGalactic();
                }
                if (msg.status.offering_withdrawn) {
                    state.offerings = state.offerings.filter(x => x.id !== msg.status.offering_withdrawn);
                    renderGalactic();
                }
                break;
        }
    };
}

connectWs();
JS
        . "\n</script>\n</body>\n</html>";
    }
}
