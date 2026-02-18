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

        $this->log("Decomposing task: {$request->title}");
        $response = $this->llm->chat($systemPrompt, $userPrompt);
        if ($response === null) {
            $this->log("LLM returned null — decomposition failed");
            return [];
        }

        $this->log("LLM response (" . strlen($response) . " chars): " . substr($response, 0, 200));
        $subtasks = $this->parseResponse($response);
        $this->log("Parsed " . count($subtasks) . " subtask(s)");

        return $subtasks;
    }

    /**
     * Get project directory tree + README + project type for LLM context.
     */
    private function getProjectContext(string $projectPath): string
    {
        $lines = [];
        $this->scanDir($projectPath, '', $lines, 0, 2);

        $tree = implode("\n", $lines);

        // Detect project type and include prominently
        $projectType = self::detectProjectType($projectPath);
        if ($projectType) {
            $tree = "## Project Type\n{$projectType}\n\n## File Tree\n" . $tree;
        }

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

    /**
     * Detect the project's primary language/framework from marker files.
     * Returns a human-readable description or empty string.
     */
    public static function detectProjectType(string $projectPath): string
    {
        $markers = [
            'composer.json'      => ['lang' => 'PHP',        'read' => true],
            'package.json'       => ['lang' => 'JavaScript/TypeScript', 'read' => true],
            'requirements.txt'   => ['lang' => 'Python',     'read' => false],
            'pyproject.toml'     => ['lang' => 'Python',     'read' => true],
            'setup.py'           => ['lang' => 'Python',     'read' => false],
            'Cargo.toml'         => ['lang' => 'Rust',       'read' => true],
            'go.mod'             => ['lang' => 'Go',         'read' => true],
            'Gemfile'            => ['lang' => 'Ruby',       'read' => false],
            'build.gradle'       => ['lang' => 'Java/Kotlin','read' => false],
            'pom.xml'            => ['lang' => 'Java',       'read' => false],
            'mix.exs'            => ['lang' => 'Elixir',     'read' => false],
            'deno.json'          => ['lang' => 'TypeScript (Deno)', 'read' => true],
            'tsconfig.json'      => ['lang' => 'TypeScript', 'read' => false],
            'CMakeLists.txt'     => ['lang' => 'C/C++',      'read' => false],
            'Makefile'           => ['lang' => 'C/C++',      'read' => false],
        ];

        $detected = [];
        $details = [];

        foreach ($markers as $file => $info) {
            $fullPath = $projectPath . '/' . $file;
            if (!file_exists($fullPath)) {
                continue;
            }

            $lang = $info['lang'];
            $detected[$lang] = $file;

            // Read key config files for extra context (name, description, deps)
            if ($info['read']) {
                $content = @file_get_contents($fullPath);
                if ($content === false) {
                    continue;
                }

                $snippet = self::extractConfigSnippet($file, $content);
                if ($snippet) {
                    $details[] = $snippet;
                }
            }
        }

        if (empty($detected)) {
            return '';
        }

        // Primary language is the first detected
        $primary = array_key_first($detected);
        $markerFile = $detected[$primary];
        $result = "This is a **{$primary}** project (detected via {$markerFile}).";

        if (count($detected) > 1) {
            $others = array_diff_key($detected, [$primary => true]);
            $result .= " Also uses: " . implode(', ', array_keys($others)) . ".";
        }

        $result .= "\nIMPORTANT: All code must be written in {$primary}. Do NOT use other languages unless the project already uses them.";

        if (!empty($details)) {
            $result .= "\n" . implode("\n", $details);
        }

        return $result;
    }

    /**
     * Extract a brief, useful snippet from a config file.
     */
    private static function extractConfigSnippet(string $filename, string $content): string
    {
        switch ($filename) {
            case 'composer.json':
                $data = json_decode($content, true);
                if (!$data) return '';
                $parts = [];
                if (!empty($data['name'])) $parts[] = "Package: {$data['name']}";
                if (!empty($data['description'])) $parts[] = "Description: {$data['description']}";
                if (!empty($data['require'])) {
                    $deps = array_keys($data['require']);
                    $parts[] = "Dependencies: " . implode(', ', array_slice($deps, 0, 15));
                }
                if (!empty($data['autoload']['psr-4'])) {
                    $ns = array_keys($data['autoload']['psr-4']);
                    $parts[] = "Namespaces: " . implode(', ', $ns);
                }
                return $parts ? implode("\n", $parts) : '';

            case 'package.json':
                $data = json_decode($content, true);
                if (!$data) return '';
                $parts = [];
                if (!empty($data['name'])) $parts[] = "Package: {$data['name']}";
                if (!empty($data['description'])) $parts[] = "Description: {$data['description']}";
                $allDeps = array_merge(
                    array_keys($data['dependencies'] ?? []),
                    array_keys($data['devDependencies'] ?? []),
                );
                if ($allDeps) {
                    $parts[] = "Dependencies: " . implode(', ', array_slice($allDeps, 0, 15));
                }
                return $parts ? implode("\n", $parts) : '';

            case 'Cargo.toml':
            case 'go.mod':
            case 'pyproject.toml':
            case 'deno.json':
                // Return first few lines as-is (these are concise formats)
                $lines = explode("\n", $content);
                return implode("\n", array_slice($lines, 0, 10));

            default:
                return '';
        }
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

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][planner] {$message}\n";
    }
}
