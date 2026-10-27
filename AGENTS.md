# change-council code map

Two halves in one repo:

- `council/`: the detector. Plain Node ESM, **zero dependencies** (built-ins
  only), plus one PHP script run as a child process. CI fetches it by pinned
  SHA and content hash, so never add an import from outside `council/`.
- `src/` (`ChangeCouncil\`): the Laravel 13 ledger. Runtime imports are
  illuminate components only (symfony/process and the HTTP client arrive
  through them). inertia-laravel is a `suggest`. Nothing in `src/` uses
  Foundation helpers (`config()`, `app()`); read config through the container.

## The detector

- `check.mjs` is the CLI (report-only: always exits 0 unless the usage is
  wrong or `--strict` is passed). `lib/detect.mjs` runs every extractor in
  `lib/extractors/`, applies waivers, sorts and counts. An extractor returns
  findings built by `lib/finding.mjs`; it reports what it did through
  `ctx.coverage()` and never swallows an error (`ctx.error()` sets `ok:false`).
- Everything is read from git objects (`lib/git.mjs`), never the working tree,
  except `--from-dts/--to-dts` directories. Fingerprints are
  `sha256(class|subject|before|after)`; changing a subject format changes every
  fingerprint and un-covers approved Changes, so treat subjects as wire format.
- `php/surface.php` tokenizes (never executes) PHP files given on stdin. It
  compares only CHANGED files under the composer autoload paths.
- `lib/record.mjs` must stay byte-compatible with `src/Support/CanonicalJson.php`.
  The shared fixture `tests/fixtures/decision-record.json` is verified by both
  suites; regenerate it with `php tests/fixtures/make-decision-fixture.php`.

## The ledger

- `Ledger\Ledger` is the ONLY writer of `council_changes.state`. Every
  transition is one transaction plus audit events through `Audit\AuditLog`.
  States and the transition diagram are in `Ledger\States`.
- Whitelisting is computed in `propose()` from `Ledger\Whitelist`, never read
  from input. For `github_issue` the initiator is the issue author's login,
  checked against the GitHub author list; a whitelisted author opens round 1
  like a whitelisted agent. Anything non-whitelisted goes to `awaiting_owner`.
- Owner methods (`approveInitiation`, `rejectInitiation`, `override`,
  `revoke`, `setSilencePolicy`, `setChangeSilencePolicy`) are called ONLY from
  `Http\Api` behind the `owner` ability. Never from `src/Console`: an agent can
  run any command with any flag. `CommandsTest` scans for it.
- `Petitions\Ingest` is the only writer of votes. Order: replay check, flags,
  strict parse (`ReplyParser`), nonce lookup, sender match, change/round/digest
  match, open-or-catch-up, then store. The tally reads only `counted` votes and
  only `verdict` and impact `severity`.
- `Petitions\DeliveryResult` reads a send result at three refusal depths;
  only an explicit `ok: true` is `sent`.
- The ledger never calls Genie: `Transport\ClerkQueueTransport` leaves
  petitions queued. Tests bind `tests/Support/FakeTransport`.
- `Http\Api` is framework-free; `Laravel\CouncilController` adds only the body
  limit, JSON decoding and the user. Every route asks `Http\Authorizer`
  (default `DenyAll`); only a literal `true` from a callback allows.
- `Models\AuditEvent` throws on update/delete; the migration adds SQLite
  triggers. `AuditLog::verify()` reads raw rows, not models.
- `Feed\Feed` never emits reply free text (it masks `note`/`reason`).

## The pages

`resources/js/Pages/Council/{Index,Show}.tsx` plus `parts/*.ts` (no JSX, so a
host's `**/*.tsx` page glob skips them). Imports are limited to Fancy packages,
React and `@inertiajs/react`; reply text renders as `Text` or a read-only
`CodeView`, never HTML. `tests/Unit/PagesTest.php` enforces these, the slow
visibility-gated poll, and a `data-action`/`data-field` handle on every control
(put it FIRST in the tag: the check stops at the first `>`).

## Tests

- `node --test "council/test/*.test.mjs"`: fixture git repos built per test,
  a must-fire pair per class and a must-not-fire pair per waiver.
- `vendor/bin/pest`: a hand-built kernel (`tests/Support/TestApp.php`; no
  testbench), in-memory SQLite, the array cache, and `FakeTransport`. The clock
  is frozen at 2026-10-27 12:00 per test. HTTP tests dispatch through the real
  router; bind `request` before setting the user resolver.
