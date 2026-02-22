<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Scheduler;

/**
 * Simple cron expression parser for calculating next run times.
 *
 * Supports standard 5-field cron format: minute hour day month weekday
 * Examples:
 * - "0 2 * * 1" = Mondays at 2:00 AM
 * - "*/15 * * * *" = Every 15 minutes
 * - "0 */6 * * *" = Every 6 hours
 * - "0 0 1 * *" = First day of every month at midnight
 */
class CronExpression
{
    private array $fields;

    private function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * Parse a cron expression string.
     *
     * @throws \InvalidArgumentException if expression is invalid
     */
    public static function parse(string $expression): self
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Invalid cron expression: expected 5 fields, got " . count($parts));
        }

        return new self([
            'minute' => $parts[0],
            'hour' => $parts[1],
            'day' => $parts[2],
            'month' => $parts[3],
            'weekday' => $parts[4],
        ]);
    }

    /**
     * Calculate the next run time from now.
     */
    public function getNextRunDate(\DateTimeInterface $from = null): ?\DateTimeImmutable
    {
        $from = $from ? \DateTimeImmutable::createFromInterface($from) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Start from next minute (rounded up)
        $next = $from->modify('+1 minute')->setTime((int)$from->format('H'), (int)$from->format('i'), 0);

        // Search up to 366 days ahead (covers all yearly patterns)
        for ($i = 0; $i < 366 * 24 * 60; $i++) {
            if ($this->matches($next)) {
                return $next;
            }
            $next = $next->modify('+1 minute');
        }

        return null; // No match found in next year
    }

    /**
     * Check if a datetime matches this cron expression.
     */
    private function matches(\DateTimeInterface $dt): bool
    {
        return $this->matchesField((int)$dt->format('i'), $this->fields['minute'], 0, 59)
            && $this->matchesField((int)$dt->format('H'), $this->fields['hour'], 0, 23)
            && $this->matchesField((int)$dt->format('d'), $this->fields['day'], 1, 31)
            && $this->matchesField((int)$dt->format('m'), $this->fields['month'], 1, 12)
            && $this->matchesField((int)$dt->format('w'), $this->fields['weekday'], 0, 6);
    }

    /**
     * Check if a value matches a cron field pattern.
     */
    private function matchesField(int $value, string $pattern, int $min, int $max): bool
    {
        // Wildcard
        if ($pattern === '*') {
            return true;
        }

        // Step values: */5 or 0-30/5
        if (str_contains($pattern, '/')) {
            [$range, $step] = explode('/', $pattern, 2);
            $step = (int)$step;

            if ($range === '*') {
                return $value % $step === 0;
            }

            // Range with step: 0-30/5
            [$start, $end] = explode('-', $range, 2);
            return $value >= (int)$start && $value <= (int)$end && ($value - (int)$start) % $step === 0;
        }

        // Range: 1-5
        if (str_contains($pattern, '-')) {
            [$start, $end] = explode('-', $pattern, 2);
            return $value >= (int)$start && $value <= (int)$end;
        }

        // List: 1,3,5
        if (str_contains($pattern, ',')) {
            $values = array_map('intval', explode(',', $pattern));
            return in_array($value, $values, true);
        }

        // Exact value
        return $value === (int)$pattern;
    }
}
