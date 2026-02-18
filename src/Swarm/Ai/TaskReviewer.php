<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Ai;

use VoidLux\Swarm\Model\TaskModel;

/**
 * Reviews completed task results against acceptance criteria using an LLM.
 */
class TaskReviewer
{
    public function __construct(
        private readonly LlmClient $llm,
    ) {}

    /**
     * Review a task's result against its acceptance criteria.
     */
    public function review(TaskModel $task, string $result): ReviewResult
    {
        // If no acceptance criteria, auto-accept
        if (trim($task->acceptanceCriteria) === '') {
            return new ReviewResult(true, 'Auto-accepted: no acceptance criteria defined.');
        }

        $systemPrompt = <<<'PROMPT'
You are a code reviewer. Given a task with acceptance criteria and the agent's reported result, determine if the work meets the criteria.

Be pragmatic:
- If the agent reports completing the work and the result description is reasonable, accept it.
- Only reject if there are clear signs the work is incomplete, incorrect, or the agent gave up.
- IMPORTANT: The work instructions may reference wrong file paths or the wrong programming language (e.g. Python paths for a PHP project). This happens when the planner couldn't detect the project type. If the agent correctly identified the real language and modified the correct files to achieve the task's INTENT, that is a PASS — do not reject because the file paths or language differ from what the instructions said.
- Focus on whether the goal was achieved, not whether the exact steps in the instructions were followed verbatim.

Return ONLY valid JSON (no markdown fences, no explanation):
{"accepted": true|false, "feedback": "Brief explanation of your decision"}
PROMPT;

        $userPrompt = "## Task\n";
        $userPrompt .= "Title: {$task->title}\n";
        if ($task->description) {
            $userPrompt .= "Description: {$task->description}\n";
        }
        if ($task->workInstructions) {
            $userPrompt .= "\n## Work Instructions\n{$task->workInstructions}\n";
        }
        $userPrompt .= "\n## Acceptance Criteria\n{$task->acceptanceCriteria}\n";
        $userPrompt .= "\n## Agent's Result\n{$result}\n";

        $this->log("Reviewing task: {$task->title}");
        $response = $this->llm->chat($systemPrompt, $userPrompt);
        if ($response === null) {
            $this->log("LLM unavailable — auto-accepting");
            return new ReviewResult(true, 'Auto-accepted: LLM reviewer unavailable.');
        }

        $this->log("Review response: " . substr($response, 0, 200));
        $result = $this->parseResponse($response);
        $this->log("Review verdict: " . ($result->accepted ? 'ACCEPTED' : 'REJECTED') . " — {$result->feedback}");

        return $result;
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][reviewer] {$message}\n";
    }

    private function parseResponse(string $response): ReviewResult
    {
        // Strip markdown fences if present
        $response = preg_replace('/^```(?:json)?\s*\n?/m', '', $response);
        $response = preg_replace('/\n?```\s*$/m', '', $response);
        $response = trim($response);

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['accepted'])) {
            return new ReviewResult(true, 'Auto-accepted: could not parse review response.');
        }

        return new ReviewResult(
            (bool) $data['accepted'],
            (string) ($data['feedback'] ?? ''),
        );
    }
}
