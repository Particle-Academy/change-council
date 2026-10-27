<?php

declare(strict_types=1);

namespace ChangeCouncil\Tests\Support;

use ChangeCouncil\Transport\PetitionTransport;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

/**
 * A hand-built Laravel kernel for the suite: the framework this package
 * integrates with, booted directly, with no orchestra/testbench (the same
 * approach as charlotte's TestApp).
 *
 * In-memory SQLite and the array cache, so every test is hermetic and nothing
 * leaves the process. Petitions go to a {@see FakeTransport}; nothing here can
 * reach Genie or any network.
 */
final class TestApp
{
    public static ?FakeTransport $transport = null;

    /** @param array<string, mixed> $config dotted keys merged over the defaults */
    public static function boot(array $config = []): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'change-council-tests';
        if (! is_dir($base.'/config')) {
            mkdir($base.'/config', 0777, true);
        }
        $app = new Application($base);
        $app->useConfigPath($base.'/config');
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $keys = sodium_crypto_sign_keypair();
        $repository = new Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'env' => 'testing', 'debug' => false],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]],
            'auth' => ['defaults' => ['guard' => 'web'], 'guards' => [], 'providers' => []],
            'council' => [
                'signing' => [
                    'secret_key' => base64_encode(sodium_crypto_sign_secretkey($keys)),
                    'public_keys' => [],
                ],
                'whitelist' => ['agents' => ['fancy@fancy-agi'], 'github_authors' => ['wishborn'], 'github_authors_file' => null],
                'owners' => ['owner@example.com'],
                'lab_url' => 'https://lab.example.test/council',
            ],
        ]);
        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }
        $app->instance('config', $repository);

        foreach ([EventServiceProvider::class, FilesystemServiceProvider::class, DatabaseServiceProvider::class, CacheServiceProvider::class,
            AuthServiceProvider::class, RoutingServiceProvider::class,
            \ChangeCouncil\Laravel\CouncilServiceProvider::class] as $serviceProvider) {
            $app->register($serviceProvider);
        }

        self::$transport = new FakeTransport;
        $app->instance(PetitionTransport::class, self::$transport);

        Model::unguard();
        self::migrate(dirname(__DIR__, 2).'/database/migrations');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });
        $app->boot();

        return $app;
    }

    private static function migrate(string $directory): void
    {
        $files = glob($directory.'/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
}
