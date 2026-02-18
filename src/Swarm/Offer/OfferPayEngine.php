<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Offer;

use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Core engine for the Offer-Pay protocol.
 *
 * Handles bidirectional offer/payment communication between nodes,
 * validates offer conditions, and maintains transaction history.
 * Gossips state changes across the P2P mesh with dedup.
 */
class OfferPayEngine
{
    /** @var array<string, OfferModel> In-memory offer cache */
    private array $offers = [];

    /** @var array<string, PaymentModel> In-memory payment cache */
    private array $payments = [];

    /** @var array<string, true> Seen gossip messages for dedup */
    private array $seenMessages = [];

    private int $seenLimit = 5000;

    public function __construct(
        private readonly string $nodeId,
        private readonly TcpMesh $mesh,
        private readonly SwarmDatabase $db,
        private readonly LamportClock $clock,
    ) {
        $this->loadFromDb();
    }

    // --- Offer lifecycle ---

    /**
     * Create a new offer and broadcast it to the mesh.
     */
    public function createOffer(
        string $toNodeId,
        int $amount,
        string $conditions = '',
        string $currency = 'VOID',
        int $validitySeconds = 300,
        ?string $taskId = null,
    ): OfferModel|string {
        $offer = OfferModel::create(
            fromNodeId: $this->nodeId,
            toNodeId: $toNodeId,
            amount: $amount,
            lamportTs: $this->clock->tick(),
            currency: $currency,
            conditions: $conditions,
            validitySeconds: $validitySeconds,
            taskId: $taskId,
        );

        $error = $offer->validate();
        if ($error !== null) {
            return $error;
        }

        $this->offers[$offer->id] = $offer;
        $this->db->insertOffer($offer);
        $this->markSeen($offer->id, 'create');

        $this->mesh->broadcast([
            'type' => MessageTypes::OFFER_CREATE,
            'offer' => $offer->toArray(),
        ]);

        return $offer;
    }

    /**
     * Accept an incoming offer. Only the recipient can accept.
     */
    public function acceptOffer(string $offerId, ?string $reason = null): OfferModel|string
    {
        $offer = $this->getOffer($offerId);
        if (!$offer) {
            return "Offer not found: {$offerId}";
        }
        if ($offer->toNodeId !== $this->nodeId) {
            return 'Only the recipient can accept an offer';
        }
        if (!$offer->status->canAccept()) {
            return "Cannot accept offer in status: {$offer->status->value}";
        }
        if ($offer->isExpired()) {
            $this->transitionOffer($offer, OfferStatus::Expired);
            return 'Offer has expired';
        }

        $updated = $this->transitionOffer($offer, OfferStatus::Accepted, $reason);

        $this->mesh->broadcast([
            'type' => MessageTypes::OFFER_ACCEPT,
            'offer_id' => $updated->id,
            'from_node_id' => $updated->fromNodeId,
            'to_node_id' => $updated->toNodeId,
            'status' => $updated->status->value,
            'lamport_ts' => $updated->lamportTs,
            'response_reason' => $reason,
        ]);

        return $updated;
    }

    /**
     * Reject an incoming offer. Only the recipient can reject.
     */
    public function rejectOffer(string $offerId, ?string $reason = null): OfferModel|string
    {
        $offer = $this->getOffer($offerId);
        if (!$offer) {
            return "Offer not found: {$offerId}";
        }
        if ($offer->toNodeId !== $this->nodeId) {
            return 'Only the recipient can reject an offer';
        }
        if (!$offer->status->canReject()) {
            return "Cannot reject offer in status: {$offer->status->value}";
        }

        $updated = $this->transitionOffer($offer, OfferStatus::Rejected, $reason);

        $this->mesh->broadcast([
            'type' => MessageTypes::OFFER_REJECT,
            'offer_id' => $updated->id,
            'from_node_id' => $updated->fromNodeId,
            'to_node_id' => $updated->toNodeId,
            'status' => $updated->status->value,
            'lamport_ts' => $updated->lamportTs,
            'response_reason' => $reason,
        ]);

        return $updated;
    }

    // --- Payment lifecycle ---

    /**
     * Initiate a payment for an accepted offer. Only the offer creator can pay.
     */
    public function initiatePayment(string $offerId): PaymentModel|string
    {
        $offer = $this->getOffer($offerId);
        if (!$offer) {
            return "Offer not found: {$offerId}";
        }
        if ($offer->fromNodeId !== $this->nodeId) {
            return 'Only the offer creator can initiate payment';
        }
        if (!$offer->status->canPay()) {
            return "Cannot pay for offer in status: {$offer->status->value}";
        }

        $this->transitionOffer($offer, OfferStatus::PaymentPending);

        $payment = PaymentModel::create(
            offerId: $offer->id,
            fromNodeId: $offer->fromNodeId,
            toNodeId: $offer->toNodeId,
            amount: $offer->amount,
            lamportTs: $this->clock->tick(),
            currency: $offer->currency,
        );

        $this->payments[$payment->id] = $payment;
        $this->db->insertPayment($payment);
        $this->markSeen($payment->id, 'create');

        $this->mesh->broadcast([
            'type' => MessageTypes::PAYMENT_INIT,
            'payment' => $payment->toArray(),
        ]);

        return $payment;
    }

