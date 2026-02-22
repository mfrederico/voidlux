<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Model;

/**
 * Represents a scheduled task in the swarm calendar.
 *
 * Supports both cron-based (recurring) and event-based (reactive) scheduling.
 * When triggered, creates a new TaskModel from the template.
 */
class ScheduledTaskModel
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public ?string $cronExpression,     // e.g., "0 2 * * 1" (Mondays at 2am)
        public ?string $eventTrigger,       // e.g., "task_complete:parent-id"
        public array $template,             // Task template (title, description, etc.)
        public string $createdBy,           // agent_id or "user"
        public string $createdAt,
        public ?string $nextRunAt,          // ISO 8601 timestamp
        public bool $enabled,
        public ?string $lastRunAt,
        public int $runCount,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            title: $row['title'],
            description: $row['description'] ?? '',
            cronExpression: $row['cron_expression'] ?? null,
            eventTrigger: $row['event_trigger'] ?? null,
            template: json_decode($row['template'], true),
            createdBy: $row['created_by'],
            createdAt: $row['created_at'],
            nextRunAt: $row['next_run_at'] ?? null,
            enabled: (bool) $row['enabled'],
            lastRunAt: $row['last_run_at'] ?? null,
            runCount: (int) ($row['run_count'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'cron_expression' => $this->cronExpression,
            'event_trigger' => $this->eventTrigger,
            'template' => $this->template,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
            'next_run_at' => $this->nextRunAt,
            'enabled' => $this->enabled,
            'last_run_at' => $this->lastRunAt,
            'run_count' => $this->runCount,
        ];
    }

    /**
     * Check if this is a cron-based schedule.
     */
    public function isCronBased(): bool
    {
        return $this->cronExpression !== null;
    }

    /**
     * Check if this is an event-based schedule.
     */
    public function isEventBased(): bool
    {
        return $this->eventTrigger !== null;
    }
}
