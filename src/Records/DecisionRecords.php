<?php

declare(strict_types=1);

namespace ChangeCouncil\Records;

use ChangeCouncil\Audit\AuditLog;
use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Models\Round;

/**
 * The committed decision record (design 8.2), schema change-council/decision.v1:
 * what a release gate can verify with no access to the lab. It carries counts,
 * never free text from any reply. Phase 2 writes and verifies; the gate that
 * requires one is Phase 3.
 */
final class DecisionRecords
{
    public const SCHEMA = 'change-council/decision.v1';

    public const OUTCOMES = ['approve', 'conflict', 'abstain', 'silent', 'unreachable', 'undelivered', 'invalid'];

    public function __construct(private readonly RecordSigner $signer, private readonly AuditLog $audit) {}

    public function build(Change $change): array
    {
        $fingerprints = [];
        foreach ((array) ($change->evidence['findings'] ?? []) as $finding) {
            if (is_array($finding) && is_string($finding['fingerprint'] ?? null) && empty($finding['waived'])) {
                $fingerprints[] = $finding['fingerprint'];
            }
        }
        $waivers = [];
        foreach ((array) ($change->evidence['findings'] ?? []) as $finding) {
            if (is_array($finding) && ! empty($finding['waived']) && is_string($finding['fingerprint'] ?? null)) {
                $waivers[] = ['fingerprint' => $finding['fingerprint'], 'kind' => (string) ($finding['waived']['kind'] ?? 'unspecified')];
            }
        }
        $rounds = [];
        foreach (Round::query()->where('change_id', $change->id)->orderBy('number')->get() as $round) {
            $outcomes = array_fill_keys(self::OUTCOMES, 0);
            foreach (Petition::query()->where('round_id', $round->id)->get() as $petition) {
                $o = $petition->outcome ?? 'silent';
                $outcomes[$o] = ($outcomes[$o] ?? 0) + 1;
            }
            $rounds[] = ['number' => $round->number, 'digest' => $round->approach_digest, 'outcomes' => $outcomes];
        }

        return $this->signer->sign([
            'schema' => self::SCHEMA,
            'ref' => $change->ref,
            'origin' => $change->origin,
            'state' => $change->state,
            'decided_by' => $change->decided_by,
            'decided_at' => $change->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'approved_unopposed' => (bool) $change->approved_unopposed,
            'packages' => array_values(array_map(fn (array $p) => ['ecosystem' => (string) $p['ecosystem'], 'name' => (string) $p['name']], $change->packages)),
            'covers' => [
                'versions' => isset($change->links['versions']) && is_string($change->links['versions']) ? $change->links['versions'] : null,
                'fingerprints' => array_values(array_unique($fingerprints)),
            ],
            'waivers' => $waivers,
            'rounds' => $rounds,
            'ledger_head' => $this->audit->head(),
        ]);
    }

    /** Writes `<repo>/.council/decisions/<ref>.json` and returns the path. */
    public function export(Change $change, string $repoPath): string
    {
        if ($change->state !== States::APPROVED) {
            throw new Refused('not_approved', "{$change->ref} is {$change->state}; only an approved Change has a decision record to commit.");
        }
        if (! is_dir($repoPath)) {
            throw new Refused('no_such_directory', "{$repoPath} is not a directory.");
        }
        $dir = rtrim($repoPath, '/\\').'/.council/decisions';
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new Refused('not_writable', "Could not create {$dir}.");
        }
        $path = $dir.'/'.$change->ref.'.json';
        file_put_contents($path, json_encode($this->build($change), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        return $path;
    }

    /** @return array{ok: bool, reason: ?string} */
    public function verify(array $record): array
    {
        if (($record['schema'] ?? null) !== self::SCHEMA) {
            return ['ok' => false, 'reason' => 'unknown_schema'];
        }

        return $this->signer->verify($record);
    }
}
