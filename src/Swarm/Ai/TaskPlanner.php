<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Ai;

use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Decomposes high-level requests into specific subtasks using an LLM.
 */
class TaskPlanner
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly SwarmDatabase $db,
    ) {}

    /**
     * Decompose a parent task into subtask definitions.
     *
     * @return array[] Each element: ['title', 'description', 'work_instructions',
     *                                'acceptance_criteria', 'requiredCapabilities', 'priority']
     */
    public function decompose(TaskModel $request): array
    {
        $projectContext = '';
        if ($request->projectPath && is_dir($request->projectPath)) {
            $projectContext = $this->getProjectContext($request->projectPath);
        }

        // Gather available agent capabilities
        $agents = $this->db->getAllIdleAgents();
        $capabilities = [];
        foreach ($agents as $agent) {
            $capabilities = array_merge($capabilities, $agent->capabilities);
        }
        $capabilities = array_unique($capabilities);
        $capsList = !empty($capabilities) ? implode(', ', $capabilities) : 'general-purpose';

        $systemPrompt = <<<'PROMPT'
You are a senior software architect. Given a high-level request and project structure, decompose it into specific, independent subtasks that can be assigned to individual AI coding agents.

Each subtask must be:
- Self-contained: an agent can complete it without depending on another subtask's output
- Specific: reference exact files to create or modify, and describe the approach
- Verifiable: include clear acceptance criteria

Return ONLY a valid JSON array (no markdown fences, no explanation). Each element:
{
    "title": "Short imperative title",
    "description": "What this subtask accomplishes",
    "work_instructions": "Specific files to modify/create, approach to take, code patterns to follow",
    "acceptance_criteria": "How to verify this subtask is done correctly",
    "requiredCapabilities": [],
    "priority": 0
}

Rules:
- Return between 1 and 8 subtasks
- Higher priority number = more important (do first)
- If the request is simple enough for one agent, return a single subtask
- Do NOT include testing/documentation subtasks unless explicitly requested
PROMPT;

        $userPrompt = "## Request\n";
        $userPrompt .= "Title: {$request->title}\n";
        if ($request->description) {
            $userPrompt .= "Description: {$request->description}\n";
        }
        if ($request->context) {
            $userPrompt .= "Context: {$request->context}\n";
        }
        if ($projectContext) {
            $userPrompt .= "\n## Project Structure\n{$projectContext}\n";
        }
        $userPrompt .= "\n## Available Agent Capabilities\n{$capsList}\n";

        $response = $this->llm->chat($systemPrompt, $userPrompt);
        if ($response === null) {
            return [];
        }

        return $this->parseResponse($response);
    }

    /**
     * Get project directory tree + README for LLM context.
     */
    private function getProjectContext(string $projectPath): string
    {
        $lines = [];
        $this->scanDir($projectPath, '', $lines, 0, 2);

        $tree = implode("\n", $lines);

        // Include README if present
        $readmePath = $projectPath . '/README.md';
        if (file_exists($readmePath)) {
            $readme = file_get_contents($readmePath);
            if (strlen($readme) > 2000) {
                $readme = substr($readme, 0, 2000) . "\n... (truncated)";
            }
            $tree .= "\n\n## README.md\n{$readme}";
        }

        // Limit total context
        if (strlen($tree) > 4000) {
            $tree = substr($tree, 0, 4000) . "\n... (truncated)";
        }

        return $tree;
    }

    private function scanDir(string $basePath, string $prefix, array &$lines, int $depth, int $maxDepth): void
    {
        if ($depth > $maxDepth) {
            return;
        }

        $skip = ['vendor', 'node_modules', '.git', '.idea', 'data', '__pycache__'];
        $entries = @scandir($basePath);
        if ($entries === false) {
            return;
        }

        $entries = array_diff($entries, ['.', '..']);
        sort($entries);

        foreach ($entries as $entry) {
            if (in_array($entry, $skip, true)) {
                continue;
            }

            $fullPath = $basePath . '/' . $entry;
            $display = $prefix . $entry;

            if (is_dir($fullPath)) {
                $lines[] = $display . '/';
                $this->scanDir($fullPath, $display . '/', $lines, $depth + 1, $maxDepth);
            } else {
                $lines[] = $display;
            }
        }
    }

    private function parseResponse(string $response): array
    {
        // Strip markdown fences if present
        $response = preg_replace('/^```(?:json)?\s*\n?/m', '', $response);
        $response = preg_replace('/\n?```\s*$/m', '', $response);
        $response = trim($response);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [];
        }

        // Validate structure
        $subtasks = [];
        foreach ($data as $item) {
            if (!is_array($item) || empty($item['title'])) {
                continue;
            }
            $subtasks[] = [
                'title' => (string) $item['title'],
                'description' => (string) ($item['description'] ?? ''),
                'work_instructions' => (string) ($item['work_instructions'] ?? ''),
                'acceptance_criteria' => (string) ($item['acceptance_criteria'] ?? ''),
                'requiredCapabilities' => (array) ($item['requiredCapabilities'] ?? []),
                'priority' => (int) ($item['priority'] ?? 0),
            ];
        }

        return $subtasks;
    }
}
