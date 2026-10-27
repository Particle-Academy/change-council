<?php

declare(strict_types=1);

namespace ChangeCouncil\Laravel;

use ChangeCouncil\Http\Api;
use ChangeCouncil\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inertia when installed (both labs have it). inertia-laravel is a `suggest`,
 * not a requirement: without it the page route answers 501 with a code that
 * says why, instead of a blank page.
 */
final class InertiaPageRenderer implements PageRenderer
{
    public function render(Request $request, string $component, array $props): Response
    {
        if (! class_exists(\Inertia\Inertia::class)) {
            $error = Api::error(501, 'inertia_missing', ['message' => 'Install inertiajs/inertia-laravel or bind ChangeCouncil\Laravel\PageRenderer.']);

            return new JsonResponse($error->body, $error->status, [], ApiResponse::FLAGS);
        }

        return \Inertia\Inertia::render($component, $props)->toResponse($request);
    }
}
