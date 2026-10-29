<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Laravel\PublishedPages;
use Illuminate\Console\Command;

/**
 * Read-only: compares the host's published lab pages with the ones this
 * version of the package ships. Exits 1 when any file is stale or missing, so
 * a host can run it in CI after `composer update`.
 */
final class PagesCheckCommand extends Command
{
    protected $signature = 'council:pages-check {--path= : The published pages directory (default: resources/{council.pages.publish_path})}';

    protected $description = 'Check that the published lab pages match this version of change-council';

    public function handle(): int
    {
        $path = $this->option('path');
        if (! is_string($path) || $path === '') {
            $path = $this->laravel->resourcePath((string) $this->laravel['config']->get('council.pages.publish_path', 'js/Pages/Council'));
        }

        $report = PublishedPages::check($path);
        $current = count(array_filter($report, fn (string $state): bool => $state === PublishedPages::CURRENT));
        foreach ($report as $file => $state) {
            if ($state !== PublishedPages::CURRENT) {
                $this->line(str_pad($state, 8).$file);
            }
        }
        $this->line("{$current} of ".count($report)." current in {$path}");
        if ($current === count($report)) {
            return 0;
        }
        $this->error('The published pages are not the ones this version ships. Re-publish them: php artisan vendor:publish --tag=council-pages --force');

        return 1;
    }
}
