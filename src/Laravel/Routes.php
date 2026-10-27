<?php

declare(strict_types=1);

namespace ChangeCouncil\Laravel;

use Illuminate\Container\Container;
use Illuminate\Routing\Router;

/**
 * The JSON API ({prefix}/api/v1/...) and the lab pages ({prefix}, {prefix}/changes/{ref}).
 * Registered only when the host asks: `Council::routes()` or config
 * `council.routes.enabled`.
 */
final class Routes
{
    /** @param array{prefix?: string, middleware?: list<string>} $options */
    public static function register(Router $router, array $options = []): void
    {
        $config = Container::getInstance()->make('config');
        $prefix = $options['prefix'] ?? $config->get('council.routes.prefix', 'council');
        $router->group([
            'prefix' => $prefix,
            'middleware' => $options['middleware'] ?? $config->get('council.routes.middleware', ['web', 'auth']),
        ], function (Router $router): void {
            $ref = '[A-Za-z0-9-]+';
            $router->get('/', [PageController::class, 'index'])->name('council.index');
            $router->get('changes/{ref}', [PageController::class, 'show'])->where('ref', $ref)->name('council.show');

            $router->get('api/v1/changes', [CouncilController::class, 'changes'])->name('council.api.changes');
            $router->get('api/v1/changes/{ref}', [CouncilController::class, 'change'])->where('ref', $ref)->name('council.api.change');
            $router->get('api/v1/changes/{ref}/rounds', [CouncilController::class, 'rounds'])->where('ref', $ref)->name('council.api.rounds');
            $router->get('api/v1/consumers', [CouncilController::class, 'consumers'])->name('council.api.consumers');
            $router->get('api/v1/feed', [CouncilController::class, 'feed'])->name('council.api.feed');

            $router->post('api/v1/changes/{ref}/owner/approve-initiation', [CouncilController::class, 'approveInitiation'])->where('ref', $ref)->name('council.api.approve-initiation');
            $router->post('api/v1/changes/{ref}/owner/reject-initiation', [CouncilController::class, 'rejectInitiation'])->where('ref', $ref)->name('council.api.reject-initiation');
            $router->post('api/v1/changes/{ref}/owner/override', [CouncilController::class, 'override'])->where('ref', $ref)->name('council.api.override');
            $router->post('api/v1/changes/{ref}/owner/revoke', [CouncilController::class, 'revoke'])->where('ref', $ref)->name('council.api.revoke');
            $router->put('api/v1/changes/{ref}/silence-policy', [CouncilController::class, 'changeSilencePolicy'])->where('ref', $ref)->name('council.api.change-silence-policy');
            $router->put('api/v1/consumers/{address}/silence-policy', [CouncilController::class, 'consumerSilencePolicy'])->where('address', '[^/]+')->name('council.api.consumer-silence-policy');
        });
    }
}
