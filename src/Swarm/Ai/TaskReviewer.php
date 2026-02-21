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
     *
     * @param TaskModel      $task       The subtask being reviewed
     * @param string         $result     The agent's reported result
     * @param TaskModel|null $parentTask The parent task (for broader context)
     */
    public function review(TaskModel $task, string $result, ?TaskModel $parentTask = null): ReviewResult
    {
        // If no acceptance criteria, auto-accept
        if (trim($task->acceptanceCriteria) === '') {
            return new ReviewResult(true, 'Auto-accepted: no acceptance criteria defined.');
        }

        $systemPrompt = <<<'PROMPT'
You are a thorough code reviewer. Given a subtask with acceptance criteria and the agent's reported result, determine if the work meets the criteria AND fits correctly into the larger feature.

Review checklist:
1. Does the result satisfy the acceptance criteria?
2. If architecture context is provided, does the implementation follow the specified data flow, field names, and integration points?
3. Are there signs of semantic mismatches? (e.g., using the wrong field name, reading from the wrong source, missing a DB migration for a new field)
4. Did the agent complete the full scope or only part of it?

Be pragmatic but thorough:
- Accept work that achieves the goal, even if it takes a different approach than specified.
- REJECT if the agent used wrong field/method names that won't integrate with other subtasks (check the architecture context).
- REJECT if the agent added a new data field but forgot the database migration.
- REJECT if the agent's changes are incomplete (e.g., added a field to one method but not the create/update paths).
- IMPORTANT: Wrong file paths or language due to planner error is NOT a rejection reason if the agent adapted correctly.
- When rejecting, be specific about what needs to change so the agent can fix it.

Return ONLY valid JSON (no markdown fences, no explanation):
{"accepted": true|false, "feedback": "Specific explanation. If rejecting, describe exactly what needs to change."}
PROMPT;

        $userPrompt = '';

        // Include parent task context if available
        if ($parentTask) {
            $userPrompt .= "## Parent Task (overall goal)\n";
            $userPrompt .= "Title: {$parentTask->title}\n";
            if ($parentTask->description) {
                $userPrompt .= "Description: " . substr($parentTask->description, 0, 1000) . "\n";
            }
            $userPrompt .= "\n";
        }

        $userPrompt .= "## Subtask Being Reviewed\n";
        $userPrompt .= "Title: {$task->title}\n";
        if ($task->description) {
            $userPrompt .= "Description: {$task->description}\n";
        }
        if ($task->workInstructions) {
            $userPrompt .= "\n## Work Instructions\n{$task->workInstructions}\n";
        }
        $userPrompt .= "\n## Acceptance Criteria\n{$task->acceptanceCriteria}\n";
        $userPrompt .= "\n## Agent's Result\n{$result}\n";

        $this->log("Reviewing task: {$task->title}" . ($parentTask ? " (parent: {$parentTask->title})" : ''));
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
