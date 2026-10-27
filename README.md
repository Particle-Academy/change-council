# change-council

**A consumer-agent voting ledger for major package changes.** Before a package
ships a change its consumers can feel (a third-party major, a raised platform
floor, a removed export, a schema change, a deprecation), the agents that run
the apps depending on it are asked, and every answer is kept.

Two halves, one repo:

- **The detector**, `council/check.mjs`: zero-dependency Node that compares two
  git refs and reports what a consumer could feel. The same file runs in a local
  pre-push hook, in GitHub Actions on public repos, and behind
  `php artisan council:detect`.
- **The ledger**, `particle-academy/change-council`: a Laravel package for a lab.
  Proposals, two voting rounds, per-consumer silence policy, owner decisions,
  an append-only hash-chained audit log, Ed25519-signed decision records, a JSON
  API, an Overwatch feed, and lab pages built from react-fancy.

> **Pre-1.0, and report-only.** Phases 1 and 2 of the design. Nothing gates a
> release yet: the detector exits 0 whatever it finds, and decision records are
> written and verified but not required. The gate is Phase 3. Breaking changes
> land in minor releases; read the [CHANGELOG](CHANGELOG.md).

## The rules it encodes

- **Silence is not consent.** A required voter's silence escalates to the owner,
  unless the owner has set that consumer to `proceed_and_notify` (it proceeds,
  and the consumer's late vote is still recorded).
- **Agents petition; the owner decides.** A whitelisted agent's finding or
  request, or an issue from a whitelisted author, opens round 1. Anything
  non-whitelisted waits for the owner with the initiating agent's pros and
  cons. No petition leaves first. Owner decisions are made in the
  authenticated lab UI only; no artisan command can record one.
- **Replies are data.** Strict JSON, bound to the round by nonce and digest,
  identified by the Genie-stamped sender, flagged for injection, rendered as
  text, never applied.
- **The ledger never calls Genie.** The lab's own agent (the clerk) sends the
  petitions it queues and pipes the replies back.
- **The detector is a floor, not a ceiling.** A behaviour change behind an
  unchanged signature is invisible to it. Declare those.

## Quick look

```bash
node council/check.mjs                     # previous v* tag -> HEAD, human summary
node council/check.mjs --json --out r.json # structured report
```

```
change-council detector 0.1.0 (REPORT-ONLY: Phase 1 never fails a release)
compare: v1.0.0 -> HEAD (3f2a9c1e0b7d)   kind: library

MAJOR (2)
  [platform-floor] composer:php  ^8.3 -> ^8.4  (composer.json)
  [third-party-major] composer:acme/http  ^2.1 -> ^3.0  (composer.json)
...
```

```php
use ChangeCouncil\Facades\Council;

Council::authorizeUsing(fn ($user, string $ability, array $subject): bool => /* view | owner | feed */);
Council::routes();
```

## Docs

- [Laravel quick start](docs/laravel-quickstart.md): install, authorization, pages, commands, API
- [The detector](docs/detector.md): classes, waivers, surface snapshots, what it cannot see
- [CI usage](docs/ci.md): GitHub Actions and pre-push, pinned SHA plus content hash, never in an id-token job
- [Petitions and replies](docs/petitions.md): initiation, the clerk loop, wire formats, tally and silence
- [Decision records](docs/decision-records.md): `decision.v1`, signing, verification, the audit chain
- [The Overwatch feed](docs/feed.md): `feed.v1`, the signed webhook, open questions for civi
- [Lab UI](docs/lab-ui.md): the pages, and how they were checked in a real browser

## Requirements

- Detector: Node 22+ and git. PHP on PATH for the PHP API comparison (it says
  so in the report when it is missing).
- Ledger: PHP 8.4 with sodium, Laravel 13. inertia-laravel and react-fancy for
  the pages (both labs have them).

## Testing

```bash
node --test "council/test/*.test.mjs"   # detector: a must-fire and a must-not-fire repo per class
vendor/bin/pest                          # ledger: hermetic, no network, a fake petition transport
php tests/lint.php
```

## License

MIT
