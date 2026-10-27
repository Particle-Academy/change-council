<?php

declare(strict_types=1);

namespace ChangeCouncil\Laravel;

use ChangeCouncil\Audit\AuditLog;
use ChangeCouncil\Console;
use ChangeCouncil\Council;
use ChangeCouncil\Detector\Detector;
use ChangeCouncil\Feed\Feed;
use ChangeCouncil\Feed\HttpSender;
use ChangeCouncil\Feed\LaravelHttpSender;
use ChangeCouncil\Feed\WebhookEmitter;
use ChangeCouncil\Http\Api;
use ChangeCouncil\Http\Authorizer;
use ChangeCouncil\Http\DenyAll;
use ChangeCouncil\Http\Presenter;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\Whitelist;
use ChangeCouncil\Petitions\Ingest;
use ChangeCouncil\Records\DecisionRecords;
use ChangeCouncil\Records\RecordSigner;
use ChangeCouncil\Transport\ClerkQueueTransport;
use ChangeCouncil\Transport\PetitionTransport;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the ledger's defaults. The host may rebind PetitionTransport,
 * Authorizer (default: deny all), PageRenderer and HttpSender. Nothing
 * registers routes unless the host asks.
 */
final class CouncilServiceProvider extends ServiceProvider
{
    public const COMMANDS = [
        Console\ProposeCommand::class,
        Console\PetitionsCommand::class,
        Console\IngestCommand::class,
        Console\TallyCommand::class,
        Console\DecideCommand::class,
        Console\StatusCommand::class,
        Console\ExportCommand::class,
        Console\VerifyLogCommand::class,
        Console\KeygenCommand::class,
        Console\DetectCommand::class,
        Console\SeedConsumersCommand::class,
        Console\PushFeedCommand::class,
        Console\ReviseCommand::class,
        Console\WithdrawCommand::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/council.php', 'council');

        $this->app->singleton(AuditLog::class, fn (Application $app) => new AuditLog($app['db']->connection()));
        $this->app->singletonIf(PetitionTransport::class, fn () => new ClerkQueueTransport);
        $this->app->singletonIf(Authorizer::class, fn () => new DenyAll);
        $this->app->singletonIf(PageRenderer::class, fn () => new InertiaPageRenderer);
        $this->app->bind(Whitelist::class, fn (Application $app) => new Whitelist(
            array_values((array) $app['config']->get('council.whitelist.agents', [])),
            array_values((array) $app['config']->get('council.whitelist.github_authors', [])),
            $app['config']->get('council.whitelist.github_authors_file'),
        ));
        // Bound, not a singleton: a test (or a host) may rebind the transport.
        $this->app->bind(Ledger::class, fn (Application $app) => new Ledger(
            $app['db']->connection(),
            $app->make(AuditLog::class),
            $app->make(PetitionTransport::class),
            $app->make(Whitelist::class),
            [
                'origin' => (string) $app['config']->get('council.origin', 'fancy'),
                'lab_url' => (string) $app['config']->get('council.lab_url', ''),
                'round1_hours' => (int) $app['config']->get('council.deadlines.round1_hours', 72),
                'round2_hours' => (int) $app['config']->get('council.deadlines.round2_hours', 48),
                'extension_hours' => (int) $app['config']->get('council.deadlines.extension_hours', 24),
                'census_max_age_days' => (int) $app['config']->get('council.census_max_age_days', 30),
            ],
        ));
        $this->app->bind(Ingest::class, fn (Application $app) => new Ingest($app['db']->connection(), $app->make(AuditLog::class), $app->make(Ledger::class)));
        $this->app->singleton(RecordSigner::class, fn (Application $app) => new RecordSigner(
            $app['config']->get('council.signing.secret_key'),
            (array) $app['config']->get('council.signing.public_keys', []),
        ));
        $this->app->bind(DecisionRecords::class, fn (Application $app) => new DecisionRecords($app->make(RecordSigner::class), $app->make(AuditLog::class)));
        $this->app->bind(Feed::class, fn (Application $app) => new Feed((string) $app['config']->get('council.origin', 'fancy')));
        $this->app->bind(Presenter::class, fn (Application $app) => new Presenter($app->make(Ledger::class)));
        $this->app->bind(Api::class, fn (Application $app) => new Api($app->make(Ledger::class), $app->make(Presenter::class), $app->make(Feed::class), $app->make(Authorizer::class)));
        $this->app->bind(Detector::class, fn (Application $app) => new Detector(
            (string) $app['config']->get('council.detector.node', 'node'),
            $app['config']->get('council.detector.script'),
        ));
        $this->app->singletonIf(HttpSender::class, fn (Application $app) => new LaravelHttpSender($app->make(Factory::class)));
        if (is_string($this->app['config']->get('council.feed.webhook_url')) && $this->app['config']->get('council.feed.webhook_url') !== '') {
            $this->app->bind(WebhookEmitter::class, fn (Application $app) => new WebhookEmitter(
                $app->make(Feed::class), $app->make(RecordSigner::class), $app->make(HttpSender::class), $app['db']->connection(),
                (string) $app['config']->get('council.feed.webhook_url'),
                (int) $app['config']->get('council.feed.max_attempts', 8),
                (int) $app['config']->get('council.feed.batch', 200),
            ));
        }
        $this->app->singleton(Council::class, fn (Application $app) => new Council($app));
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadMigrationsFrom($root.'/database/migrations');
        if ($this->app['config']->get('council.routes.enabled') === true) {
            Routes::register($this->app->make('router'));
        }
        if ($this->app->runningInConsole()) {
            $this->commands(self::COMMANDS);
            $this->publishes([$root.'/config/council.php' => $this->app->configPath('council.php')], 'council-config');
            $this->publishes([$root.'/database/migrations' => $this->app->databasePath('migrations')], 'council-migrations');
            $this->publishes([$root.'/resources/js/Pages/Council' => $this->app->resourcePath((string) $this->app['config']->get('council.pages.publish_path', 'js/Pages/Council'))], 'council-pages');
        }
    }
}
