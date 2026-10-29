<?php

declare(strict_types=1);

namespace ChangeCouncil\Tests\Support;

use ChangeCouncil\Laravel\PageRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A renderer that returns the page name and props as JSON, standing in for Inertia. */
final class JsonPageRenderer implements PageRenderer
{
    public function render(Request $request, string $component, array $props): Response
    {
        return new JsonResponse(['component' => $component, 'props' => $props]);
    }
}
