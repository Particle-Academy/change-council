<?php

declare(strict_types=1);

return [

    /*
    | This ledger's origin: the `<origin>` in `CC-<origin>-0007`. One per lab,
    | so one Overwatch can merge several ledgers (fancy, prism, ...).
    */
    'origin' => env('COUNCIL_ORIGIN', 'fancy'),

    // Where a petition tells the agent it can read the Change.
    'lab_url' => env('COUNCIL_LAB_URL', 'https://flabs.gen/council'),

    // Round deadlines, in hours. Silent required voters get ONE extension.
    'deadlines' => [
        'round1_hours' => 72,
        'round2_hours' => 48,
        'extension_hours' => 24,
    ],

    // A Change with no required voters is approved_unopposed only when every
    // external consumer's census is younger than this; otherwise it escalates.
    'census_max_age_days' => 30,

    /*
    | Who may initiate straight into round 1. Everything else lands in
    | `awaiting_owner` (HITL) with a pros/cons summary, and no petition goes out
    | until the owner approves. A GitHub issue counts as whitelisted only when
    | its AUTHOR is a listed login; every other issue goes to HITL.
    |
    | agents: literal Genie addresses (plus any consumer row marked whitelisted)
    | github_authors: literal logins; github_authors_file: a trusted-authors.txt
    |
    | Owner decisions are not configured here: they go through the lab UI only,
    | behind the host's authorization hook (the `owner` ability). No artisan
    | command can record one.
    */
    'whitelist' => [
        'agents' => array_filter(explode(',', (string) env('COUNCIL_WHITELIST_AGENTS', ''))),
        'github_authors' => ['wishborn'],
        'github_authors_file' => env('COUNCIL_TRUSTED_AUTHORS_FILE'),
    ],

    /*
    | Ed25519 signing for decision records and the Overwatch webhook, through
    | PHP's built-in sodium. `php artisan council:keygen` prints a pair.
    | secret_key: base64 of the 64-byte secret key. public_keys: key_id => base64
    | of a 32-byte public key (every key a record may be verified against).
    */
    'signing' => [
        'secret_key' => env('COUNCIL_SIGNING_KEY'),
        'public_keys' => [],
    ],

    // The detector the `council:detect` command runs: the SAME check.mjs CI
    // runs, never a PHP mirror of it.
    'detector' => [
        'node' => env('COUNCIL_NODE', 'node'),
        'script' => null, // null: the copy shipped in this package
    ],

    // Overwatch push. Off until a URL is set. The pull feed is always available
    // to a principal holding the `feed` ability.
    'feed' => [
        'webhook_url' => env('COUNCIL_FEED_WEBHOOK_URL'),
        'max_attempts' => 8,
        'batch' => 200,
    ],

    // The optional routes: JSON API under {prefix}/api/v1 and the lab pages at
    // {prefix}. Every route asks the host's authorization hook (default: deny).
    'routes' => [
        'enabled' => false,
        'prefix' => 'council',
        'middleware' => ['web', 'auth'],
        'max_body_bytes' => 65536,
    ],

    'pages' => [
        // Lab pages poll slowly: a lab is served by ONE php-cgi worker.
        'poll_ms' => 15000,
        // Where `vendor:publish --tag=council-pages` copies the TSX. Prism's
        // lab globs ./pages/**, fancy-labs globs ./Pages/**.
        'publish_path' => 'js/Pages/Council',
    ],
];
