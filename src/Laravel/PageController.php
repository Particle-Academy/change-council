<?php

declare(strict_types=1);

namespace ChangeCouncil\Laravel;

use ChangeCouncil\Http\Api;
use ChangeCouncil\Http\ApiResponse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The lab pages. Props come from the same {@see Api} the pages poll, so the
 * first paint and every refresh agree, and the same authorization hook guards
 * both.
 */
final class PageController
{
    public function __construct(private readonly Api $api, private readonly PageRenderer $renderer, private readonly Repository $config) {}

    public function index(Request $request): Response
    {
        $filters = array_intersect_key($request->query(), array_flip(['state', 'package', 'class']));
        $changes = $this->api->changes($request->user(), $filters);
        if ($changes->status !== 200) {
            return self::json($changes);
        }
        $consumers = $this->api->consumers($request->user());

        return $this->renderer->render($request, 'Council/Index', [
            'changes' => $changes->body['changes'],
            'consumers' => $consumers->status === 200 ? $consumers->body['consumers'] : [],
            'filters' => (object) $filters,
            'pollMs' => $this->pollMs(),
            'endpoints' => [
                'changes' => $this->url('api/v1/changes'),
                'show' => $this->url('changes'),
            ],
        ]);
    }

    public function show(Request $request, string $ref): Response
    {
        $detail = $this->api->change($request->user(), $ref);
        if ($detail->status !== 200) {
            return self::json($detail);
        }

        return $this->renderer->render($request, 'Council/Show', [
            'detail' => $detail->body,
            'pollMs' => $this->pollMs(),
            'endpoints' => [
                'index' => $this->url(''),
                'detail' => $this->url('api/v1/changes/'.$ref),
                'owner' => $this->url('api/v1/changes/'.$ref.'/owner'),
                'changeSilencePolicy' => $this->url('api/v1/changes/'.$ref.'/silence-policy'),
                'consumers' => $this->url('api/v1/consumers'),
            ],
        ]);
    }

    private function pollMs(): int
    {
        // Never faster than 15s: a lab is served by ONE php-cgi worker.
        return max(15000, (int) $this->config->get('council.pages.poll_ms', 15000));
    }

    private function url(string $path): string
    {
        $prefix = trim((string) $this->config->get('council.routes.prefix', 'council'), '/');

        return '/'.$prefix.($path === '' ? '' : '/'.$path);
    }

    private static function json(ApiResponse $response): JsonResponse
    {
        return new JsonResponse($response->body, $response->status, [], ApiResponse::FLAGS & ~JSON_THROW_ON_ERROR);
    }
}
