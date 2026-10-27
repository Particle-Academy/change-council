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

/*
 * Owner decisions go through the authenticated lab UI only. An agent can run
 * any artisan command and pass any flag, so no command may reach an owner
 * method of the ledger at all.
 */
const OWNER_METHODS = ['approveInitiation', 'rejectInitiation', 'override', 'revoke', 'setSilencePolicy', 'setChangeSilencePolicy'];

test('no artisan command calls an owner method of the ledger', function (): void {
    $files = glob(dirname(__DIR__, 2).'/src/Console/*.php') ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = file_get_contents($file);
        foreach (OWNER_METHODS as $method) {
            expect(preg_match('/->\s*'.$method.'\s*\(/', $source))->toBe(0, basename($file)." calls {$method}()");
        }
    }
});

test('there is no council:decide command, and no registered command is an owner path', function (): void {
    $names = array_map(fn (string $class) => app($class)->getName(), \ChangeCouncil\Laravel\CouncilServiceProvider::COMMANDS);

    expect($names)->not->toContain('council:decide')
        ->and(class_exists(\ChangeCouncil\Console\DecideCommand::class))->toBeFalse();
});

test('running every command with owner-shaped flags records no owner decision', function (): void {
    propose();
    propose(['title' => 'hitl', 'source' => 'agent_request', 'initiator' => 'x@y', 'pros' => 'p', 'cons' => 'c']);
    $owner = ['--owner' => 'owner@example.com', '--reason' => 'agent says so', '--decision' => 'approve'];

    foreach (\ChangeCouncil\Laravel\CouncilServiceProvider::COMMANDS as $class) {
        $command = app($class);
        $definition = $command->getDefinition();
        $args = [];
        foreach ($definition->getArguments() as $argument) {
            $args[$argument->getName()] = $argument->getName() === 'ref' ? 'CC-fancy-0002' : 'approve';
        }
        foreach ($owner as $flag => $value) {
            if ($definition->hasOption(ltrim($flag, '-'))) {
                $args[$flag] = $value;
            }
        }
        if ($command->getName() === 'council:push-feed' || $command->getName() === 'council:detect') {
            continue; // network-free by construction; detect needs a repo
        }
        try {
            runCommandWithCode($command->getName(), $args);
        } catch (Throwable) {
            // A command refusing loudly is fine; what matters is what it wrote.
        }
    }

    expect(\ChangeCouncil\Models\AuditEvent::query()->where('actor', 'like', 'owner:%')->count())->toBe(0)
        ->and(\ChangeCouncil\Models\Decision::query()->where('decided_by', 'like', 'owner:%')->count())->toBe(0)
        ->and(Change::query()->where('ref', 'CC-fancy-0002')->value('state'))->toBe(States::AWAITING_OWNER);
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
