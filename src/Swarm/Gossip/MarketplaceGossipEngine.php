<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Gossip;

use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Galactic\BountyModel;
use VoidLux\Swarm\Galactic\CapabilityProfile;
use VoidLux\Swarm\Galactic\GalacticMarketplace;
use VoidLux\Swarm\Galactic\OfferingModel;
use VoidLux\Swarm\Galactic\TaskDelegation;
use VoidLux\Swarm\Galactic\TributeModel;

/**
 * Push-based gossip dissemination for cross-swarm marketplace messages.
 *
 * Handles: offerings, tributes, capability advertisements, bounties,
 * and task delegations. Follows the same dedup/forward pattern as
 * TaskGossipEngine. All messages carry Lamport timestamps for ordering.
 */
class MarketplaceGossipEngine
{
    /** @var array<string, true> Seen message keys for dedup */
    private array $seenMessages = [];
    private int $seenLimit = 10000;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly LamportClock $clock,
        private readonly GalacticMarketplace $marketplace,
    ) {}

    // ─── Offering gossip ───────────────────────────────────────

    public function gossipOfferingAnnounce(OfferingModel $offering): void
    {
        $key = 'offering:' . $offering->id;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::OFFERING_ANNOUNCE,
            'offering' => $offering->toArray(),
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveOfferingAnnounce(array $msg, ?string $senderAddress = null): ?OfferingModel
    {
        $offeringData = $msg['offering'] ?? $msg;
        $id = $offeringData['id'] ?? '';
        $key = 'offering:' . $id;

        if (!$id || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $offering = $this->marketplace->receiveOffering($offeringData);
        if ($offering) {
            $this->mesh->broadcast($msg + ['type' => MessageTypes::OFFERING_ANNOUNCE], $senderAddress);
        }

        $this->pruneSeenMessages();
        return $offering;
    }

    public function gossipOfferingWithdraw(string $offeringId): void
    {
        $key = 'offering_withdraw:' . $offeringId;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::OFFERING_WITHDRAW,
            'offering_id' => $offeringId,
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveOfferingWithdraw(array $msg, ?string $senderAddress = null): bool
    {
        $offeringId = $msg['offering_id'] ?? '';
        $key = 'offering_withdraw:' . $offeringId;

        if (!$offeringId || isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $withdrawn = $this->marketplace->receiveWithdraw($msg);
        if ($withdrawn) {
            $this->mesh->broadcast($msg + ['type' => MessageTypes::OFFERING_WITHDRAW], $senderAddress);
        }

        $this->pruneSeenMessages();
        return $withdrawn;
    }

    // ─── Tribute gossip ────────────────────────────────────────

    public function gossipTributeRequest(TributeModel $tribute): void
    {
        $key = 'tribute:' . $tribute->id;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::TRIBUTE_REQUEST,
            'tribute' => $tribute->toArray(),
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveTributeRequest(array $msg, ?string $senderAddress = null): ?TributeModel
    {
        $tributeData = $msg['tribute'] ?? $msg;
        $id = $tributeData['id'] ?? '';
        $key = 'tribute:' . $id;

        if (!$id || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $tribute = $this->marketplace->receiveTributeRequest($tributeData);
        if ($tribute) {
            $this->mesh->broadcast($msg + ['type' => MessageTypes::TRIBUTE_REQUEST], $senderAddress);
        }

        $this->pruneSeenMessages();
        return $tribute;
    }

    public function gossipTributeAccept(string $tributeId, string $nodeId): void
    {
        $key = 'tribute_accept:' . $tributeId;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::TRIBUTE_ACCEPT,
            'tribute_id' => $tributeId,
            'node_id' => $nodeId,
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveTributeAccept(array $msg, ?string $senderAddress = null): bool
    {
        $tributeId = $msg['tribute_id'] ?? '';
        $key = 'tribute_accept:' . $tributeId;

        if (!$tributeId || isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $accepted = $this->marketplace->acceptTribute($tributeId);
        if ($accepted) {
            $this->mesh->broadcast($msg + ['type' => MessageTypes::TRIBUTE_ACCEPT], $senderAddress);
        }

        $this->pruneSeenMessages();
        return $accepted;
    }

    public function gossipTributeReject(string $tributeId, string $nodeId): void
    {
        $key = 'tribute_reject:' . $tributeId;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::TRIBUTE_REJECT,
            'tribute_id' => $tributeId,
            'node_id' => $nodeId,
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveTributeReject(array $msg, ?string $senderAddress = null): bool
    {
        $tributeId = $msg['tribute_id'] ?? '';
        $key = 'tribute_reject:' . $tributeId;

        if (!$tributeId || isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $rejected = $this->marketplace->rejectTribute($tributeId);
        if ($rejected) {
            $this->mesh->broadcast($msg + ['type' => MessageTypes::TRIBUTE_REJECT], $senderAddress);
        }

        $this->pruneSeenMessages();
        return $rejected;
    }

    // ─── Capability advertisement gossip ───────────────────────

    public function gossipCapabilityAdvertise(CapabilityProfile $profile): void
    {
        $key = 'cap:' . $profile->nodeId . ':' . $profile->lamportTs;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::CAPABILITY_ADVERTISE,
            'profile' => $profile->toArray(),
            'lamport_ts' => $profile->lamportTs,
        ]);
    }

    public function receiveCapabilityAdvertise(array $msg, ?string $senderAddress = null): ?CapabilityProfile
    {
        $profileData = $msg['profile'] ?? $msg;
        $nodeId = $profileData['node_id'] ?? '';
        $ts = (int) ($msg['lamport_ts'] ?? 0);
        $key = 'cap:' . $nodeId . ':' . $ts;

        if (!$nodeId || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($ts);

        $profile = CapabilityProfile::fromArray($profileData);
        $this->marketplace->receiveCapabilityProfile($profile);

        $this->mesh->broadcast($msg + ['type' => MessageTypes::CAPABILITY_ADVERTISE], $senderAddress);
        $this->pruneSeenMessages();
        return $profile;
    }

    public function gossipCapabilityQuery(array $requiredCapabilities, string $queryId): void
    {
        $key = 'cap_query:' . $queryId;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::CAPABILITY_QUERY,
            'query_id' => $queryId,
            'required_capabilities' => $requiredCapabilities,
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveCapabilityQuery(array $msg, ?string $senderAddress = null): ?string
    {
        $queryId = $msg['query_id'] ?? '';
        $key = 'cap_query:' . $queryId;

        if (!$queryId || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        // Forward the query
        $this->mesh->broadcast($msg + ['type' => MessageTypes::CAPABILITY_QUERY], $senderAddress);
        $this->pruneSeenMessages();

        // Return query ID so caller can respond with local profile if it matches
        return $queryId;
    }

    public function gossipCapabilityQueryResponse(string $queryId, CapabilityProfile $profile, string $targetNodeId): void
    {
        $key = 'cap_query_rsp:' . $queryId . ':' . $profile->nodeId;
        $this->seenMessages[$key] = true;

        // Directed response: send to specific node
        $this->mesh->sendTo($targetNodeId, [
            'type' => MessageTypes::CAPABILITY_QUERY_RSP,
            'query_id' => $queryId,
            'profile' => $profile->toArray(),
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveCapabilityQueryResponse(array $msg, ?string $senderAddress = null): ?CapabilityProfile
    {
        $queryId = $msg['query_id'] ?? '';
        $nodeId = $msg['profile']['node_id'] ?? '';
        $key = 'cap_query_rsp:' . $queryId . ':' . $nodeId;

        if (!$queryId || !$nodeId || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $profile = CapabilityProfile::fromArray($msg['profile']);
        $this->marketplace->receiveCapabilityProfile($profile);

        $this->pruneSeenMessages();
        return $profile;
    }

    // ─── Bounty gossip ─────────────────────────────────────────

    public function gossipBountyPost(BountyModel $bounty): void
    {
        $key = 'bounty:' . $bounty->id;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::BOUNTY_POST,
            'bounty' => $bounty->toArray(),
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveBountyPost(array $msg, ?string $senderAddress = null): ?BountyModel
    {
        $bountyData = $msg['bounty'] ?? $msg;
        $id = $bountyData['id'] ?? '';
        $key = 'bounty:' . $id;

        if (!$id || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $bounty = $this->marketplace->receiveBounty($bountyData);
        if ($bounty) {
            $this->mesh->broadcast($msg + ['type' => MessageTypes::BOUNTY_POST], $senderAddress);
        }

        $this->pruneSeenMessages();
        return $bounty;
    }

    public function gossipBountyClaim(string $bountyId, string $claimerNodeId): void
    {
        $key = 'bounty_claim:' . $bountyId . ':' . $claimerNodeId;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::BOUNTY_CLAIM,
            'bounty_id' => $bountyId,
            'claimer_node_id' => $claimerNodeId,
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveBountyClaim(array $msg, ?string $senderAddress = null): bool
    {
        $bountyId = $msg['bounty_id'] ?? '';
        $claimerNodeId = $msg['claimer_node_id'] ?? '';
        $key = 'bounty_claim:' . $bountyId . ':' . $claimerNodeId;

        if (!$bountyId || !$claimerNodeId || isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $claimed = $this->marketplace->claimBounty($bountyId, $claimerNodeId);
        if ($claimed) {
            $this->mesh->broadcast($msg + ['type' => MessageTypes::BOUNTY_CLAIM], $senderAddress);
        }

        $this->pruneSeenMessages();
        return $claimed;
    }

    public function gossipBountyCancel(string $bountyId): void
    {
        $key = 'bounty_cancel:' . $bountyId;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::BOUNTY_CANCEL,
            'bounty_id' => $bountyId,
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveBountyCancel(array $msg, ?string $senderAddress = null): bool
    {
        $bountyId = $msg['bounty_id'] ?? '';
        $key = 'bounty_cancel:' . $bountyId;

        if (!$bountyId || isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $cancelled = $this->marketplace->cancelBounty($bountyId);
        if ($cancelled) {
            $this->mesh->broadcast($msg + ['type' => MessageTypes::BOUNTY_CANCEL], $senderAddress);
        }

        $this->pruneSeenMessages();
        return $cancelled;
    }

    // ─── Task delegation gossip ────────────────────────────────

    public function gossipTaskDelegate(TaskDelegation $delegation): void
    {
        $key = 'delegate:' . $delegation->id;
        $this->seenMessages[$key] = true;

        // Directed: send to target node, not broadcast
        $this->mesh->sendTo($delegation->targetNodeId, [
            'type' => MessageTypes::TASK_DELEGATE,
            'delegation' => $delegation->toArray(),
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveTaskDelegate(array $msg, ?string $senderAddress = null): ?TaskDelegation
    {
        $delegationData = $msg['delegation'] ?? $msg;
        $id = $delegationData['id'] ?? '';
        $key = 'delegate:' . $id;

        if (!$id || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $delegation = TaskDelegation::fromArray($delegationData);
        $this->marketplace->receiveDelegation($delegation);

        $this->pruneSeenMessages();
        return $delegation;
    }

    public function gossipTaskDelegateResponse(string $delegationId, string $sourceNodeId, bool $accepted, ?string $remoteTaskId = null, ?string $reason = null): void
    {
        $key = 'delegate_rsp:' . $delegationId;
        $this->seenMessages[$key] = true;

        // Directed: send back to source node
        $this->mesh->sendTo($sourceNodeId, [
            'type' => MessageTypes::TASK_DELEGATE_RSP,
            'delegation_id' => $delegationId,
            'accepted' => $accepted,
            'remote_task_id' => $remoteTaskId,
            'reason' => $reason,
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveTaskDelegateResponse(array $msg, ?string $senderAddress = null): ?array
    {
        $delegationId = $msg['delegation_id'] ?? '';
        $key = 'delegate_rsp:' . $delegationId;

        if (!$delegationId || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $accepted = (bool) ($msg['accepted'] ?? false);
        if ($accepted) {
            $this->marketplace->acceptDelegation($delegationId, $msg['remote_task_id'] ?? '');
        } else {
            $this->marketplace->rejectDelegation($delegationId, $msg['reason'] ?? 'rejected');
        }

        $this->pruneSeenMessages();
        return [
            'delegation_id' => $delegationId,
            'accepted' => $accepted,
            'remote_task_id' => $msg['remote_task_id'] ?? null,
            'reason' => $msg['reason'] ?? null,
        ];
    }

    public function gossipTaskDelegateResult(string $delegationId, string $sourceNodeId, ?string $result, ?string $error): void
    {
        $key = 'delegate_result:' . $delegationId;
        $this->seenMessages[$key] = true;

        // Directed: send back to source node
        $this->mesh->sendTo($sourceNodeId, [
            'type' => MessageTypes::TASK_DELEGATE_RESULT,
            'delegation_id' => $delegationId,
            'result' => $result,
            'error' => $error,
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    public function receiveTaskDelegateResult(array $msg, ?string $senderAddress = null): ?array
    {
        $delegationId = $msg['delegation_id'] ?? '';
        $key = 'delegate_result:' . $delegationId;

        if (!$delegationId || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        if ($msg['error'] ?? null) {
            $this->marketplace->failDelegation($delegationId, $msg['error']);
        } else {
            $this->marketplace->completeDelegation($delegationId, $msg['result'] ?? '');
        }

        $this->pruneSeenMessages();
        return [
            'delegation_id' => $delegationId,
            'result' => $msg['result'] ?? null,
            'error' => $msg['error'] ?? null,
        ];
    }

    // ─── Anti-entropy sync ─────────────────────────────────────

    /**
     * Build a marketplace sync request payload.
     */
    public function buildSyncRequest(): array
    {
        return [
            'type' => MessageTypes::MARKETPLACE_SYNC_REQ,
            'lamport_ts' => $this->clock->tick(),
        ];
    }

    /**
     * Build a marketplace sync response with all current marketplace state.
     */
    public function buildSyncResponse(): array
    {
        return [
            'type' => MessageTypes::MARKETPLACE_SYNC_RSP,
            'offerings' => array_map(fn($o) => $o->toArray(), $this->marketplace->getOfferings()),
            'bounties' => array_map(fn($b) => $b->toArray(), $this->marketplace->getBounties()),
            'capability_profiles' => array_map(fn($p) => $p->toArray(), $this->marketplace->getCapabilityProfiles()),
            'tributes' => array_map(fn($t) => $t->toArray(), $this->marketplace->getTributes()),
            'lamport_ts' => $this->clock->tick(),
        ];
    }

    /**
     * Process a sync response, merging remote marketplace state into local.
     */
    public function receiveSyncResponse(array $msg): void
    {
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        foreach ($msg['offerings'] ?? [] as $offeringData) {
            $this->marketplace->receiveOffering($offeringData);
        }
        foreach ($msg['bounties'] ?? [] as $bountyData) {
            $this->marketplace->receiveBounty($bountyData);
        }
        foreach ($msg['capability_profiles'] ?? [] as $profileData) {
            $profile = CapabilityProfile::fromArray($profileData);
            $this->marketplace->receiveCapabilityProfile($profile);
        }
        foreach ($msg['tributes'] ?? [] as $tributeData) {
            $this->marketplace->receiveTributeRequest($tributeData);
        }
    }

    // ─── Helpers ───────────────────────────────────────────────

    private function pruneSeenMessages(): void
    {
        if (count($this->seenMessages) > $this->seenLimit) {
            $this->seenMessages = array_slice($this->seenMessages, -($this->seenLimit / 2), null, true);
        }
    }
}
