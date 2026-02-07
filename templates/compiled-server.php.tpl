<?php
/**
 * VoidLux Compiled Server Template
 * Adapted from myctobot pipeline-engine server template.
 * Generated: {{TIMESTAMP}}
 */

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$host = '{{HOST}}';
$port = {{PORT}};

$server = new Server($host, $port);

$server->set([
    'worker_num' => {{WORKER_NUM}},
    'enable_coroutine' => true,
    'hook_flags' => SWOOLE_HOOK_ALL,
]);

$server->on('start', function (Server $server) use ($host, $port) {
    echo "{{APP_NAME}} started on {$host}:{$port}\n";
});

$server->on('request', function (Request $request, Response $response) {
    $uri = $request->server['request_uri'] ?? '/';

    if ($uri === '/health') {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status' => 'ok',
            'app' => '{{APP_NAME}}',
            'timestamp' => date('c'),
        ]));
        return;
    }

    {{REQUEST_HANDLER}}
});

$server->start();
