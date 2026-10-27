<?php

declare(strict_types=1);

use ChangeCouncil\Consumers\ConsumerSeed;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Consumer;

/*
 * The artisan surface an agent drives: propose, petitions, ingest, tally,
 * decide, status, export, detect, seed-consumers.
 */

beforeEach(function (): void {
    councilApp();
    consumer('moic@moic-suite', ['particle-academy/prism']);
});

function writeTemp(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'council');
    file_put_contents($path, $content);

    return $path;
}

test('council:propose takes a JSON file and reports the state and ref', function (): void {
    $file = writeTemp(json_encode(proposal()));

    $out = runCommand('council:propose', ['--file' => $file, '--json' => true]);

    expect(json_decode($out, true))->toMatchArray(['ref' => 'CC-fancy-0001', 'state' => States::ROUND1_OPEN]);
});

test('council:propose by a non-whitelisted agent reports awaiting_owner', function (): void {
    $file = writeTemp(json_encode(proposal(['source' => 'agent_request', 'initiator' => 'x@y', 'pros' => 'p', 'cons' => 'c'])));

    expect(runCommand('council:propose', ['--file' => $file]))->toContain('awaiting_owner')->toContain('owner');
});

test('council:ingest takes a pasted reply plus the Genie sender, and a raw Genie message', function (): void {
    $change = propose();
    $petition = petitionFor($change, 'moic@moic-suite');

    $pasted = runCommand('council:ingest', ['--from' => 'moic@moic-suite', '--message-id' => 'm-1', '--file' => writeTemp(replyText($petition))]);
    $genie = runCommand('council:ingest', ['--genie-json' => true, '--file' => writeTemp(json_encode(['id' => 'm-2', 'from' => 'moic@moic-suite', 'at' => '2026-10-27T12:00:00Z', 'text' => replyText($petition, ['verdict' => 'abstain', 'reason' => 'n/a'])]))]);

    expect($pasted)->toContain('counted')
        ->and($genie)->toContain('counted')
        ->and($petition->fresh()->outcome)->toBe('abstain');
});

test('council:ingest without a sender is refused: the sender is never taken from the text', function (): void {
    $change = propose();
    [$code, $out] = runCommandWithCode('council:ingest', ['--file' => writeTemp(replyText(petitionFor($change, 'moic@moic-suite')))]);

    expect($code)->toBe(2)
        ->and($out)->toContain('--from');
});

test('council:tally closes due rounds; council:status shows the state', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite');

    runCommand('council:tally');

    expect(json_decode(runCommand('council:status', ['ref' => 'CC-fancy-0001', '--json' => true]), true)['change']['state'])->toBe(States::APPROVED)
        ->and(runCommand('council:status'))->toContain('CC-fancy-0001')->toContain('approved');
});

test('council:decide is the owner path from a terminal: owners only, reason required', function (): void {
    propose();

    [$notOwner] = runCommandWithCode('council:decide', ['ref' => 'CC-fancy-0001', 'decision' => 'reject', '--owner' => 'agent@fancy', '--reason' => 'x']);
    [$noReason] = runCommandWithCode('council:decide', ['ref' => 'CC-fancy-0001', 'decision' => 'reject', '--owner' => 'owner@example.com']);
    runCommand('council:decide', ['ref' => 'CC-fancy-0001', 'decision' => 'reject', '--owner' => 'owner@example.com', '--reason' => 'Not this release.']);

    expect($notOwner)->toBe(2)
        ->and($noReason)->toBe(2)
        ->and(Change::query()->value('state'))->toBe(States::REJECTED);
});

test('council:export writes the signed record into the target repo', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite');
    ledger()->tally();
    $dir = sys_get_temp_dir().'/council-export-'.bin2hex(random_bytes(4));
    mkdir($dir);

    runCommand('council:export', ['ref' => 'CC-fancy-0001', '--to' => $dir]);

    expect(file_exists($dir.'/.council/decisions/CC-fancy-0001.json'))->toBeTrue();
});

test('council:detect runs the SAME node check.mjs and can open a Change from its findings', function (): void {
    $repo = sys_get_temp_dir().'/council-detect-'.bin2hex(random_bytes(4));
    mkdir($repo);
    $git = fn (string $args) => exec('git -C '.escapeshellarg($repo).' '.$args.' 2>&1');
    $git('init -q -b main');
    $git('config user.email f@example.com');
    $git('config user.name F');
    file_put_contents($repo.'/composer.json', json_encode(['name' => 'particle-academy/prism', 'require' => ['php' => '^8.3']]));
    $git('add -A');
    $git('commit -q -m one');
    $git('tag v1.0.0');
    file_put_contents($repo.'/composer.json', json_encode(['name' => 'particle-academy/prism', 'require' => ['php' => '^8.4']]));
    $git('commit -q -am two');

    $report = json_decode(runCommand('council:detect', ['--repo' => $repo, '--json' => true]), true);
    $opened = runCommand('council:detect', ['--repo' => $repo, '--propose' => true, '--initiator' => 'fancy@fancy-agi', '--package' => 'composer:particle-academy/prism', '--approach' => 'Raise the PHP floor in 0.141.']);

    $change = Change::query()->first();
    expect($report['summary']['major'])->toBe(1)
        ->and($report['findings'][0]['class'])->toBe('platform-floor')
        ->and($opened)->toContain('CC-fancy-0001')
        ->and($change->classes)->toBe(['platform-floor'])
        ->and($change->evidence['findings'][0]['fingerprint'])->toBe($report['findings'][0]['fingerprint'])
        ->and($change->evidence['detector']['version'])->toBe('0.1.0');
});

test('council:seed-consumers seeds the known consumers, escalate by default, MOIC with its four Prism packages', function (): void {
    Consumer::query()->delete();

    runCommand('council:seed-consumers');

    $moic = Consumer::query()->where('address', 'moic@moic-suite')->first();
    $names = array_column($moic->packages, 'name');
    expect($names)->toContain('particle-academy/prism', 'particle-academy/prism-harness', 'particle-academy/prism-opentelemetry', 'particle-academy/prism-perplexity')
        ->and(collect($moic->packages)->firstWhere('name', 'particle-academy/prism')['constraint'])->toBe('0.131.0')
        ->and(array_column($moic->watches, 'name'))->toContain('particle-academy/prism-memory')
        ->and(Consumer::query()->pluck('silence_policy')->unique()->values()->all())->toBe(['escalate'])
        ->and(Consumer::query()->pluck('address')->all())->toContain('tynn@tynn', 'miapa@maipa', 'weaver@weaver', 'guardcard@guardcard-net', 'tribeshare@tribetrip')
        ->and(Consumer::query()->where('internal', true)->pluck('address')->all())->toContain('px-ui-sandbox', 'fancy-starter-kit', 'fancy-labs');
});

test('re-seeding is idempotent and never overwrites an owner-set silence policy', function (): void {
    Consumer::query()->delete();
    runCommand('council:seed-consumers');
    ledger()->setSilencePolicy('tynn@tynn', 'proceed_and_notify', 'owner@example.com');
    $count = Consumer::query()->count();

    runCommand('council:seed-consumers');

    expect(Consumer::query()->count())->toBe($count)
        ->and(Consumer::query()->where('address', 'tynn@tynn')->value('silence_policy'))->toBe('proceed_and_notify')
        ->and(count(ConsumerSeed::rows()))->toBe($count);
});
