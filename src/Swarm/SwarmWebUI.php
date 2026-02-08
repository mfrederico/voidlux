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

.empty { text-align: center; padding: 30px; color: #444; }
.subtask-card { border-left: 3px solid #444; margin-left: 16px; }
.parent-card { border-left: 3px solid #cc6600; }
.planning-spinner { display: inline-block; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.review-badge { background: #3a3a1a; border: 1px solid #666600; border-radius: 4px; padding: 4px 8px; font-size: 0.75rem; color: #ddcc44; }
.subtask-progress { font-size: 0.8rem; color: #888; margin-left: 8px; }

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
    <div class="section">
        <div class="stats" id="stats">
            <div class="stat"><div class="stat-value" id="stat-pending">0</div><div class="stat-label">Pending</div></div>
            <div class="stat"><div class="stat-value" id="stat-planning">0</div><div class="stat-label">Planning</div></div>
            <div class="stat"><div class="stat-value" id="stat-active">0</div><div class="stat-label">Active</div></div>
            <div class="stat"><div class="stat-value" id="stat-review">0</div><div class="stat-label">Review</div></div>
            <div class="stat"><div class="stat-value" id="stat-completed">0</div><div class="stat-label">Completed</div></div>
            <div class="stat"><div class="stat-value" id="stat-failed">0</div><div class="stat-label">Failed</div></div>
            <div class="stat"><div class="stat-value" id="stat-agents">0</div><div class="stat-label">Agents</div></div>
        </div>
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
        <h2>Tasks</h2>
        <div class="card-grid" id="task-list">
            <div class="empty">No tasks yet</div>
        </div>
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

let agentMap = {}; // id -> {name, ...}
let taskChildren = {}; // parent_id -> [subtasks]

function renderTask(t, isSubtask) {
    const agentName = t.assigned_to ? (agentMap[t.assigned_to]?.name || t.assigned_to.substring(0,8)) : null;
    const isActive = t.status === 'claimed' || t.status === 'in_progress' || t.status === 'waiting_input';
    const cardClass = isSubtask ? 'card subtask-card' : (t.parent_id === null && taskChildren[t.id] ? 'card parent-card' : 'card');
    let html = '<div class="'+cardClass+'" id="task-'+t.id+'">';

    // Planning spinner
    if (t.status === 'planning') {
        html += '<div class="card-title">'+escapeHtml(t.title)+' '+statusBadge(t.status)+' <span class="planning-spinner" style="font-size:0.8rem;">&#9881;</span></div>';
        html += '<div style="font-size:0.85rem;color:#bb88ff;margin:4px 0;">Emperor is analyzing and decomposing this request...</div>';
    } else {
        html += '<div class="card-title">'+escapeHtml(t.title)+' '+statusBadge(t.status)+'</div>';
    }

    // Parent task: show subtask progress
    if (!isSubtask && taskChildren[t.id]) {
        const children = taskChildren[t.id];
        const done = children.filter(c => c.status === 'completed').length;
        const total = children.length;
        html += '<div class="subtask-progress">Subtasks: '+done+'/'+total+' completed</div>';
    }

    if (t.description) html += '<div style="font-size:0.85rem;color:#aaa;margin-bottom:4px;">'+escapeHtml(t.description).substring(0,120)+'</div>';
    if (agentName) html += '<div style="font-size:0.8rem;color:#668;" title="'+t.assigned_to+'">Agent: '+escapeHtml(agentName)+'</div>';

    // Review status
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

    // Work instructions / acceptance criteria detail toggle
    if (t.work_instructions || t.acceptance_criteria) {
        html += '<details style="margin-top:6px;font-size:0.8rem;"><summary style="cursor:pointer;color:#888;">Work Details</summary>';
        if (t.work_instructions) html += '<div style="color:#aaa;margin:4px 0;"><strong>Instructions:</strong> '+escapeHtml(t.work_instructions).substring(0,300)+'</div>';
        if (t.acceptance_criteria) html += '<div style="color:#aaa;"><strong>Criteria:</strong> '+escapeHtml(t.acceptance_criteria).substring(0,200)+'</div>';
        html += '</details>';
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

    // Render subtasks inline
    if (!isSubtask && taskChildren[t.id]) {
        html += '<div style="margin-top:8px;">';
        taskChildren[t.id].forEach(sub => { html += renderTask(sub, true); });
        html += '</div>';
    }

    html += '</div>';
    return html;
}

function renderAgent(a) {
    const shortPath = a.project_path ? a.project_path.replace(/^\/home\/[^/]+\//, '~/') : '';
    let html = '<div class="card" id="agent-'+a.id+'">';
    html += '<div class="card-title" title="Agent ID: '+a.id+'">'+escapeHtml(a.name)+' '+statusBadge(a.status)+'</div>';
    html += '<div style="font-size:0.85rem;color:#aaa;">Tool: '+a.tool+' | <span title="Worker node running this agent\'s tmux session (full: '+a.node_id+')">Node: '+a.node_id.substring(0,8)+'</span></div>';
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

// Save focused input state before DOM replacement
function saveFocusState() {
    const active = document.activeElement;
    if (active && active.tagName === 'INPUT' && active.id) {
        return { id: active.id, value: active.value, selStart: active.selectionStart, selEnd: active.selectionEnd };
    }
    return null;
}

// Restore focused input after DOM replacement
function restoreFocusState(state) {
    if (!state) return;
    const el = document.getElementById(state.id);
    if (el && el.tagName === 'INPUT') {
        el.value = state.value;
        el.focus();
        try { el.setSelectionRange(state.selStart, state.selEnd); } catch(e) {}
    }
}

function refresh() {
    fetch('/api/swarm/status').then(r=>r.json()).then(s => {
        document.getElementById('task-count').textContent = s.tasks.total;
        document.getElementById('agent-count').textContent = s.agents.total;
        document.getElementById('stat-pending').textContent = s.tasks.pending;
        document.getElementById('stat-planning').textContent = s.tasks.planning||0;
        document.getElementById('stat-active').textContent = (s.tasks.claimed||0) + (s.tasks.in_progress||0) + (s.tasks.waiting_input||0);
        document.getElementById('stat-review').textContent = s.tasks.pending_review||0;
        document.getElementById('stat-completed').textContent = s.tasks.completed;
        document.getElementById('stat-failed').textContent = s.tasks.failed;
        document.getElementById('stat-agents').textContent = s.agents.total;
    }).catch(()=>{});

    // Fetch agents first so task rendering can resolve agent names
    fetch('/api/swarm/agents').then(r=>r.json()).then(agents => {
        agentMap = {};
        agents.forEach(a => { agentMap[a.id] = a; });

        // Only re-render agent list if user isn't focused inside it
        const agentEl = document.getElementById('agent-list');
        if (!agentEl.contains(document.activeElement)) {
            agentEl.innerHTML = agents.length ? agents.map(renderAgent).join('') : '<div class="empty">No agents registered</div>';
        }

        // Now render tasks with agent lookup available
        fetch('/api/swarm/tasks').then(r=>r.json()).then(tasks => {
            // Build parent-child map
            taskChildren = {};
            const topLevel = [];
            tasks.forEach(t => {
                if (t.parent_id) {
                    if (!taskChildren[t.parent_id]) taskChildren[t.parent_id] = [];
                    taskChildren[t.parent_id].push(t);
                } else {
                    topLevel.push(t);
                }
            });
            const taskEl = document.getElementById('task-list');
            const focusState = saveFocusState();
            taskEl.innerHTML = topLevel.length ? topLevel.map(t => renderTask(t, false)).join('') : '<div class="empty">No tasks yet</div>';
            restoreFocusState(focusState);
        }).catch(()=>{});
    }).catch(()=>{});
}

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
        refresh();
    });
}

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
                // Replace input with select
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
        refresh();
    });
}

function killPopulation() {
    if (!confirm('Kill all local agent sessions? Their tasks will be requeued.')) return;
    fetch('/api/swarm/agents/kill-all', {method:'POST'}).then(r=>r.json()).then(d => {
        addLog('agent_stopped', 'Killed '+d.killed+' agent(s)');
        refresh();
    });
}

function clearTasks() {
    if (!confirm('Clear all tasks? They will be archived to a .txt log file.')) return;
    fetch('/api/swarm/tasks/clear', {method:'POST'}).then(r=>r.json()).then(d => {
        addLog('task_completed', 'Cleared '+d.cleared+' task(s)' + (d.log_file ? ' → '+d.log_file : ''));
        refresh();
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
    fetch('/api/swarm/tasks/'+id+'/cancel', {method:'POST'}).then(()=>refresh());
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
        refresh();
    });
}

function deregisterAgent(id) {
    fetch('/api/swarm/agents/'+id, {method:'DELETE'}).then(()=>refresh());
}

function viewTaskOutput(agentId, taskTitle) {
    const agent = agentMap[agentId];
    const name = agent ? agent.name : agentId.substring(0,8);
    document.getElementById('modal-title').textContent = 'Output: '+taskTitle+' ('+name+')';
    document.getElementById('modal-body').textContent = 'Loading...';
    document.getElementById('pane-modal').classList.add('active');
    fetch('/api/swarm/agents/'+agentId+'/output?lines=80').then(r=>r.json()).then(d => {
        document.getElementById('modal-body').textContent = d.output || '(empty)';
        // Auto-scroll to bottom
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
            addLog('task_assigned', 'Sent refinement to '+(agentMap[agentId]?.name||agentId.substring(0,8)));
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

let ws;
function connectWs() {
    ws = new WebSocket('ws://'+location.host+'/ws');
    ws.onopen = () => { document.getElementById('ws-status').textContent = 'connected'; };
    ws.onclose = () => {
        document.getElementById('ws-status').textContent = 'reconnecting';
        setTimeout(connectWs, 2000);
    };
    ws.onmessage = (e) => {
        const msg = JSON.parse(e.data);
        if (msg.type === 'task_event') {
            addLog(msg.event, msg.event+': '+(msg.data.title || msg.data.task_id || ''));
            // Debounced refresh — avoid flooding on rapid task events
            clearTimeout(window._wsRefreshTimer);
            window._wsRefreshTimer = setTimeout(refresh, 1000);
        }
        if (msg.type === 'agent_event') {
            addLog(msg.event, msg.event+': '+(msg.data.agent_id || '').substring(0,8));
            clearTimeout(window._wsRefreshTimer);
            window._wsRefreshTimer = setTimeout(refresh, 1000);
        }
        if (msg.type === 'status') {
            if (msg.status.tasks !== undefined) document.getElementById('task-count').textContent = msg.status.tasks;
            if (msg.status.agents !== undefined) document.getElementById('agent-count').textContent = msg.status.agents;
            if (msg.status.emperor !== undefined) updateEmperorBanner(msg.status.emperor);
            if (msg.status.promoted) addLog('election', 'This node promoted to emperor');
        }
    };
}

refresh();
connectWs();
setInterval(refresh, 30000);
JS
        . "\n</script>\n</body>\n</html>";
    }
}
