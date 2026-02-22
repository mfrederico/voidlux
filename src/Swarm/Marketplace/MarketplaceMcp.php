<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Marketplace;

/**
 * MCP Tools for Galactic Marketplace
 *
 * Agents can interact with the marketplace through these primitives:
 * - Browse and search offerings
 * - Post bounties for needed plugins
 * - Offer plugins they've built
 * - Install plugins
 * - Rate and review plugins
 */
class MarketplaceMcp
{
    private MarketplaceDatabase $db;

    public function __construct(MarketplaceDatabase $db)
    {
        $this->db = $db;
    }

    /**
     * Get all MCP tool schemas for the marketplace.
     */
    public function getTools(): array
    {
        return [
            (object) [
                'name' => 'marketplace_browse',
                'description' => 'Browse available plugins in the Galactic Marketplace',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name',
                        ],
                        'search' => (object) [
                            'type' => 'string',
                            'description' => 'Optional search query to filter plugins',
                        ],
                        'limit' => (object) [
                            'type' => 'integer',
                            'description' => 'Number of results to return (default: 20)',
                        ],
                    ],
                    'required' => ['agent_name'],
                ],
            ],
            (object) [
                'name' => 'marketplace_post_bounty',
                'description' => 'Post a bounty requesting a plugin you need',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name',
                        ],
                        'plugin_name' => (object) [
                            'type' => 'string',
                            'description' => 'Name of the plugin you need',
                        ],
                        'description' => (object) [
                            'type' => 'string',
                            'description' => 'Detailed description of what the plugin should do',
                        ],
                        'required_capabilities' => (object) [
                            'type' => 'array',
                            'description' => 'List of capabilities the plugin must provide',
                            'items' => (object) ['type' => 'string'],
                        ],
                        'reward' => (object) [
                            'type' => 'string',
                            'description' => 'Optional reward for building this plugin',
                        ],
                    ],
                    'required' => ['agent_name', 'plugin_name', 'description', 'required_capabilities'],
                ],
            ],
            (object) [
                'name' => 'marketplace_offer_plugin',
                'description' => 'Offer a plugin you have built to the marketplace',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name',
                        ],
                        'plugin_name' => (object) [
                            'type' => 'string',
                            'description' => 'Name of your plugin',
                        ],
                        'version' => (object) [
                            'type' => 'string',
                            'description' => 'Plugin version (semantic versioning)',
                        ],
                        'description' => (object) [
                            'type' => 'string',
                            'description' => 'What your plugin does',
                        ],
                        'capabilities' => (object) [
                            'type' => 'array',
                            'description' => 'Capabilities provided by this plugin',
                            'items' => (object) ['type' => 'string'],
                        ],
                        'requirements' => (object) [
                            'type' => 'array',
                            'description' => 'External dependencies required',
                            'items' => (object) ['type' => 'string'],
                        ],
                        'code_url' => (object) [
                            'type' => 'string',
                            'description' => 'Git URL or file path to plugin code',
                        ],
                    ],
                    'required' => ['agent_name', 'plugin_name', 'version', 'description', 'capabilities'],
                ],
            ],
            (object) [
                'name' => 'marketplace_install',
                'description' => 'Install a plugin from the marketplace',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name',
                        ],
                        'plugin_name' => (object) [
                            'type' => 'string',
                            'description' => 'Name of the plugin to install',
                        ],
                    ],
                    'required' => ['agent_name', 'plugin_name'],
                ],
            ],
            (object) [
                'name' => 'marketplace_review',
                'description' => 'Rate and review a plugin you have used',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name',
                        ],
                        'plugin_name' => (object) [
                            'type' => 'string',
                            'description' => 'Name of the plugin to review',
                        ],
                        'rating' => (object) [
                            'type' => 'integer',
                            'description' => 'Rating from 1 to 5 stars',
                            'minimum' => 1,
                            'maximum' => 5,
                        ],
                        'review_text' => (object) [
                            'type' => 'string',
                            'description' => 'Optional detailed review',
                        ],
                    ],
                    'required' => ['agent_name', 'plugin_name', 'rating'],
                ],
            ],
            (object) [
                'name' => 'marketplace_bounties',
                'description' => 'View open bounties for plugins that need to be built',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name',
                        ],
                    ],
                    'required' => ['agent_name'],
                ],
            ],
            (object) [
                'name' => 'marketplace_claim_bounty',
                'description' => 'Claim a bounty to build a requested plugin',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name',
                        ],
                        'bounty_id' => (object) [
                            'type' => 'string',
                            'description' => 'ID of the bounty to claim',
                        ],
                    ],
                    'required' => ['agent_name', 'bounty_id'],
                ],
            ],
        ];
    }

    /**
     * Handle marketplace tool calls.
     */
    public function handleToolCall(string $toolName, array $args): array
    {
        return match ($toolName) {
            'marketplace_browse' => $this->handleBrowse($args),
            'marketplace_post_bounty' => $this->handlePostBounty($args),
            'marketplace_offer_plugin' => $this->handleOfferPlugin($args),
            'marketplace_install' => $this->handleInstall($args),
            'marketplace_review' => $this->handleReview($args),
            'marketplace_bounties' => $this->handleBounties($args),
            'marketplace_claim_bounty' => $this->handleClaimBounty($args),
            default => $this->toolError("Unknown marketplace tool: {$toolName}"),
        };
    }

    private function handleBrowse(array $args): array
    {
        $search = $args['search'] ?? null;
        $limit = $args['limit'] ?? 20;

        $offerings = $search
            ? $this->db->searchOfferings($search)
            : $this->db->getAllOfferings($limit);

        return $this->toolResult([
            'count' => count($offerings),
            'plugins' => array_map(fn($o) => [
                'name' => $o['plugin_name'],
                'version' => $o['version'],
                'description' => $o['description'],
                'author' => $o['author_agent_id'],
                'capabilities' => $o['capabilities'],
                'rating' => round($o['rating_avg'], 1),
                'downloads' => $o['downloads'],
                'featured' => (bool) $o['featured'],
            ], $offerings),
        ]);
    }

    private function handlePostBounty(array $args): array
    {
        // TODO: Get agent ID from agent_name
        $agentId = 'temp-agent-id'; // Placeholder
        $nodeId = 'temp-node-id';

        $id = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');

        $this->db->createBounty([
            'id' => $id,
            'plugin_name' => $args['plugin_name'],
            'description' => $args['description'],
            'requester_agent_id' => $agentId,
            'requester_node_id' => $nodeId,
            'required_capabilities' => $args['required_capabilities'],
            'reward' => $args['reward'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->toolResult([
            'status' => 'bounty_posted',
            'bounty_id' => $id,
            'plugin_name' => $args['plugin_name'],
            'message' => 'Bounty posted to Galactic Marketplace! Other agents can now claim and build this plugin.',
        ]);
    }

    private function handleOfferPlugin(array $args): array
    {
        // TODO: Get agent ID from agent_name
        $agentId = 'temp-agent-id';
        $nodeId = 'temp-node-id';

        $id = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');

        // TODO: Calculate code hash if code_url provided
        $codeHash = isset($args['code_url']) ? hash('sha256', $args['code_url']) : null;

        $this->db->createOffering([
            'id' => $id,
            'plugin_name' => $args['plugin_name'],
            'version' => $args['version'],
            'description' => $args['description'],
            'author_agent_id' => $agentId,
            'author_node_id' => $nodeId,
            'capabilities' => $args['capabilities'],
            'requirements' => $args['requirements'] ?? [],
            'code_url' => $args['code_url'] ?? null,
            'code_hash' => $codeHash,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->toolResult([
            'status' => 'plugin_offered',
            'offering_id' => $id,
            'plugin_name' => $args['plugin_name'],
            'message' => 'Plugin listed in Galactic Marketplace! Other agents can now discover and install it.',
        ]);
    }

    private function handleInstall(array $args): array
    {
        // TODO: Get agent ID from agent_name
        $agentId = 'temp-agent-id';

        // Find offering
        $offerings = $this->db->searchOfferings($args['plugin_name']);
        if (empty($offerings)) {
            return $this->toolError("Plugin '{$args['plugin_name']}' not found in marketplace");
        }

        $offering = $offerings[0]; // Get latest version

        // Install plugin
        $this->db->installPlugin(
            $agentId,
            $offering['plugin_name'],
            $offering['version'],
            $offering['id']
        );

        // TODO: Actually download and install the plugin code
        // For now, just record the installation

        return $this->toolResult([
            'status' => 'installed',
            'plugin_name' => $offering['plugin_name'],
            'version' => $offering['version'],
            'capabilities' => $offering['capabilities'],
            'message' => 'Plugin installed! You now have these new capabilities.',
        ]);
    }

    private function handleReview(array $args): array
    {
        // TODO: Get agent ID from agent_name
        $agentId = 'temp-agent-id';
        $nodeId = 'temp-node-id';

        // Find offering
        $offerings = $this->db->searchOfferings($args['plugin_name']);
        if (empty($offerings)) {
            return $this->toolError("Plugin '{$args['plugin_name']}' not found");
        }

        $offering = $offerings[0];

        $id = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');

        $this->db->createReview([
            'id' => $id,
            'offering_id' => $offering['id'],
            'reviewer_agent_id' => $agentId,
            'reviewer_node_id' => $nodeId,
            'rating' => $args['rating'],
            'review_text' => $args['review_text'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->toolResult([
            'status' => 'review_posted',
            'plugin_name' => $offering['plugin_name'],
            'rating' => $args['rating'],
            'message' => 'Thank you for your review! It helps other agents discover great plugins.',
        ]);
    }

    private function handleBounties(array $args): array
    {
        $bounties = $this->db->getOpenBounties();

        return $this->toolResult([
            'count' => count($bounties),
            'bounties' => array_map(fn($b) => [
                'id' => $b['id'],
                'plugin_name' => $b['plugin_name'],
                'description' => $b['description'],
                'requester' => $b['requester_agent_id'],
                'required_capabilities' => $b['required_capabilities'],
                'reward' => $b['reward'],
                'created_at' => $b['created_at'],
            ], $bounties),
        ]);
    }

    private function handleClaimBounty(array $args): array
    {
        // TODO: Get agent ID from agent_name
        $agentId = 'temp-agent-id';

        $this->db->claimBounty($args['bounty_id'], $agentId);

        return $this->toolResult([
            'status' => 'bounty_claimed',
            'bounty_id' => $args['bounty_id'],
            'message' => 'Bounty claimed! Build the plugin and offer it to complete the bounty.',
        ]);
    }

    private function toolResult(array $data): array
    {
        return ['content' => [(object) ['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT)]]];
    }

    private function toolError(string $message): array
    {
        return [
            'content' => [(object) ['type' => 'text', 'text' => json_encode(['error' => $message], JSON_PRETTY_PRINT)]],
            'isError' => true,
        ];
    }
}
