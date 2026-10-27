<?php

declare(strict_types=1);

namespace ChangeCouncil\Petitions;

use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Models\Round;

/**
 * The COUNCIL-PETITION v1 text (design 9.2). Plain data: it contains no
 * instruction beyond the reply format, so a petition forwarded by mistake
 * cannot make an agent do anything.
 */
final class PetitionText
{
    public static function render(Change $change, Round $round, Petition $petition, string $labUrl): string
    {
        $deadline = $round->deadline()->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        $header = [
            'COUNCIL-PETITION v1',
            'change: '.$change->ref,
            'round: '.$round->number,
            'nonce: '.$petition->nonce,
            'digest: '.$round->approach_digest,
            'reply-by: '.$deadline,
            'tier: '.$petition->tier,
            'why-you: '.$petition->why,
            'lab: '.rtrim($labUrl, '/').'/changes/'.$change->ref,
        ];

        $packages = array_map(function (array $p): string {
            $move = isset($p['from']) || isset($p['to']) ? ' '.($p['from'] ?? '?').' -> '.($p['to'] ?? '?') : '';

            return $p['name'].$move.' ('.$p['ecosystem'].')';
        }, $change->packages);
        $links = [];
        foreach ($change->links as $key => $value) {
            if (is_scalar($value)) {
                $links[] = $key.': '.$value;
            }
        }
        $findings = count((array) ($change->evidence['findings'] ?? []));

        $summary = [
            $change->title,
            'Packages: '.implode('; ', $packages),
            'Classes: '.implode(', ', $change->classes),
            'What changes: '.$change->summary,
            "Proposed approach (round {$round->number}, frozen by the digest above):",
            $round->approach,
            'What a consumer must DO: read the approach; if it breaks your app, reply `conflict` with the affected app, paths and severity.',
            'Evidence: '.($links === [] ? 'none linked' : implode('; ', $links)).'; detector findings: '.$findings,
        ];

        $example = '{"change":"'.$change->ref.'","round":'.$round->number.',"nonce":"'.$petition->nonce.'",'."\n"
            .' "digest":"'.$round->approach_digest.'","verdict":"approve|conflict|abstain",'."\n"
            .' "impact":[{"app":"...","paths":["..."],"severity":"blocker|major|minor","note":"..."}],'."\n"
            .' "alternative":"...","reason":"..."}';

        return implode("\n", $header)."\n----\n"
            .str_replace("\n----\n", "\n- - -\n", implode("\n", $summary))."\n----\n"
            ."Reply with a message whose FIRST line is `COUNCIL-REPLY v1`, followed by ONE\n"
            ."JSON object, exactly as below. Nothing else in the message is read.\n"
            .$example."\n";
    }
}
