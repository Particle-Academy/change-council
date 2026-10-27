# Laravel quick start

change-council runs inside a lab (a Laravel 13 app with Inertia and
react-fancy). You supply who may do what; everything else has a working default.

## 1. Install

```bash
composer require particle-academy/change-council
php artisan vendor:publish --tag=council-config
php artisan vendor:publish --tag=council-pages     # TSX into resources/js/Pages/Council
php artisan migrate
php artisan council:keygen                          # put COUNCIL_SIGNING_KEY in .env
php artisan council:seed-consumers                  # the known consumers, escalate by default
```

```dotenv
COUNCIL_ORIGIN=fancy
COUNCIL_LAB_URL=https://flabs.gen/council
COUNCIL_SIGNING_KEY=...
COUNCIL_WHITELIST_AGENTS=fancy@fancy-agi
COUNCIL_OWNERS=owner@example.com
```

Prism's lab globs `./pages/**`; set `council.pages.publish_path` to
`js/pages/council` before publishing there. Paths are case-sensitive on Linux.

## 2. Authorization and routes

Every route asks your hook. Until you register one, every route answers 403.

```php
// app/Providers/AppServiceProvider.php
use ChangeCouncil\Facades\Council;

public function boot(): void
{
    // $ability: view | owner | feed
    // $subject: ['ref' => CC-... | null, 'address' => consumer | null]
    Council::authorizeUsing(fn ($user, string $ability, array $subject): bool => match ($ability) {
        'owner' => in_array($user->email, config('council.owners'), true),
        'view' => true,
        'feed' => $user->can('read-council-feed'),
    });

    Council::routes(); // prefix and middleware from config/council.php (web, auth)
}
```

Only a literal `true` allows.

## 3. Mount the pages

The pages compile with the host's Vite against the host's own react-fancy (one
React, one copy). If your `resolve` already globs `./Pages/**/*.tsx`, the
published `Council/Index` and `Council/Show` resolve with no change. The helpers
live in `Council/parts/*.ts` (plain `.ts`), so a `*.tsx` glob never mistakes them
for pages.

The pages poll the JSON API every 15s at most, and not at all while the tab is
hidden: a lab is served by one php-cgi worker.

## 4. Schedule

```php
// routes/console.php
Schedule::command('council:tally')->everyFifteenMinutes();
Schedule::command('council:push-feed')->everyFiveMinutes();   // only with a webhook URL
```

## 5. The clerk

The ledger never calls Genie. The lab's own agent works the queue; see
[petitions.md](petitions.md).

## Commands

| Command | Who | What |
|---|---|---|
| `council:propose --file=` | any agent | Propose a Change (HITL unless whitelisted) |
| `council:detect [--propose]` | any agent | Run the shared detector; optionally propose from it |
| `council:petitions [--json]` | clerk | List queued petitions with their exact text |
| `council:petitions --mark-sent=<id> --result=` | clerk | Record a raw send result |
| `council:ingest --genie-json` / `--from= --message-id=` | clerk | Record a reply, as data |
| `council:tally` | scheduler | Close due rounds, extend once, escalate per policy |
| `council:revise <ref> --by=` | initiator | Revised approach: opens round 2 |
| `council:withdraw <ref> --by=` | initiator | Withdraw a live Change |
| `council:status [ref] [--json]` | anyone | State of one or every Change |
| `council:decide <ref> <decision> --owner= --reason=` | **owner only** | approve, reject, revoke, approve-initiation, reject-initiation |
| `council:export <ref> --to=` | releaser | Write the signed decision record into a repo |
| `council:verify-log` | anyone | Recompute the audit chain; exit 1 on a break |
| `council:keygen` | owner | New Ed25519 pair |
| `council:seed-consumers` | owner | Seed known consumers (idempotent) |
| `council:push-feed` | scheduler | Push the next feed page to Overwatch |

## JSON API (`/council/api/v1`)

| Method | Path | Ability |
|---|---|---|
| GET | `changes?state=&package=&class=` | view |
| GET | `changes/{ref}` (rounds, petitions, votes, audit, decisions, owner actions) | view |
| GET | `changes/{ref}/rounds` | view |
| GET | `consumers` | view |
| GET | `feed?since=` | feed |
| POST | `changes/{ref}/owner/approve-initiation` `{note?}` | owner |
| POST | `changes/{ref}/owner/reject-initiation` `{reason}` | owner |
| POST | `changes/{ref}/owner/override` `{decision: approve\|reject, reason, acknowledge: [vote ids]}` | owner |
| POST | `changes/{ref}/owner/revoke` `{reason}` | owner |
| PUT | `changes/{ref}/silence-policy` `{address, policy: escalate\|proceed_and_notify\|null}` | owner |
| PUT | `consumers/{address}/silence-policy` `{policy}` | owner |

Errors are `{"error": {"code": "..."}}`: 403 `forbidden`, 404 `not_found`,
409 `invalid_state`, 413 `payload_too_large`, 422 for everything else
(`invalid_request`, `reason_required`, `acknowledge_conflicts` with `missing`,
`invalid_policy`, ...). Bodies are closed: an unknown key is 422.
