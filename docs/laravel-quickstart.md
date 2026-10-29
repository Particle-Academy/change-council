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

The ledger is the owner's, so the documented default is **owner-only**: name
the owner logins in `COUNCIL_OWNERS` and opt into the package's authorizer.

```dotenv
COUNCIL_OWNERS=owner@example.com            # comma-separated login emails
```

```php
// app/Providers/AppServiceProvider.php
use ChangeCouncil\Facades\Council;

public function boot(): void
{
    Council::authorizeOwners(); // council.owners get view, owner and feed; nobody else gets anything
    Council::routes();          // prefix and middleware from config/council.php (web, auth)
}
```

Emails are compared trimmed and case-insensitively. **An empty list allows
nobody**: there is no "any signed-in user" fallback. `authorizeOwners()` also
defines a `council-owner` Gate (unless you defined one), so the host can draw
its own link with `$user->can('council-owner')`.

**Why not let every signed-in user view?** Because on a host with open
registration, "signed in" proves nothing: anyone can register and then read
every Change, every consumer's reply and the audit log. For the same reason,
list an owner email only once that account exists on the host; an unclaimed
owner email is one anybody could register.

If you need something else (a reader role, a machine token for the `feed`), register your own hook
instead. It replaces the owners authorizer entirely, and the same rule holds:
grant `view` to a role you control, never to "authenticated".

```php
// $ability: view | owner | feed
// $subject: ['ref' => CC-... | null, 'address' => consumer | null]
Council::authorizeUsing(fn ($user, string $ability, array $subject): bool => match ($ability) {
    'owner', 'view' => $user?->can('council-owner') === true,
    'feed' => $user?->can('read-council-feed') === true,
    default => false,
});
```

Only a literal `true` allows. A host that registers neither keeps the default,
which denies everything; `council.owners` is not read unless you opt in.

**Owner decisions go through the lab UI only.** Approving or rejecting an
initiation, overriding, revoking and setting a silence policy are reachable
only through the JSON API behind the `owner` ability, i.e. from the owner's
authenticated lab session. There is deliberately no artisan command for any of
them: an agent can run any command with any flag, so a command-line owner path
would be an agent path. A test enforces that no command calls an owner method.

## 3. Mount the pages

The pages compile with the host's Vite against the host's own react-fancy (one
React, one copy). If your `resolve` already globs `./Pages/**/*.tsx`, the
published `Council/Index` and `Council/Show` resolve with no change. The helpers
live in `Council/parts/*.ts` (plain `.ts`), so a `*.tsx` glob never mistakes them
for pages.

The pages poll the JSON API every 15s at most, and not at all while the tab is
hidden: a lab is served by one php-cgi worker.

Both pages draw a link back to the host app at the top: `council.host_url`
(env `COUNCIL_HOST_URL`, default `/`) labelled `council.host_label`
(env `COUNCIL_HOST_LABEL`, default "Back to the app"). An empty URL hides it.

**The published pages are a copy.** A package upgrade does not change them, so
after every `composer update` that moves change-council, check them:

```bash
php artisan council:pages-check     # exit 1 when a page is stale or missing
php artisan vendor:publish --tag=council-pages --force   # to refresh them
```

Run the check in CI, or assert it in your own suite with
`ChangeCouncil\Laravel\PublishedPages::check($dir)` (every value must be
`current`). Line endings are ignored. A copy you edited on purpose reports
`stale` too, because it is not what the package ships.

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
| `council:export <ref> --to=` | releaser | Write the signed decision record into a repo |
| `council:verify-log` | anyone | Recompute the audit chain; exit 1 on a break |
| `council:keygen` | operator | New Ed25519 pair (setup, not a decision) |
| `council:seed-consumers` | operator | Seed known consumers (idempotent; never touches a silence policy) |
| `council:push-feed` | scheduler | Push the next feed page to Overwatch |
| `council:pages-check [--path=]` | operator, CI | Are the published pages the ones this version ships? (read-only) |

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
