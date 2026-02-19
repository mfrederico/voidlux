<?php

declare(strict_types=1);

namespace VoidLux\App;

use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Simple health check endpoint.
 *
 * Returns JSON with status and timestamp on GET /health.
 */
class HealthCheck
{
    public function handle(Request $request, Response $response): bool
    {
        if ($request->server['request_uri'] !== '/health' || $request->server['request_method'] !== 'GET') {
            return false;
        }

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status' => 'ok',
            'timestamp' => time(),
        ], JSON_UNESCAPED_SLASHES));

        return true;
    }
}
