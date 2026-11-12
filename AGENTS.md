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
- Never spawn a bare program name. `resolveExecutable()` in `lib/git.mjs` walks
  PATH (absolute entries only) so the repo under inspection or the start
  directory cannot supply `git` or `php`. Its cases are
  `council/test/fixtures/executable-lookup-cases.json`, the same file as in
  fancy-git-js and fancy-git-php.
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
  `revoke`, `setSilencePolicy`, `setChangeSilencePolicy`, `setWeight`,
  `setProject`, `setBlockingThreshold`, `removeMember`) are called ONLY from
  `Http\Api` behind the `owner` ability. Never from `src/Console`: an agent can
  run any command with any flag. `CommandsTest` scans for it.
- `Petitions\Ingest` is the only writer of votes. Order: replay check, flags,
  strict parse (`ReplyParser`), nonce lookup, sender match, change/round/digest
  match, withdrawn check, open-or-catch-up, then store. The tally reads only
  `counted` votes and only `verdict` and impact `severity`.
- Removal (`Ledger::removeMember()`, 0.5.1): a consumer row is never deleted.
  `removed_at` takes it out of `voters()`, the census check, the whitelist,
  `Members::list()` (it appears in `former()`) and the seeder. Its petitions in
  OPEN rounds get `withdrawn_at` (and outcome `withdrawn`), audited as
  `petition.withdrawn`; every tally read (`tallyChange`, `countedVotes`, the
  summary counts, the clerk's queue) skips a withdrawn petition, and Ingest
  refuses a reply to one as `invalid`/`petition_withdrawn`.
- Census (0.6.0): `Census\CensusIngest` is the ONLY writer of a census
  (`source = census`, `census_at`, and the `packages` it replaces). Order:
  replay check, size cap, flags, strict parse (`Census\CensusParser`), nonce
  lookup, sender match, already-answered, member-removed, then apply. It never
  touches `watches`, owner-set fields or existing petitions, and its audit
  event carries only validated names, codes and counts. Non-first-party rows
  are dropped with a reason (`not_first_party`, `bad_name`, `bad_version`,
  `duplicate`), never applied. `Census\Census` queues requests (one
  outstanding per active external member) and records send results;
  `council:ingest` routes a `COUNCIL-CENSUS v1` message to `CensusIngest`, so
  `Petitions\Ingest` stays the only writer of votes.
- First-party rule (0.7.1): `Census\FirstParty`, built from
  `council.census.first_party` and validated in the provider's `boot()` (a bad
  entry throws, naming it). Patterns are a literal prefix plus one trailing `*`
  or an exact name, matched with `str_starts_with`/`===`: never compile config
  into a regex that runs on reply text. The name shapes in `CensusParser::NAMES`
  are fixed and end in `$` with `D`. Unset, the rule is `FirstParty::DEFAULTS`.
- Weights: `Ledger::weigh()` is the ONE place a member's weight affects an
  outcome. Blocking-severity conflicts block only when their members' summed
  weight reaches `blockingThreshold()` (owner setting in `council_settings`,
  else `council.tally.blocking_threshold`). `close()` records the weights and
  arithmetic in the round's tally; `openConflicts()` reads a 0.5+ tally as
  recorded, weighs an open round live, and treats an older closed round by the
  pre-0.5 rule. Never re-weigh a closed round. Do not put `weight` in
  `Consumer::$attributes`: a host migration that seeds consumers before the 0.5
  migration would then insert a column that does not exist yet.
- Sendable: `Petition::sendable()` is the ONE definition of a petition the
  clerk can still send; `council:petitions` and the summary's `queued` count
  both use it. Never count `delivery = 'queued'` directly (`SendableTest`
  scans `src/`). Closing a round, `override`, `withdraw` and `removeMember`
  record unsent, unanswered petitions as `superseded` (`petition.superseded`);
  `Ledger::supersedeStale()` (`council:petitions --supersede-stale`) does the
  same for older rows.
- `Members\Members` is the read model for the members pages and API (stats,
  voting record). Read-only.
- `Petitions\DeliveryResult` reads a send result at three refusal depths;
  only an explicit `ok: true` is `sent`.
- The ledger never calls Genie: `Transport\ClerkQueueTransport` leaves
  petitions queued. Tests bind `tests/Support/FakeTransport`.
- `Http\Api` is framework-free; `Laravel\CouncilController` adds only the body
  limit, JSON decoding and the user. Every route asks `Http\Authorizer`
  (default `DenyAll`); only a literal `true` from a callback allows.
  `Http\OwnersAuthorizer` (`Council::authorizeOwners()`) is the opt-in
  owner-only hook over `council.owners`; an empty list allows nobody. It is
  never bound by default.
- Owner actions record the id from `Http\ActorResolver` (0.7.0): the
  principal's email/key by default, or `Council::resolveActorUsing()`'s answer.
  A bad answer (non-string, blank, over 191 characters, invalid UTF-8, any C0/C1
  control or DEL) throws `Refused('invalid_actor')`, mapped to 500, BEFORE the
  ledger is called. Never fall back to the email.
- `Models\AuditEvent` throws on update/delete; the migration adds SQLite
  triggers. `AuditLog::verify()` reads raw rows, not models.
- `Feed\Feed` never emits reply free text (it masks `note`/`reason`).
- `Notify\OwnerEventNotifier` (default `NullNotifier`) is told about owner
  events. `Ledger::notifyAfterCommit()` queues it with `afterCommit()` at each
  transition point:
  - `propose()` when it lands in `awaiting_owner` (the only way into that
    state), as `awaiting_owner`;
  - `approveInitiation`, `rejectInitiation`, `override` and `revoke`;
  - `close()`, once per closed round, plus `escalated`.

  An `OwnerEvent` holds refs, states, codes and counts, never free text. The
  `awaiting_owner` initiator passes through `OwnerEvent::identity()`. A
  throwing notifier is swallowed and audited as `notify.failed`.
  `Notify\GenieInboxNotifier` reads `GENIE_INBOX_URL`/`_TOKEN` with `getenv()`
  at send time, never config. It audits event, ref and status only, never
  throws and never retries. Opt in with `Council::notifyUsing()`,
  `Council::notifyGenie()` or `council.notify.genie`.

## The pages

`resources/js/Pages/Council/{Index,Show,Members,Member}.tsx` plus `parts/*.ts` (no JSX, so a
host's `**/*.tsx` page glob skips them). Imports are limited to Fancy packages,
React and `@inertiajs/react`; reply text renders as `Text` or a read-only
`CodeView`, never HTML. `tests/Unit/PagesTest.php` enforces these, the slow
visibility-gated poll, and a `data-action`/`data-field` handle on every control
(put it FIRST in the tag: the check stops at the first `>`).
Hosts publish COPIES of these files; `Laravel\PublishedPages` and
`council:pages-check` compare a host's copy with the shipped one, so any
change here is a "re-publish the pages" line in the CHANGELOG.

## Tests

- `node --test "council/test/*.test.mjs"`: fixture git repos built per test,
  a must-fire pair per class and a must-not-fire pair per waiver.
- `vendor/bin/pest`: a hand-built kernel (`tests/Support/TestApp.php`; no
  testbench), in-memory SQLite, the array cache, and `FakeTransport`. The clock
  is frozen at 2026-10-27 12:00 per test. HTTP tests dispatch through the real
  router; bind `request` before setting the user resolver.
