<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

use Swoole\Coroutine\Http\Client;

/**
 * Remote HTTP implementation of AgentRpc.
 * Sends JSON-RPC requests to an agent container's RPC server.
 */
class RemoteTmuxRpc implements AgentRpc
{
    private string $host;
    private int $port;
    private float $timeout;

    public function __construct(string $hostPort, float $timeout = 10.0)
    {
        $parts = parse_url("http://{$hostPort}");
        $this->host = $parts['host'] ?? '127.0.0.1';
        $this->port = $parts['port'] ?? 9092;
        $this->timeout = $timeout;
    }

    public function sessionExists(string $sessionName): bool
    {
        $result = $this->call('sessionExists', ['session' => $sessionName]);
        return $result['exists'] ?? false;
    }

    public function createSession(string $sessionName, string $cwd, string $command): bool
    {
        $result = $this->call('createSession', [
            'session' => $sessionName,
            'cwd' => $cwd,
            'command' => $command,
        ]);
        return $result['created'] ?? false;
    }

    public function sendText(string $sessionName, string $text): bool
    {
        $result = $this->call('sendText', ['session' => $sessionName, 'text' => $text]);
        return $result['ok'] ?? false;
    }

    public function sendEnter(string $sessionName): bool
    {
        $result = $this->call('sendEnter', ['session' => $sessionName]);
        return $result['ok'] ?? false;
    }

    public function sendKeys(string $sessionName, string $keys): bool
    {
        $result = $this->call('sendKeys', ['session' => $sessionName, 'keys' => $keys]);
        return $result['ok'] ?? false;
    }

    public function pasteText(string $sessionName, string $text): bool
    {
        $result = $this->call('pasteText', ['session' => $sessionName, 'text' => $text]);
        return $result['ok'] ?? false;
    }

    public function capturePane(string $sessionName, int $lines = 50): string
    {
        $result = $this->call('capturePane', ['session' => $sessionName, 'lines' => $lines]);
        return $result['content'] ?? '';
    }

    public function killSession(string $sessionName): bool
    {
        $result = $this->call('killSession', ['session' => $sessionName]);
        return $result['killed'] ?? false;
    }

    public function getSessionPids(string $sessionName): array
    {
        $result = $this->call('getSessionPids', ['session' => $sessionName]);
        return $result['pids'] ?? [];
    }

    /**
     * Make an HTTP POST to the agent RPC server.
     * @return array<string, mixed>
     */
    private function call(string $method, array $params): array
    {
        $client = new Client($this->host, $this->port);
        $client->set(['timeout' => $this->timeout]);
        $client->setHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        $body = json_encode([
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);

        $client->post('/rpc', $body);
        $statusCode = $client->getStatusCode();
        $responseBody = $client->getBody();
        $client->close();

        if ($statusCode !== 200 || !$responseBody) {
            error_log("[RemoteTmuxRpc] {$method} failed: HTTP {$statusCode}");
            return [];
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            error_log("[RemoteTmuxRpc] {$method}: invalid JSON response");
            return [];
        }

        if (isset($data['error'])) {
            error_log("[RemoteTmuxRpc] {$method}: {$data['error']}");
            return [];
        }

        return $data;
    }
}
