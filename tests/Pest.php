<?php

declare(strict_types=1);

use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Petitions\Ingest;
use ChangeCouncil\Petitions\IngestResult;
use ChangeCouncil\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

// Every test boots its own kernel; drop it afterwards so no container, facade,
// in-memory database or frozen clock leaks into the next test.
uses()->beforeEach(function (): void {
    Carbon::setTestNow('2026-10-27 12:00:00');
})->afterEach(function (): void {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
    Container::setInstance(null);
})->in(__DIR__);

function councilApp(array $config = []): Application
{
    return TestApp::boot($config);
}

function ledger(): Ledger
{
    return app(Ledger::class);
}

function ingest(): Ingest
{
    return app(Ingest::class);
}

/** A consumer declaring packages, with a fresh census by default. */
function consumer(string $address, array $packages, string $policy = 'escalate', array $extra = []): Consumer
{
    return Consumer::create([
        'address' => $address,
        'packages' => array_map(fn (string $name) => ['ecosystem' => str_contains($name, '/') && ! str_starts_with($name, '@') ? 'composer' : 'npm', 'name' => $name, 'constraint' => '*'], $packages),
        'watches' => $extra['watches'] ?? [],
        'silence_policy' => $policy,
        'whitelisted' => $extra['whitelisted'] ?? false,
        'internal' => $extra['internal'] ?? false,
        'source' => $extra['source'] ?? 'declared',
        'census_at' => array_key_exists('census_at', $extra) ? $extra['census_at'] : Carbon::now()->subDays(2),
    ]);
}

/** A whitelisted agent's own finding on particle-academy/prism, unless overridden. */
function proposal(array $overrides = []): array
{
    return [
        'title' => 'prism 0.140: approvals fail closed',
        'summary' => 'Approval callbacks that throw now deny instead of allow.',
        'approach' => "Ship in 0.140.0 with a CHANGELOG BREAKING entry.\nConsumers wrap their callbacks.",
        'classes' => ['declared-breaking'],
        'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism', 'from' => '0.139.0', 'to' => '0.140.0']],
        'evidence' => ['findings' => [['class' => 'declared-breaking', 'fingerprint' => str_repeat('a', 64)]]],
        'links' => ['commit' => 'abc1234'],
        'source' => 'agent_finding',
        'initiator' => 'fancy@fancy-agi',
        ...$overrides,
    ];
}

function propose(array $overrides = []): Change
{
    return ledger()->propose(proposal($overrides));
}

/** The petition a consumer received in the change's current round. */
function petitionFor(Change $change, string $address): Petition
{
    return Petition::query()->where('change_id', $change->id)->where('agent_address', $address)
        ->where('round_id', $change->fresh()->currentRound()->id)->firstOrFail();
}

/** A well-formed COUNCIL-REPLY v1 for a petition, with field overrides. */
function replyText(Petition $petition, array $overrides = [], string $prefix = ''): string
{
    $round = $petition->round;
    $body = [
        'change' => $petition->change->ref,
        'round' => $round->number,
        'nonce' => $petition->nonce,
        'digest' => $round->approach_digest,
        'verdict' => 'approve',
        'impact' => [],
        ...$overrides,
    ];
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($body[$key]);
        }
    }

    return $prefix."COUNCIL-REPLY v1\n".json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function vote(Change $change, string $address, string $verdict = 'approve', array $overrides = [], ?string $messageId = null): IngestResult
{
    $petition = petitionFor($change, $address);
    if ($verdict === 'conflict' && ! isset($overrides['impact'])) {
        $overrides['impact'] = [['app' => 'moic', 'paths' => ['app/Flows/Approve.php'], 'severity' => 'blocker', 'note' => 'We rely on allow-on-throw.']];
    }
    if ($verdict === 'abstain' && ! isset($overrides['reason'])) {
        $overrides['reason'] = 'Not using approvals.';
    }

    return ingest()->receive($address, replyText($petition, ['verdict' => $verdict, ...$overrides]), $messageId ?? 'msg-'.bin2hex(random_bytes(4)));
}

/** @return array{0: int, 1: string} exit code and output of a council command */
function runCommandWithCode(string $name, array $arguments = []): array
{
    foreach (\ChangeCouncil\Laravel\CouncilServiceProvider::COMMANDS as $class) {
        $command = app($class);
        if ($command->getName() !== $name) {
            continue;
        }
        $command->setLaravel(app());
        $output = new \Symfony\Component\Console\Output\BufferedOutput;
        $code = $command->run(new \Symfony\Component\Console\Input\ArrayInput($arguments), $output);

        return [$code, $output->fetch()];
    }
    throw new RuntimeException("No command {$name}");
}

function runCommand(string $name, array $arguments = []): string
{
    [$code, $output] = runCommandWithCode($name, $arguments);
    if ($code !== 0) {
        throw new RuntimeException("{$name} exited {$code}: {$output}");
    }

    return $output;
}

/** Move the clock past the current round's deadline (and extension, if any). */
function pastDeadline(Change $change, int $extraHours = 1): void
{
    $round = $change->fresh()->currentRound();
    $deadline = $round->extended_until ?? $round->closes_at;
    Carbon::setTestNow(Carbon::parse($deadline)->addHours($extraHours));
}
