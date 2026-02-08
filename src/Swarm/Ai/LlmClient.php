<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Ai;

use Swoole\Coroutine\Http\Client;

/**
 * Coroutine-safe LLM client supporting Ollama and Claude APIs.
 *
 * Both providers use the /v1/messages endpoint (Claude-compatible format).
 * Ollama natively supports this endpoint, so a single code path handles both.
 */
class LlmClient
{
    public function __construct(
        private string $provider = 'ollama',
        private string $model = 'qwen3:32b',
        private string $ollamaHost = '127.0.0.1',
        private int $ollamaPort = 11434,
        private string $claudeApiKey = '',
    ) {}

    /**
     * Send a chat completion request to the configured LLM.
     */
    public function chat(string $systemPrompt, string $userPrompt): ?string
    {
        if ($this->provider === 'claude') {
            $client = new Client('api.anthropic.com', 443, true);
            $headers = [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->claudeApiKey,
                'anthropic-version' => '2023-06-01',
            ];
            $model = $this->model ?: 'claude-sonnet-4-5-20250929';
        } else {
            $client = new Client($this->ollamaHost, $this->ollamaPort);
            $headers = ['Content-Type' => 'application/json'];
            $model = $this->model;
        }

        $client->set(['timeout' => 120]);
        $client->setHeaders($headers);

        $body = json_encode([
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_tokens' => 4096,
        ]);

        $client->post('/v1/messages', $body);

        if ($client->statusCode !== 200) {
            $client->close();
            return null;
        }

        $data = json_decode($client->body, true);
        $client->close();

        return $data['content'][0]['text'] ?? null;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
