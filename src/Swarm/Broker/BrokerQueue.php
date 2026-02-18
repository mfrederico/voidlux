<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Broker;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Coroutine-safe message queue with store-and-forward semantics.
 *
 * Buffers messages destined for unreachable nodes and retries delivery
 * when the target comes back online. Also queues task creation requests
 * during emperor transitions.
 */
class BrokerQueue
{
    /** @var array<string, QueuedMessage[]> targetNodeId => messages */
    private array $queues = [];

    /** @var QueuedMessage[] Messages awaiting emperor (task creation during failover) */
    private array $emperorQueue = [];

    private int $maxPerNode = 500;
    private int $maxEmperorQueue = 200;
    private float $messageTtl = 300.0; // 5 minutes
    private bool $running = false;
    private ?Channel $deliverySignal = null;

    /** @var callable|null fn(string $targetNodeId, array $msg): bool */
    private $deliveryCallback = null;

    /** @var callable|null fn(string $msg): void */
    private $logCallback = null;

    private int $totalEnqueued = 0;
    private int $totalDelivered = 0;
    private int $totalExpired = 0;
    private int $totalDropped = 0;

    /**
     * Enqueue a message for a specific target node.
     */
    public function enqueue(string $targetNodeId, array $message, int $priority = 0): bool
    {
        $queue = $this->queues[$targetNodeId] ?? [];

        if (count($queue) >= $this->maxPerNode) {
            $this->totalDropped++;
            return false;
        }

        $queue[] = new QueuedMessage(
            targetNodeId: $targetNodeId,
            message: $message,
            priority: $priority,
            enqueuedAt: microtime(true),
            ttl: $this->messageTtl,
        );

        $this->queues[$targetNodeId] = $queue;
        $this->totalEnqueued++;
        $this->signal();

        return true;
    }

    /**
     * Enqueue a task creation request for the emperor.
     * Buffered during emperor transitions and flushed when a new emperor is elected.
     */
    public function enqueueForEmperor(array $message): bool
    {
        if (count($this->emperorQueue) >= $this->maxEmperorQueue) {
            $this->totalDropped++;
            return false;
        }

        $this->emperorQueue[] = new QueuedMessage(
            targetNodeId: '__emperor__',
            message: $message,
            priority: 10, // High priority
            enqueuedAt: microtime(true),
            ttl: $this->messageTtl,
        );

        $this->totalEnqueued++;
        return true;
    }

    /**
     * Called when a node reconnects — try to deliver buffered messages.
     */
    public function onNodeReconnected(string $nodeId): void
    {
        if (!empty($this->queues[$nodeId])) {
            $this->signal();
        }
    }

    /**
     * Called when a new emperor is elected — flush emperor queue.
     */
    public function onEmperorElected(string $emperorNodeId): void
    {
        if (empty($this->emperorQueue)) {
            return;
        }

        $flushed = 0;
        foreach ($this->emperorQueue as $queued) {
            if ($queued->isExpired()) {
                $this->totalExpired++;
                continue;
            }
            // Re-target to the actual emperor node
            $this->enqueue($emperorNodeId, $queued->message, $queued->priority);
            $flushed++;
        }

        $this->emperorQueue = [];
        $this->log("Flushed {$flushed} emperor-queue messages to {$emperorNodeId}");
        $this->signal();
    }

    /**
     * Get pending message count for a specific node.
     */
    public function pendingCount(string $nodeId): int
    {
        return count($this->queues[$nodeId] ?? []);
    }

    /**
     * Get total pending messages across all queues.
     */
    public function totalPending(): int
    {
        $total = count($this->emperorQueue);
        foreach ($this->queues as $queue) {
            $total += count($queue);
        }
        return $total;
    }

    public function onDelivery(callable $callback): void
    {
        $this->deliveryCallback = $callback;
    }

    public function onLog(callable $callback): void
    {
        $this->logCallback = $callback;
    }

    /**
     * Start the delivery loop coroutine.
     * Blocks on a channel, wakes when signaled or every 10s for cleanup.
     */
    public function start(): void
    {
        $this->running = true;
        $this->deliverySignal = new Channel(1);

        while ($this->running) {
            $this->deliverySignal->pop(10.0);
            if (!$this->running) {
                break;
            }
            $this->processQueues();
            $this->purgeExpired();
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $this->deliverySignal?->push(true, 0);
        $this->deliverySignal?->close();
    }

    public function stats(): array
    {
        $perNode = [];
        foreach ($this->queues as $nodeId => $queue) {
            $perNode[substr($nodeId, 0, 8)] = count($queue);
        }

        return [
            'total_pending' => $this->totalPending(),
            'emperor_queue' => count($this->emperorQueue),
            'per_node' => $perNode,
            'total_enqueued' => $this->totalEnqueued,
            'total_delivered' => $this->totalDelivered,
            'total_expired' => $this->totalExpired,
            'total_dropped' => $this->totalDropped,
        ];
    }

    private function processQueues(): void
    {
        if ($this->deliveryCallback === null) {
            return;
        }

        foreach ($this->queues as $nodeId => $queue) {
            if (empty($queue)) {
                unset($this->queues[$nodeId]);
                continue;
            }

            // Sort by priority (higher first), then by enqueue time
            usort($queue, function (QueuedMessage $a, QueuedMessage $b) {
                if ($a->priority !== $b->priority) {
                    return $b->priority - $a->priority;
                }
                return $a->enqueuedAt <=> $b->enqueuedAt;
            });

            $remaining = [];
            foreach ($queue as $queued) {
                if ($queued->isExpired()) {
                    $this->totalExpired++;
                    continue;
                }

                $delivered = ($this->deliveryCallback)($nodeId, $queued->message);
                if ($delivered) {
                    $this->totalDelivered++;
                } else {
                    $remaining[] = $queued;
                }
            }

            if (empty($remaining)) {
                unset($this->queues[$nodeId]);
            } else {
                $this->queues[$nodeId] = $remaining;
            }
        }
    }

    private function purgeExpired(): void
    {
        // Purge expired emperor queue messages
        $before = count($this->emperorQueue);
        $this->emperorQueue = array_values(array_filter(
            $this->emperorQueue,
            fn(QueuedMessage $m) => !$m->isExpired(),
        ));
        $this->totalExpired += $before - count($this->emperorQueue);
    }

    private function signal(): void
    {
        $this->deliverySignal?->push(true, 0);
    }

    private function log(string $message): void
    {
        if ($this->logCallback) {
            ($this->logCallback)($message);
        }
    }
}
