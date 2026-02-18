<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Broker;

use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Routes messages between nodes through the Seneschal broker.
 *
 * Handles three routing patterns:
 * 1. **Direct forwarding**: Route BROKER_FORWARD messages to specific target nodes
 * 2. **Task mediation**: Buffer task creation during emperor failover, forward when elected
 * 3. **Offer mediation**: Track offer lifecycle and route between sender/recipient nodes
 */
class BrokerRouter
{
    /** @var array<string, true> Dedup for forwarded messages */
    private array $seenForwards = [];
    private int $seenLimit = 5000;

    private int $totalForwarded = 0;
    private int $totalBuffered = 0;
    private int $totalOfferRouted = 0;

    /** @var callable|null fn(string $msg): void */
    private $logCallback = null;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly NodeDirectory $nodeDirectory,
        private readonly BrokerQueue $queue,
        private readonly string $nodeId,
    ) {}

    public function onLog(callable $callback): void
    {
        $this->logCallback = $callback;
    }

    /**
     * Handle a BROKER_FORWARD message: deliver the inner payload to the target node.
     * If the target is unreachable, queue for store-and-forward.
     *
     * @return array ACK message to send back to the sender
     */
    public function handleForward(array $msg, ?string $senderAddress = null): array
    {
        $forwardId = $msg['forward_id'] ?? '';
        $targetNodeId = $msg['target_node_id'] ?? '';
        $innerMessage = $msg['inner_message'] ?? [];
        $priority = $msg['priority'] ?? 0;

        if (!$forwardId || !$targetNodeId || empty($innerMessage)) {
            return $this->ack($forwardId, false, 'Missing required fields');
        }

        // Dedup
        if (isset($this->seenForwards[$forwardId])) {
            return $this->ack($forwardId, true, 'Already processed');
        }
        $this->markSeen($forwardId);

        // Don't route to self
        if ($targetNodeId === $this->nodeId) {
            return $this->ack($forwardId, false, 'Cannot forward to self');
        }

        // Try direct delivery
        if ($this->nodeDirectory->isConnected($targetNodeId)) {
            $sent = $this->mesh->sendTo($targetNodeId, $innerMessage);
            if ($sent) {
                $this->totalForwarded++;
                $this->log("Forwarded message to " . substr($targetNodeId, 0, 8));
                return $this->ack($forwardId, true, 'Delivered');
            }
        }

        // Store-and-forward: queue for later delivery
        $queued = $this->queue->enqueue($targetNodeId, $innerMessage, $priority);
        if ($queued) {
            $this->totalBuffered++;
            $this->log("Buffered message for offline node " . substr($targetNodeId, 0, 8));
            return $this->ack($forwardId, true, 'Queued for delivery');
        }

        return $this->ack($forwardId, false, 'Queue full');
    }

    /**
     * Route a task creation request. If emperor is available, forward directly.
     * Otherwise, buffer in emperor queue for delivery when new emperor elected.
     */
    public function routeTaskCreation(array $taskCreateMsg): bool
    {
        $emperor = $this->nodeDirectory->getEmperor();

        if ($emperor !== null) {
            $sent = $this->mesh->sendTo($emperor->nodeId, $taskCreateMsg);
            if ($sent) {
                $this->totalForwarded++;
                return true;
            }
        }

        // No emperor or delivery failed — buffer
        $queued = $this->queue->enqueueForEmperor($taskCreateMsg);
        if ($queued) {
            $this->totalBuffered++;
            $this->log("Buffered task creation during emperor transition");
        }
        return $queued;
    }

    /**
     * Route an offer-pay protocol message to the relevant counterparty.
     * Determines the target based on message type and content.
     */
    public function routeOfferMessage(array $msg): bool
    {
        $type = $msg['type'] ?? 0;
        $targetNodeId = $this->resolveOfferTarget($type, $msg);

        if (!$targetNodeId) {
            return false;
        }

        if ($this->nodeDirectory->isConnected($targetNodeId)) {
            $sent = $this->mesh->sendTo($targetNodeId, $msg);
            if ($sent) {
                $this->totalOfferRouted++;
                return true;
            }
        }

        // Queue for store-and-forward with high priority
        return $this->queue->enqueue($targetNodeId, $msg, 5);
    }

    /**
     * Route a message to all workers (for task distribution).
     * @return int Number of nodes reached
     */
    public function broadcastToWorkers(array $msg): int
    {
        $workers = $this->nodeDirectory->getWorkers();
        $sent = 0;

        foreach ($workers as $worker) {
            if ($this->mesh->sendTo($worker->nodeId, $msg)) {
                $sent++;
            }
        }

        $this->totalForwarded += $sent;
        return $sent;
    }

    /**
     * Handle BROKER_QUEUE_STATUS request — return queue stats.
     */
    public function handleQueueStatusRequest(array $msg): array
    {
        return [
            'type' => MessageTypes::BROKER_QUEUE_RSP,
            'node_id' => $this->nodeId,
            'stats' => $this->queue->stats(),
            'routing' => [
                'total_forwarded' => $this->totalForwarded,
                'total_buffered' => $this->totalBuffered,
                'total_offer_routed' => $this->totalOfferRouted,
            ],
            'nodes' => [
                'total' => $this->nodeDirectory->count(),
                'connected' => $this->nodeDirectory->connectedCount(),
                'emperor' => $this->nodeDirectory->getEmperor()?->nodeId,
            ],
        ];
    }

    public function stats(): array
    {
        return [
            'total_forwarded' => $this->totalForwarded,
            'total_buffered' => $this->totalBuffered,
            'total_offer_routed' => $this->totalOfferRouted,
            'queue' => $this->queue->stats(),
            'nodes' => [
                'total' => $this->nodeDirectory->count(),
                'connected' => $this->nodeDirectory->connectedCount(),
            ],
        ];
    }

    /**
     * Determine the target node for an offer-pay message.
     */
    private function resolveOfferTarget(int $type, array $msg): ?string
    {
        return match ($type) {
            // Create: route to the target node (to_node_id)
            MessageTypes::OFFER_CREATE => $msg['offer']['to_node_id'] ?? $msg['to_node_id'] ?? null,
            // Accept/Reject: route back to the offer creator (from_node_id)
            MessageTypes::OFFER_ACCEPT,
            MessageTypes::OFFER_REJECT => $msg['from_node_id'] ?? null,
            // Payment: route to the offer target (to_node_id)
            MessageTypes::PAYMENT_INIT => $msg['payment']['to_node_id'] ?? $msg['to_node_id'] ?? null,
            // Payment confirm: route to the payer (from_node_id)
            MessageTypes::PAYMENT_CONFIRM => $msg['from_node_id'] ?? null,
            default => null,
        };
    }

    private function ack(string $forwardId, bool $success, string $reason): array
    {
        return [
            'type' => MessageTypes::BROKER_FORWARD_ACK,
            'forward_id' => $forwardId,
            'node_id' => $this->nodeId,
            'success' => $success,
            'reason' => $reason,
        ];
    }

    private function markSeen(string $forwardId): void
    {
        $this->seenForwards[$forwardId] = true;

        if (count($this->seenForwards) > $this->seenLimit) {
            $this->seenForwards = array_slice($this->seenForwards, -($this->seenLimit / 2), null, true);
        }
    }

    private function log(string $message): void
    {
        if ($this->logCallback) {
            ($this->logCallback)($message);
        }
    }
}
