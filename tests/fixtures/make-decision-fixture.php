<?php

declare(strict_types=1);

/*
 * Regenerates the cross-language decision-record fixture:
 *   tests/fixtures/decision-record.json  a record signed by RecordSigner
 *   tests/fixtures/decision-keys.json    {key_id: base64 public key}
 *
 * Both suites verify the SAME fixture: Pest (RecordSigner) and node --test
 * (council/lib/record.mjs). `fixture_text` exercises the canonical-JSON corners
 * where PHP and JavaScript could disagree: non-ASCII, a slash, a control
 * character, a newline, a quote and U+2028.
 *
 *   php tests/fixtures/make-decision-fixture.php
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use ChangeCouncil\Records\RecordSigner;

$pair = RecordSigner::generate();
$signer = new RecordSigner($pair['secret']);
$record = $signer->sign([
    'schema' => 'change-council/decision.v1',
    'ref' => 'CC-fancy-0007',
    'origin' => 'fancy',
    'state' => 'approved',
    'decided_by' => 'tally',
    'decided_at' => '2026-10-30T12:00:00Z',
    'approved_unopposed' => false,
    'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/fancy-flow-php']],
    'covers' => ['versions' => '>=0.52.0 <0.53.0', 'fingerprints' => [hash('sha256', 'schema|example||')]],
    'waivers' => [['fingerprint' => hash('sha256', 'third-party-major|dev||'), 'kind' => 'dev-only']],
    'rounds' => [['number' => 1, 'digest' => 'sha256:'.hash('sha256', 'approach'), 'outcomes' => ['approve' => 2, 'conflict' => 0, 'abstain' => 1, 'silent' => 0, 'unreachable' => 0, 'undelivered' => 0, 'invalid' => 0]]],
    'ledger_head' => hash('sha256', 'head'),
    'fixture_text' => "caf\u{e9} / tab\t ctrl\u{1f} nl\n quote\" ls\u{2028}",
]);

$dir = __DIR__;
file_put_contents($dir.'/decision-record.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
file_put_contents($dir.'/decision-keys.json', json_encode([$pair['key_id'] => $pair['public']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo "wrote decision-record.json and decision-keys.json (key {$pair['key_id']})\n";
