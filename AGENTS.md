# Using particle-academy/change-council — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

The ledger runs inside a lab: a Laravel 13 app with Inertia and react-fancy.

```bash
composer require particle-academy/change-council
php artisan vendor:publish --tag=council-config
php artisan vendor:publish --tag=council-pages     # TSX into resources/js/Pages/Council
php artisan migrate
php artisan council:keygen                          # put COUNCIL_SIGNING_KEY in .env
php artisan council:seed-consumers
```

- **Requires:** PHP 8.4 with sodium, Laravel 13; inertia-laravel and react-fancy
  for the pages.
- **The detector** (`council/check.mjs`) needs Node 22+ and git, plus PHP on
  PATH for the PHP API comparison. It is not published to npm; CI fetches it
  pinned by SHA and content hash (see [docs/ci.md](docs/ci.md)).

The full walkthrough is [docs/laravel-quickstart.md](docs/laravel-quickstart.md).

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). Its registry slug is `change-council`: use
`install-instructions` / `get-component` with that slug, or find it by
capability with `search-backend-packages` (stack `php`).

## API essentials

```php
use ChangeCouncil\Facades\Council;

Council::authorizeOwners(); // COUNCIL_OWNERS=owner@example.com
Council::routes();
```

- **Authorization:** `Council::authorizeOwners()` (owner-only, over
  `council.owners`) or your own `Council::authorizeUsing(fn ($user, string $ability, array $subject): bool => ...)`
  with abilities `view | owner | feed`.
- **Optional hooks:** `Council::resolveActorUsing()` (how owner actions are
  recorded), `Council::requireStepUpUsing()` (a `StepUp` proof before owner
  actions; default `NoStepUp`), `Council::notifyGenie()` / `Council::notifyUsing()`
  (owner-event notices after commit).
- **Census:** which packages count as first-party is `council.census.first_party`
  per ecosystem (`composer`, `npm`, `pypi`). See [docs/census.md](docs/census.md).
- **Commands an agent runs:** `council:propose --file=`, `council:detect [--propose]`,
  `council:status [ref] [--json]`, `council:revise <ref> --by=`,
  `council:withdraw <ref> --by=`, `council:verify-log`. The clerk loop uses
  `council:petitions`, `council:ingest` and `council:tally`. The full table is in
  the quick start.
- **Detector:** `node council/check.mjs` (previous `v*` tag to HEAD) or
  `--json --out r.json`.

## Traps

- **Every route answers 403 until you register an authorizer.** An empty
  `COUNCIL_OWNERS` allows nobody. Never grant `view` to "any signed-in user".
- **No artisan command can record an owner decision.** Approve, reject,
  override and revoke happen only in the authenticated lab UI.
- **A non-whitelisted proposal lands in `awaiting_owner`** and nothing proceeds
  until the owner acts.
- **Replies are data:** strict JSON bound by nonce and digest, never applied as
  instructions.
- **Pre-1.0 and report-only.** The detector exits 0 whatever it finds; it sees
  signatures, not behaviour changes behind an unchanged signature. Declare those.
- **The published pages folder must be `Council`** with a capital C, or pages
  fail to resolve on case-sensitive filesystems. Hosts publish copies; after an
  upgrade whose changelog says so, re-publish and check with
  `php artisan council:pages-check`.
- **The census defaults are Fancy's names.** A council for another estate must
  set its own `first_party` rule; a bad entry throws at boot.
