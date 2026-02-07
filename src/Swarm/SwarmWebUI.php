<?php

declare(strict_types=1);

namespace VoidLux\Swarm;

class SwarmWebUI
{
    public static function render(string $nodeId): string
    {
        $nodeIdJs = json_encode($nodeId);
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
.status-claimed { background: #1a3a1a; color: #66cc66; }
.status-in_progress { background: #1a2a3a; color: #66aaff; }
.status-completed { background: #1a3a1a; color: #00ff66; }
.status-failed { background: #3a1a1a; color: #ff6666; }
.status-cancelled { background: #333; color: #888; }

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

<div class="main">
    <div class="section">
        <div class="stats" id="stats">
            <div class="stat"><div class="stat-value" id="stat-pending">0</div><div class="stat-label">Pending</div></div>
            <div class="stat"><div class="stat-value" id="stat-active">0</div><div class="stat-label">Active</div></div>
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
                <input type="text" form="task-form" name="project_path" placeholder="Project path" style="background:#1a1a1a;border:1px solid #333;color:#fff;padding:8px 12px;border-radius:4px;font-family:inherit;" />
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
        . "\nconst NODE_ID = {$nodeIdJs};\n" . <<<'JS'
document.getElementById('node-id').textContent = NODE_ID.substring(0, 8);

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

function renderTask(t) {
    let html = '<div class="card" id="task-'+t.id+'">';
    html += '<div class="card-title">'+escapeHtml(t.title)+' '+statusBadge(t.status)+'</div>';
    if (t.description) html += '<div style="font-size:0.85rem;color:#aaa;margin-bottom:4px;">'+escapeHtml(t.description).substring(0,120)+'</div>';
    if (t.assigned_to) html += '<div style="font-size:0.8rem;color:#668;">Agent: '+t.assigned_to.substring(0,8)+'</div>';
    if (t.progress) html += '<div style="font-size:0.8rem;color:#686;">'+escapeHtml(t.progress).substring(0,80)+'</div>';
    if (t.result) html += '<div style="font-size:0.8rem;color:#6a6;">Result: '+escapeHtml(t.result).substring(0,100)+'</div>';
    if (t.error) html += '<div style="font-size:0.8rem;color:#a66;">Error: '+escapeHtml(t.error).substring(0,100)+'</div>';
    html += '<div class="card-meta">ts:'+t.lamport_ts+' | '+t.created_at+'</div>';
    html += '<div class="card-actions">';
    if (t.status === 'pending' || t.status === 'claimed' || t.status === 'in_progress') {
        html += '<button onclick="cancelTask(\''+t.id+'\')">Cancel</button>';
    }
    html += '</div></div>';
    return html;
}

function renderAgent(a) {
    let html = '<div class="card" id="agent-'+a.id+'">';
    html += '<div class="card-title">'+escapeHtml(a.name)+' '+statusBadge(a.status)+'</div>';
    html += '<div style="font-size:0.85rem;color:#aaa;">Tool: '+a.tool+' | Node: '+a.node_id.substring(0,8)+'</div>';
    if (a.capabilities && a.capabilities.length) html += '<div style="font-size:0.8rem;color:#686;">Caps: '+a.capabilities.join(', ')+'</div>';
    if (a.current_task_id) html += '<div style="font-size:0.8rem;color:#668;">Task: '+a.current_task_id.substring(0,8)+'</div>';
    html += '<div class="card-meta">Session: '+(a.tmux_session_id||'none')+'</div>';
    html += '<div class="card-actions">';
    html += '<button onclick="viewOutput(\''+a.id+'\',\''+escapeHtml(a.name)+'\')">View Output</button>';
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

function refresh() {
    fetch('/api/swarm/status').then(r=>r.json()).then(s => {
        document.getElementById('task-count').textContent = s.tasks.total;
        document.getElementById('agent-count').textContent = s.agents.total;
        document.getElementById('stat-pending').textContent = s.tasks.pending;
        document.getElementById('stat-active').textContent = (s.tasks.claimed||0) + (s.tasks.in_progress||0);
        document.getElementById('stat-completed').textContent = s.tasks.completed;
        document.getElementById('stat-failed').textContent = s.tasks.failed;
        document.getElementById('stat-agents').textContent = s.agents.total;
    }).catch(()=>{});

    fetch('/api/swarm/tasks').then(r=>r.json()).then(tasks => {
        const el = document.getElementById('task-list');
        el.innerHTML = tasks.length ? tasks.map(renderTask).join('') : '<div class="empty">No tasks yet</div>';
    }).catch(()=>{});

    fetch('/api/swarm/agents').then(r=>r.json()).then(agents => {
        const el = document.getElementById('agent-list');
        el.innerHTML = agents.length ? agents.map(renderAgent).join('') : '<div class="empty">No agents registered</div>';
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

function cancelTask(id) {
    fetch('/api/swarm/tasks/'+id+'/cancel', {method:'POST'}).then(()=>refresh());
}

function deregisterAgent(id) {
    fetch('/api/swarm/agents/'+id, {method:'DELETE'}).then(()=>refresh());
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
            refresh();
        }
        if (msg.type === 'agent_event') {
            addLog(msg.event, msg.event+': '+(msg.data.agent_id || '').substring(0,8));
            refresh();
        }
        if (msg.type === 'status') {
            document.getElementById('task-count').textContent = msg.status.tasks || 0;
            document.getElementById('agent-count').textContent = msg.status.agents || 0;
        }
    };
}

refresh();
connectWs();
setInterval(refresh, 5000);
JS
        . "\n</script>\n</body>\n</html>";
    }
}
