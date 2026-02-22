<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Scheduler;

use Swoole\Coroutine;
use VoidLux\Swarm\Storage\SwarmDatabase;
use VoidLux\Swarm\Model\{ScheduledTaskModel, TaskModel, TaskStatus};
use VoidLux\Swarm\Orchestrator\TaskQueue;

/**
 * Task scheduler service - evaluates cron expressions and event triggers.
 *
 * Runs in a coroutine loop checking for due tasks and listening for events.
 * When triggered, creates a new TaskModel from the schedule template.
 */
class TaskScheduler
{
    private SwarmDatabase $db;
    private TaskQueue $taskQueue;
    private array $eventHandlers = [];
    private bool $running = false;

    public function __construct(SwarmDatabase $db, TaskQueue $taskQueue)
    {
        $this->db = $db;
        $this->taskQueue = $taskQueue;
    }

    /**
     * Start the scheduler loop (runs in coroutine).
     */
    public function start(): void
    {
        $this->running = true;

        // Coroutine: Check cron-based schedules every minute
        Coroutine::create(function () {
            while ($this->running) {
                $this->evaluateCronSchedules();
                Coroutine::sleep(60); // Check every minute
            }
        });
    }

    /**
     * Stop the scheduler.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Evaluate all cron-based schedules and trigger due tasks.
     */
    private function evaluateCronSchedules(): void
    {
        $dueTasks = $this->db->getDueScheduledTasks();

        foreach ($dueTasks as $schedule) {
            if (!$schedule->isCronBased()) {
                continue;
            }

            // Create task from template
            $this->executeScheduledTask($schedule);

            // Calculate next run time
            $nextRun = $this->calculateNextRun($schedule->cronExpression);
            $this->db->updateScheduledTaskRun($schedule->id, $nextRun);
        }
    }

    /**
     * Fire an event trigger and execute matching schedules.
     */
    public function fireEvent(string $event): void
    {
        $schedules = $this->db->getScheduledTasksByEvent($event);

        foreach ($schedules as $schedule) {
            $this->executeScheduledTask($schedule);

            // Event-based tasks don't update next_run_at (they only fire on events)
            $this->db->updateScheduledTaskRun($schedule->id, null);
        }
    }

    /**
     * Execute a scheduled task by creating a new task from its template.
     */
    private function executeScheduledTask(ScheduledTaskModel $schedule): void
    {
        $template = $schedule->template;

        // Create new task from template
        $task = new TaskModel(
            id: bin2hex(random_bytes(4)),
            title: $template['title'] ?? $schedule->title,
            description: $template['description'] ?? $schedule->description,
            status: TaskStatus::Pending,
            priority: $template['priority'] ?? 5,
            requiredCapabilities: $template['required_capabilities'] ?? [],
            createdBy: $schedule->createdBy,
            assignedTo: null,
            assignedNode: null,
            result: '',
            error: '',
            progress: 0,
            projectPath: $template['project_path'] ?? '',
            context: json_encode($template['context'] ?? []),
            lamportTs: 0,
            claimedAt: null,
            completedAt: null,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            parentId: null,
            workInstructions: $template['work_instructions'] ?? '',
            acceptanceCriteria: $template['acceptance_criteria'] ?? '',
            reviewStatus: null,
            reviewFeedback: null,
            archived: false,
            gitBranch: null,
            mergeAttempts: 0,
            testCommand: $template['test_command'] ?? null,
            dependsOn: [],
            autoMerge: false,
            prUrl: null,
        );

        // Insert task via TaskQueue (handles gossip)
        $this->taskQueue->createTask($task);
    }

    /**
     * Calculate next run time from cron expression.
     *
     * @param string $cronExpression e.g., "0 2 * * 1" (Mondays at 2am UTC)
     * @return string|null Next run timestamp (ISO 8601) or null if invalid
     */
    private function calculateNextRun(string $cronExpression): ?string
    {
        try {
            $cron = CronExpression::parse($cronExpression);
            $next = $cron->getNextRunDate();
            return $next ? $next->format('Y-m-d\TH:i:s\Z') : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create a new scheduled task (used by MCP tool and API).
     */
    public function createSchedule(
        string $title,
        string $description,
        ?string $cronExpression,
        ?string $eventTrigger,
        array $template,
        string $createdBy
    ): ScheduledTaskModel {
        $id = bin2hex(random_bytes(4));
        $nextRun = $cronExpression ? $this->calculateNextRun($cronExpression) : null;

        $schedule = new ScheduledTaskModel(
            id: $id,
            title: $title,
            description: $description,
            cronExpression: $cronExpression,
            eventTrigger: $eventTrigger,
            template: $template,
            createdBy: $createdBy,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
            nextRunAt: $nextRun,
            enabled: true,
            lastRunAt: null,
            runCount: 0,
        );

        $this->db->insertScheduledTask($schedule);
        return $schedule;
    }
}
