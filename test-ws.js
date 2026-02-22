const WebSocket = require('ws');

// First create auth session
const http = require('http');

const postData = JSON.stringify({});
const options = {
  hostname: 'localhost',
  port: 9090,
  path: '/api/swarm/auth/start',
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Content-Length': postData.length
  }
};

const req = http.request(options, (res) => {
  let data = '';
  res.on('data', (chunk) => data += chunk);
  res.on('end', () => {
    const result = JSON.parse(data);
    console.log('Auth session created:', result.session_name);

    // Now connect WebSocket
    const ws = new WebSocket(`ws://localhost:9091/ws/terminal/${result.session_name}`);

    ws.on('open', () => {
      console.log('WebSocket OPEN');
      // Send resize
      ws.send(JSON.stringify({ type: 'resize', rows: 30, cols: 120 }));
    });

    ws.on('message', (data) => {
      console.log('Received:', data.toString().substring(0, 200));
    });

    ws.on('close', (code, reason) => {
      console.log('WebSocket CLOSED:', code, reason.toString());
      process.exit(0);
    });

    ws.on('error', (err) => {
      console.log('WebSocket ERROR:', err.message);
    });

    setTimeout(() => {
      console.log('Closing after 10s...');
      ws.close();
    }, 10000);
  });
});

req.on('error', (e) => {
  console.error('HTTP Error:', e);
});

req.write(postData);
req.end();
