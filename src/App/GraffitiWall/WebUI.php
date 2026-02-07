<?php

declare(strict_types=1);

namespace VoidLux\App\GraffitiWall;

/**
 * Single HTML page with embedded CSS/JS for the graffiti wall.
 */
class WebUI
{
    public static function render(string $nodeId, int $httpPort): string
    {
        $nodeIdJs = json_encode($nodeId);
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VoidLux Graffiti Wall</title>
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
    border-bottom: 2px solid #6600cc;
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.header h1 {
    font-size: 1.5rem;
    background: linear-gradient(90deg, #ff00ff, #00ffff, #ff00ff);
    background-size: 200% auto;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: shimmer 3s linear infinite;
}
@keyframes shimmer {
    to { background-position: 200% center; }
}
.status-bar {
    display: flex;
    gap: 16px;
    font-size: 0.8rem;
    color: #888;
}
.status-bar .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
.dot-green { background: #00ff66; box-shadow: 0 0 4px #00ff66; }
.compose {
    padding: 20px 24px;
    background: #111;
    border-bottom: 1px solid #222;
}
.compose form {
    display: flex;
    gap: 10px;
    max-width: 800px;
    margin: 0 auto;
}
.compose input[type="text"] {
    flex: 1;
    background: #1a1a1a;
    border: 1px solid #333;
    color: #fff;
    padding: 10px 14px;
    border-radius: 4px;
    font-family: inherit;
    font-size: 0.95rem;
}
.compose input[type="text"]:focus { outline: none; border-color: #6600cc; }
.compose input[name="author"] { max-width: 150px; }
.compose button {
    background: #6600cc;
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 4px;
    cursor: pointer;
    font-family: inherit;
    font-weight: bold;
}
.compose button:hover { background: #7700dd; }
.wall {
    max-width: 800px;
    margin: 20px auto;
    padding: 0 24px;
}
.post {
    background: #111;
    border-left: 4px solid #666;
    margin-bottom: 12px;
    padding: 12px 16px;
    border-radius: 0 4px 4px 0;
    animation: slideIn 0.3s ease-out;
}
@keyframes slideIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.post-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 6px;
    font-size: 0.8rem;
    color: #888;
}
.post-author { font-weight: bold; }
.post-content {
    font-size: 1rem;
    line-height: 1.5;
    word-break: break-word;
    white-space: pre-wrap;
}
.post-meta {
    margin-top: 6px;
    font-size: 0.7rem;
    color: #555;
}
.empty-wall {
    text-align: center;
    padding: 60px 0;
    color: #444;
    font-size: 1.2rem;
}
</style>
</head>
<body>
<div class="header">
    <h1>VoidLux Graffiti Wall</h1>
    <div class="status-bar">
        <span><span class="dot dot-green"></span> Node: <span id="node-id">...</span></span>
        <span>Peers: <span id="peer-count">0</span></span>
        <span>Posts: <span id="post-count">0</span></span>
        <span>WS: <span id="ws-status">connecting</span></span>
    </div>
</div>
<div class="compose">
    <form id="post-form">
        <input type="text" name="author" placeholder="Your name" maxlength="50" />
        <input type="text" name="content" placeholder="Write on the wall..." maxlength="1000" autofocus />
        <button type="submit">Post</button>
    </form>
</div>
<div class="wall" id="wall">
    <div class="empty-wall">The wall is empty. Be the first to write something!</div>
</div>

<script>
const NODE_ID = {$nodeIdJs};
const WS_URL = 'ws://' + location.host + '/ws';
document.getElementById('node-id').textContent = NODE_ID.substring(0, 8);

function nodeColor(nodeId) {
    let hash = 0;
    for (let i = 0; i < nodeId.length; i++) {
        hash = nodeId.charCodeAt(i) + ((hash << 5) - hash);
    }
    const h = Math.abs(hash) % 360;
    return 'hsl(' + h + ', 70%, 60%)';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function renderPost(post) {
    const color = nodeColor(post.node_id);
    const time = new Date(post.created_at).toLocaleString();
    const nodeShort = post.node_id.substring(0, 8);
    return '<div class="post" style="border-left-color:' + color + '">' +
        '<div class="post-header">' +
            '<span class="post-author" style="color:' + color + '">' + escapeHtml(post.author || 'anon') + '</span>' +
            '<span>' + time + '</span>' +
        '</div>' +
        '<div class="post-content">' + escapeHtml(post.content) + '</div>' +
        '<div class="post-meta">node:' + nodeShort + ' | ts:' + post.lamport_ts + '</div>' +
    '</div>';
}

function loadPosts() {
    fetch('/api/posts')
        .then(r => r.json())
        .then(posts => {
            const wall = document.getElementById('wall');
            if (posts.length === 0) {
                wall.innerHTML = '<div class="empty-wall">The wall is empty. Be the first to write something!</div>';
            } else {
                wall.innerHTML = posts.map(renderPost).join('');
            }
            document.getElementById('post-count').textContent = posts.length;
        });
}

function addPost(post) {
    const wall = document.getElementById('wall');
    const empty = wall.querySelector('.empty-wall');
    if (empty) empty.remove();
    wall.insertAdjacentHTML('afterbegin', renderPost(post));
    const count = wall.querySelectorAll('.post').length;
    document.getElementById('post-count').textContent = count;
}

document.getElementById('post-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = e.target;
    const content = form.content.value.trim();
    if (!content) return;
    const author = form.author.value.trim() || 'anon';
    fetch('/api/posts', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({content: content, author: author})
    }).then(r => r.json()).then(post => {
        form.content.value = '';
    });
});

let ws;
function connectWs() {
    ws = new WebSocket(WS_URL);
    ws.onopen = () => { document.getElementById('ws-status').textContent = 'connected'; };
    ws.onclose = () => {
        document.getElementById('ws-status').textContent = 'reconnecting';
        setTimeout(connectWs, 2000);
    };
    ws.onmessage = (e) => {
        const msg = JSON.parse(e.data);
        if (msg.type === 'new_post') addPost(msg.post);
        if (msg.type === 'status') {
            document.getElementById('peer-count').textContent = msg.status.peers || 0;
            document.getElementById('post-count').textContent = msg.status.posts || 0;
        }
    };
}

function pollStatus() {
    fetch('/api/status')
        .then(r => r.json())
        .then(s => {
            document.getElementById('peer-count').textContent = s.peers || 0;
        })
        .catch(() => {});
}

loadPosts();
connectWs();
setInterval(pollStatus, 5000);
</script>
</body>
</html>
HTML;
    }
}
