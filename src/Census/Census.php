<?php

declare(strict_types=1);

namespace ChangeCouncil\Census;

use ChangeCouncil\Audit\AuditLog;
use ChangeCouncil\Models\CensusRequest;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Petitions\DeliveryResult;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The census request queue, the clerk's outbox for censuses. Like petitions,
 * nothing here calls Genie: a request stays `queued` until the clerk sends it
 * with its own `agentinbox send` and records the raw tool result.
 *
 * A request is outstanding until a valid census answers it; while one is
 * outstanding the member gets no second request. Internal members (our own
 * apps, which are never petitioned) and removed members get none.
 */
final class Census
{
    public function __construct(private readonly ConnectionInterface $db, private readonly AuditLog $audit, private readonly string $labUrl = '', private readonly ?FirstParty $firstParty = null) {}

    /**
     * Queues one request per active external member without an outstanding one.
     *
     * @return list<CensusRequest> the requests created now
     */
    public function request(): array
    {
        return $this->db->transaction(function (): array {
            $created = [];
            foreach (Consumer::query()->where('internal', false)->whereNull('removed_at')->orderBy('address')->get() as $consumer) {
                if (CensusRequest::query()->where('agent_address', $consumer->address)->whereNull('answered_at')->whereIn('delivery', ['queued', 'sent'])->exists()) {
                    continue;
                }
                $request = CensusRequest::query()->create([
                    'consumer_id' => $consumer->id,
                    'agent_address' => $consumer->address,
                    'nonce' => bin2hex(random_bytes(16)),
                    'queued_at' => Carbon::now(),
                    'delivery' => 'queued',
                ]);
                $this->audit->append(null, 'clerk', 'census.requested', ['request' => $request->id, 'to' => $consumer->address]);
                $created[] = $request;
            }

            return $created;
        });
    }

    /**
     * Requests still waiting for the clerk to send, with their text. A request
     * whose member was removed since is skipped.
     *
     * @return list<array{request: int, to: string, nonce: string, queued_at: string, text: string}>
     */
    public function queued(): array
    {
        $removed = Consumer::query()->whereNotNull('removed_at')->pluck('address')->all();
        $out = [];
        foreach (CensusRequest::query()->where('delivery', 'queued')->whereNull('answered_at')->orderBy('id')->get() as $request) {
            if (in_array($request->agent_address, $removed, true)) {
                continue;
            }
            $out[] = [
                'request' => $request->id,
                'to' => $request->agent_address,
                'nonce' => $request->nonce,
                'queued_at' => $request->queued_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'text' => CensusText::render($request, $this->labUrl, $this->firstParty),
            ];
        }

        return $out;
    }

    /** Records the clerk's raw `agentinbox send` result for a request. */
    public function markSent(CensusRequest $request, string $rawResult): CensusRequest
    {
        $result = DeliveryResult::parse($rawResult);

        return $this->db->transaction(function () use ($request, $result): CensusRequest {
            $request = CensusRequest::query()->lockForUpdate()->findOrFail($request->id);
            $request->forceFill([
                'delivery' => $result->delivery,
                'delivery_detail' => $result->detail,
                'sent_at' => $result->delivery === 'sent' ? Carbon::now() : null,
            ])->save();
            $this->audit->append(null, 'clerk', 'census.'.$result->delivery, ['request' => $request->id, 'to' => $request->agent_address]);

            return $request;
        });
    }
}