    /**
     * Confirm a payment. Only the recipient can confirm.
     */
    public function confirmPayment(string $paymentId): PaymentModel|string
    {
        $payment = $this->getPayment($paymentId);
        if (!$payment) {
            return "Payment not found: {$paymentId}";
        }
        if ($payment->toNodeId !== $this->nodeId) {
            return 'Only the recipient can confirm payment';
        }
        if ($payment->status->isTerminal()) {
            return "Payment already in terminal state: {$payment->status->value}";
        }

        $updated = $payment->withStatus(PaymentStatus::Confirmed, $this->clock->tick());
        $this->payments[$updated->id] = $updated;
        $this->db->updatePayment($updated);
        $this->markSeen($updated->id, 'confirm');

        // Mark the associated offer as paid
        $offer = $this->getOffer($updated->offerId);
        if ($offer) {
            $this->transitionOffer($offer, OfferStatus::Paid);
        }

        $this->mesh->broadcast([
            'type' => MessageTypes::PAYMENT_CONFIRM,
            'payment_id' => $updated->id,
            'offer_id' => $updated->offerId,
            'status' => $updated->status->value,
            'lamport_ts' => $updated->lamportTs,
        ]);

        return $updated;
    }

    // --- Gossip receivers ---

    public function receiveOfferCreate(array $msg, ?string $senderAddress = null): ?OfferModel
    {
        $data = $msg['offer'] ?? $msg;
        $id = $data['id'] ?? '';
        if (!$id || $this->hasSeen($id, 'create')) {
            return null;
        }

        $this->clock->witness($data['lamport_ts'] ?? 0);
        $offer = OfferModel::fromArray($data);
        $this->offers[$offer->id] = $offer;
        $this->db->insertOffer($offer);
        $this->markSeen($id, 'create');

        $this->mesh->broadcast([
            'type' => MessageTypes::OFFER_CREATE,
            'offer' => $offer->toArray(),
        ], $senderAddress);

        return $offer;
    }

    public function receiveOfferAccept(array $msg, ?string $senderAddress = null): ?OfferModel
    {
        $offerId = $msg['offer_id'] ?? '';
        $key = $offerId . ':accept';
        if (!$offerId || isset($this->seenMessages[$key])) {
            return null;
        }

        $this->clock->witness($msg['lamport_ts'] ?? 0);
        $offer = $this->getOffer($offerId);
        if (!$offer) {
            return null;
        }

        $updated = $offer->withStatus(
            OfferStatus::Accepted,
            $msg['lamport_ts'] ?? $this->clock->tick(),
            $msg['response_reason'] ?? null,
        );
        $this->offers[$updated->id] = $updated;
        $this->db->updateOffer($updated);
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast($msg + ['type' => MessageTypes::OFFER_ACCEPT], $senderAddress);

        return $updated;
    }

    public function receiveOfferReject(array $msg, ?string $senderAddress = null): ?OfferModel
    {
        $offerId = $msg['offer_id'] ?? '';
        $key = $offerId . ':reject';
        if (!$offerId || isset($this->seenMessages[$key])) {
            return null;
        }

        $this->clock->witness($msg['lamport_ts'] ?? 0);
        $offer = $this->getOffer($offerId);
        if (!$offer) {
            return null;
        }

        $updated = $offer->withStatus(
            OfferStatus::Rejected,
            $msg['lamport_ts'] ?? $this->clock->tick(),
            $msg['response_reason'] ?? null,
        );
        $this->offers[$updated->id] = $updated;
        $this->db->updateOffer($updated);
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast($msg + ['type' => MessageTypes::OFFER_REJECT], $senderAddress);

        return $updated;
    }

    public function receivePaymentInit(array $msg, ?string $senderAddress = null): ?PaymentModel
    {
        $data = $msg['payment'] ?? $msg;
        $id = $data['id'] ?? '';
        if (!$id || $this->hasSeen($id, 'create')) {
            return null;
        }

        $this->clock->witness($data['lamport_ts'] ?? 0);
        $payment = PaymentModel::fromArray($data);
        $this->payments[$payment->id] = $payment;
        $this->db->insertPayment($payment);
        $this->markSeen($id, 'create');

        // Update offer status on receiving node
        $offer = $this->getOffer($payment->offerId);
        if ($offer && $offer->status === OfferStatus::Accepted) {
            $this->transitionOffer($offer, OfferStatus::PaymentPending);
        }

        $this->mesh->broadcast([
            'type' => MessageTypes::PAYMENT_INIT,
            'payment' => $payment->toArray(),
        ], $senderAddress);

        return $payment;
    }

