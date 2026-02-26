<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

use Aoe\Tmux\TmuxService;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Thin HTTP server that exposes tmux operations as a JSON-RPC API.
 * Runs inside the agent container, receives requests from the emperor's RemoteTmuxRpc.
 */
class AgentHostServer
{
    private Server $server;
    private LocalTmuxRpc $rpc;

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 9092,
        ?TmuxService $tmux = null,
    ) {
        $this->rpc = new LocalTmuxRpc($tmux);
    }

    public function start(): void
    {
        $this->server = new Server($this->host, $this->port);
        $this->server->set([
            'worker_num' => 1,
            'enable_coroutine' => true,
        ]);

        $this->server->on('start', function () {
            echo "[agent-host] RPC server listening on {$this->host}:{$this->port}\n";
        });

        $this->server->on('request', function (Request $request, Response $response) {
            $response->header('Content-Type', 'application/json');
            $response->header('Access-Control-Allow-Origin', '*');

            $uri = $request->server['request_uri'] ?? '/';
            $method = $request->server['request_method'] ?? 'GET';

            if ($uri === '/health' && $method === 'GET') {
                $response->end(json_encode(['status' => 'ok', 'port' => $this->port]));
                return;
            }

            if ($uri === '/rpc' && $method === 'POST') {
                $this->handleRpc($request, $response);
                return;
            }

            $response->status(404);
            $response->end(json_encode(['error' => 'Not found']));
        });

        $this->server->start();
    }

    private function handleRpc(Request $request, Response $response): void
    {
        $body = json_decode($request->getContent(), true);
        if (!$body || !isset($body['method'])) {
            $response->status(400);
            $response->end(json_encode(['error' => 'Missing method']));
            return;
        }

        $method = $body['method'];
        $params = $body['params'] ?? [];

        try {
            $result = match ($method) {
                'sessionExists' => [
                    'exists' => $this->rpc->sessionExists($params['session'] ?? ''),
                ],
                'createSession' => [
                    'created' => $this->rpc->createSession(
                        $params['session'] ?? '',
                        $params['cwd'] ?? '/tmp',
                        $params['command'] ?? '',
                    ),
                ],
                'sendText' => [
                    'ok' => $this->rpc->sendText($params['session'] ?? '', $params['text'] ?? ''),
                ],
                'sendEnter' => [
                    'ok' => $this->rpc->sendEnter($params['session'] ?? ''),
                ],
                'sendKeys' => [
                    'ok' => $this->rpc->sendKeys($params['session'] ?? '', $params['keys'] ?? ''),
                ],
                'pasteText' => [
                    'ok' => $this->rpc->pasteText($params['session'] ?? '', $params['text'] ?? ''),
                ],
                'capturePane' => [
                    'content' => $this->rpc->capturePane(
                        $params['session'] ?? '',
                        (int) ($params['lines'] ?? 50),
                    ),
                ],
                'killSession' => [
                    'killed' => $this->rpc->killSession($params['session'] ?? ''),
                ],
                'getSessionPids' => [
                    'pids' => $this->rpc->getSessionPids($params['session'] ?? ''),
                ],
                default => throw new \InvalidArgumentException("Unknown method: {$method}"),
            };

            $response->end(json_encode($result, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $response->status(500);
            $response->end(json_encode(['error' => $e->getMessage()]));
        }
    }
}
