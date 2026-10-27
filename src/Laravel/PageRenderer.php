<?php

declare(strict_types=1);

namespace ChangeCouncil\Laravel;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a lab page (Council/Index, Council/Show) with its props. The default,
 * {@see InertiaPageRenderer}, uses inertia-laravel when the host has it; a host
 * with another stack binds its own.
 */
interface PageRenderer
{
    public function render(Request $request, string $component, array $props): Response;
}