    public function receivePaymentConfirm(array $msg, ?string $senderAddress = null): ?PaymentModel
    {
        $paymentId = $msg['payment_id'] ?? '';
        $key = $paymentId . ':confirm';
        if (!$paymentId || isset($this->seenMessages[$key])) {
            return null;
        }

        $this->clock->witness($msg['lamport_ts'] ?? 0);
        $payment = $this->getPayment($paymentId);
        if (!$payment) {
            return null;
        }

        $updated = $payment->withStatus(PaymentStatus::Confirmed, $msg['lamport_ts'] ?? $this->clock->tick());
        $this->payments[$updated->id] = $updated;
        $this->db->updatePayment($updated);
        $this->seenMessages[$key] = true;

        // Mark the associated offer as paid
        $offer = $this->getOffer($updated->offerId);
        if ($offer) {
            $this->transitionOffer($offer, OfferStatus::Paid);
        }

        $this->mesh->broadcast($msg + ['type' => MessageTypes::PAYMENT_CONFIRM], $senderAddress);

        return $updated;
    }

    // --- Query methods ---

    public function getOffer(string $id): ?OfferModel
    {
        return $this->offers[$id] ?? $this->db->getOffer($id);
    }

    public function getPayment(string $id): ?PaymentModel
    {
        return $this->payments[$id] ?? $this->db->getPayment($id);
    }

    public function getPaymentByOffer(string $offerId): ?PaymentModel
    {
        foreach ($this->payments as $payment) {
            if ($payment->offerId === $offerId) {
                return $payment;
            }
        }
        return $this->db->getPaymentByOffer($offerId);
    }

    /** @return OfferModel[] */
    public function getOffersSent(): array
    {
        return array_values(array_filter(
            $this->offers,
            fn(OfferModel $o) => $o->fromNodeId === $this->nodeId,
        ));
    }

    /** @return OfferModel[] */
    public function getOffersReceived(): array
    {
        return array_values(array_filter(
            $this->offers,
            fn(OfferModel $o) => $o->toNodeId === $this->nodeId,
        ));
    }

    /** @return OfferModel[] */
    public function getAllOffers(): array
    {
        return array_values($this->offers);
    }

    /** @return PaymentModel[] */
    public function getAllPayments(): array
    {
        return array_values($this->payments);
    }

    /**
     * Get complete transaction history for this node.
     * @return array{offers: array, payments: array, summary: array}
     */
    public function getTransactionHistory(): array
    {
        $sent = $this->getOffersSent();
        $received = $this->getOffersReceived();
        $payments = $this->getAllPayments();

        $totalPaid = 0;
        $totalReceived = 0;
        foreach ($payments as $p) {
            if ($p->status !== PaymentStatus::Confirmed) {
                continue;
            }
            if ($p->fromNodeId === $this->nodeId) {
                $totalPaid += $p->amount;
            }
            if ($p->toNodeId === $this->nodeId) {
                $totalReceived += $p->amount;
            }
        }

        return [
            'offers_sent' => array_map(fn($o) => $o->toArray(), $sent),
            'offers_received' => array_map(fn($o) => $o->toArray(), $received),
            'payments' => array_map(fn($p) => $p->toArray(), $payments),
            'summary' => [
                'total_offers_sent' => count($sent),
                'total_offers_received' => count($received),
                'total_payments' => count($payments),
                'total_paid' => $totalPaid,
                'total_received' => $totalReceived,
                'net_balance' => $totalReceived - $totalPaid,
            ],
        ];
    }

    // --- Internal helpers ---

    private function transitionOffer(OfferModel $offer, OfferStatus $status, ?string $reason = null): OfferModel
    {
        $updated = $offer->withStatus($status, $this->clock->tick(), $reason);
        $this->offers[$updated->id] = $updated;
        $this->db->updateOffer($updated);
        return $updated;
    }

    private function markSeen(string $id, string $action): void
    {
        $key = "{$id}:{$action}";
        $this->seenMessages[$key] = true;

        if (count($this->seenMessages) > $this->seenLimit) {
            $this->seenMessages = array_slice($this->seenMessages, -($this->seenLimit / 2), null, true);
        }
    }

    private function hasSeen(string $id, string $action): bool
    {
        return isset($this->seenMessages["{$id}:{$action}"]);
    }

    private function loadFromDb(): void
    {
        foreach ($this->db->getOffersSince(0) as $offer) {
            $this->offers[$offer->id] = $offer;
        }
        foreach ($this->db->getPaymentsSince(0) as $payment) {
            $this->payments[$payment->id] = $payment;
        }
    }
}
