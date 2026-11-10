# Changelog

Notable changes to `particle-academy/change-council`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases; read those entries before
upgrading. The wire formats (`COUNCIL-PETITION v1`, `COUNCIL-REPLY v1`,
`change-council/report.v1`, `change-council/decision.v1`,
`change-council/feed.v1`, `change-council/surface.v1`,
`COUNCIL-CENSUS-REQUEST v1`, `COUNCIL-CENSUS v1`) are versioned on their
own: a breaking change to one of them ships as a new version beside the old.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

## [0.7.1] - 2026-11-10

**Consumer action:** none for Fancy's councils; the defaults are unchanged. A
council for any other estate should set `council.census.first_party` (and
`council.census.publisher`), or every census keeps none of its packages. No
migration, no page re-publish.

### Fixed
- **The census first-party rule is host configuration, not Fancy's names.**
  `CensusParser` hard-coded `particle-academy/*` (Composer),
  `@particle-academy/*` (npm) and `fancy-*` (PyPI), so another estate's council
  dropped all of its own packages as `not_first_party`: in Prism's council its
  PyPI distributions (`prism-ai-core` among them) and `prism-conformance`. The
  rule is now `council.census.first_party`, per ecosystem, a list of `patterns`
  (an exact name or a literal prefix with one trailing `*`, never a regex) plus
  optional exact `names`. Unset, it is exactly the old rule. It is validated
  once at boot; an invalid entry throws an `InvalidArgumentException` naming it
  and is never ignored. Matching is a prefix or exact comparison, so nothing
  from config runs as a regex against a reply.
- **The census request names the host's own packages.** Its per-ecosystem lines,
  its example name and the publisher it names (`council.census.publisher`,
  default `Particle Academy`) come from the same config. With the defaults the
  text is byte for byte the 0.7.0 text.
- **A name or version ending in a newline is refused.** The name and version
  shapes ended in `$` without the `D` modifier, which also matches before a
  final newline, so `"particle-academy/prism\n"` was accepted and stored with
  the newline. They are now `bad_name` / `bad_version`.

### Changed
- `CensusParser::parse()` and `::reason()` take an optional `FirstParty`, and
  `CensusText::render()` an optional third argument; omitted, they use the
  defaults. `CensusParser::PREFIXES` is deprecated (it holds the defaults only).

## [0.7.0] - 2026-11-10

**Consumer action:** re-publish the pages:
`php artisan vendor:publish --tag=council-pages --force`, then
`php artisan council:pages-check`. No migration. Nothing else changes unless you
register the new hook.

### Added
- **`Council::resolveActorUsing(callable)`** (CC-fancy-0021). The callable gets
  the authenticated user and returns the id an owner action is recorded as, in
  the audit log and the decision (`owner:<id>`; an answer already starting with
  `owner:` is kept as is). Without a hook nothing changes: `owner:<email>`, or
  the user's key when it has no email. The answer is validated: a non-empty
  string of at most 191 characters, valid UTF-8, with no control characters or
  newlines. Anything else refuses the owner action with `invalid_actor` (HTTP
  500, with a `detail` naming the hook) and records nothing. It never falls
  back to the email, because the audit log is append-only.

### Fixed
- **The Change page no longer widens a phone screen.** Its five tabs sat in a
  list with no wrap and no scroll, so at a 390px viewport the document grew to
  426px and the whole page scrolled sideways. The tab list now scrolls inside
  its own box (`overflow-x-auto`), tabs neither shrink nor wrap, and arrow-key
  navigation and each tab's `id` are unchanged. Each tab also gains a
  `data-action="council-tab-<name>"` handle. The same pass found two more on
  that page: the silence-policy table (and the package and impact tables) had
  no scroll box, so react-fancy's frame clipped the last column; and the audit
  timeline's hashes and JSON widened the page to 825px once the Audit tab was
  open. Every table now scrolls inside its frame and the timeline wraps long
  tokens. The Index, Members and Member pages were measured at 390px and
  already contain their tables. **Re-publish the pages** to get this;
  `council:pages-check` reports the old copy as stale.
- **The quick start's publish path was lower case.** It suggested
  `js/pages/council` for a lab that globs `./pages/**`; the pages render as
  `Council/Index` and so on, so on a case-sensitive filesystem (Linux, most CI
  and production) a literal resolver found nothing. It now says
  `js/pages/Council`, with a warning about case. If you followed the old line,
  rename the folder to `Council` and re-publish the pages.

## [0.6.0] - 2026-11-08

**Consumer action:** run `php artisan migrate` (one additive migration: two new
tables), then re-publish the pages:
`php artisan vendor:publish --tag=council-pages --force`. Nothing changes for a
member until it answers a census; until then its hand-written packages stand.

### Added
- **The census.** A member's `packages` came only from the hand-written seed
  (`inferred` or `declared`), so they missed what members really install, and
  nothing could write `census_at`. Now:
  - `council:census-requests` queues one `COUNCIL-CENSUS-REQUEST v1` per active,
    external member with no outstanding request (removed and internal members
    get none), and lists the queue for the clerk, in the same pattern as
    `council:petitions` (`--json`, `--mark-sent=<id> --result=<raw JSON>`, plus
    `--list` to queue nothing). The request asks the member to read its
    lockfiles and list every `particle-academy/*` (Composer),
    `@particle-academy/*` (npm) and `fancy-*` (PyPI) package with its installed
    version. Audited as `census.requested` and `census.<delivery>`.
  - `council:census-ingest` reads a `COUNCIL-CENSUS v1` reply strictly: one
    header line, ONE JSON object `{nonce, apps, packages}`, unknown keys refused,
    a closed ecosystem enum, and size caps (64 KB, 500 packages, 50 apps).
    The sender must match the request's address and nonce, as for votes. A row
    that is not a first-party package (or has a non-plain name or version, or
    repeats) is dropped with a recorded reason. The body is data; nothing in it
    is executed. `council:ingest` routes a census here, so it is never stored as
    an invalid vote.
  - A valid census **replaces** the member's `packages` (`source: census`, each
    row `{ecosystem, name, version}`) and sets `census_at`. `watches`, owner-set
    fields and petitions already created are untouched. It is audited as
    `census.applied` with a before/after diff; the audit carries no free text
    from the reply, so neither does the feed.
  - `voters()` uses the census from then on (a census row's petition reads
    "your census lists ... (census YYYY-MM-DD)"), and `census_stale` reads its
    age, as before.
- Members list and profile (pages and API): the packages' **source** as a badge,
  the census date, and an outstanding **census requested** state (queued or
  sent). Wire field, additive: a member's `census_request`
  (`{id, delivery, queued_at, sent_at}` or null). A census package row carries
  `version` instead of `constraint`.
- New wire formats, versioned on their own: `COUNCIL-CENSUS-REQUEST v1` and
  `COUNCIL-CENSUS v1` ([docs/census.md](docs/census.md)).
- Migration `2026_11_08_100000_create_council_census_tables`:
  `council_census_requests` and `council_census_replies`. No existing row is
  changed.

### Changed
- A petition's `why` is capped at 255 characters (its column's length), since a
  census name and version are member-supplied.
- The package's own `phpunit.xml` now also forces in-memory SQLite through
  `env` and `server`. The test kernel already built that connection explicitly.

## [0.5.1] - 2026-11-08

**Consumer action:** run `php artisan migrate` (one additive migration), then
re-publish the pages: `php artisan vendor:publish --tag=council-pages --force`.
Nothing else changes until the owner removes a member.

### Added
- **Remove a member from the council** (owner, 2026-11-08). Owner method
  `Ledger::removeMember(address, owner, reason?)`, reachable only through the
  API behind the `owner` ability (`POST members/{address}/remove {reason?}`),
  never from a command, and a **Remove from council** control on the member
  profile, staged behind a confirmation. The row is never deleted: it is marked
  `removed_at`, so its voting record and audit history stay readable. A removed
  member:
  - gets no petitions on any later round, and is no longer whitelisted;
  - leaves the census check (a removed member with no census no longer holds a
    Change in `census_stale`);
  - leaves the members list; the API's `GET members` lists it under
    `former_members`, and the members page under **Former members**;
  - is never re-added, or changed, by `council:seed-consumers`.
- Its petitions in rounds that are still open are **withdrawn**: each gets
  `withdrawn_at` and outcome `withdrawn`, and is audited as `petition.withdrawn`
  (`counted: false`) beside one `consumer.removed` event listing them. The tally
  skips a withdrawn petition: it is never a silent required voter, never
  extends or escalates a round, and its vote no longer counts toward a blocking
  conflict. A reply to it afterwards is stored as `invalid` with problem
  `petition_withdrawn`. The clerk's `council:petitions` queue skips it. Petitions
  in closed rounds are history and stay exactly as tallied.
- **PS Mapper** (`b-mo-fluid@ps-mapper`) is seeded as a declared member of
  `react-fancy`, `fancy-compliance-ui`, `particle-academy/fancy-compliance` and
  `agent-integrations` (owner, 2026-11-08; the only consumer of
  `fancy-compliance-ui`).
- Wire fields, all additive: a member's `removed_at` and `stats.withdrawn`; a
  record row's `withdrawn`; a petition's `withdrawn_at`; a consumer's
  `removed_at`.
- Migration `2026_11_08_000000_add_council_removed_and_withdrawn`: nullable
  `council_consumers.removed_at` and `council_petitions.withdrawn_at`. No
  existing row is changed.

### Removed
- `tribeshare@tribetrip` is no longer in the consumer seed (removed from the
  council by the owner, 2026-11-08). Re-seeding never deletes, so an existing
  install keeps the row until the owner removes it in the lab.

### Changed
- A change summary's petition counts (`total`, `answered`, `conflicts`,
  `queued`) leave out withdrawn petitions.
- The pages changed (`Members.tsx`, `Member.tsx`, `Show.tsx`, `parts/api.ts`):
  re-publish them.

## [0.5.0] - 2026-11-07

**Consumer action:** run `php artisan migrate` (one additive migration), then
re-publish the pages: `php artisan vendor:publish --tag=council-pages --force`.
Nothing else. With the defaults (every weight 1, threshold 1) every outcome is
exactly what 0.4 would decide, so nothing changes until the owner changes a
weight or the threshold.

### Added
- **Council members.** A members list (`{prefix}/members`, page
  `Council/Members`) and a profile per member (`{prefix}/members/{address}`,
  page `Council/Member`), linked from a Council nav on every page. The list is a
  sortable table: name and address, project and workspace, weight, silence
  policy, declared package count, votes cast, approve/conflict/abstain counts,
  missed petitions, last vote and census. The profile shows the member's
  project, declared and watched packages, and its voting record: every petition
  sent to it, newest first, with change, round, verdict (or silent/undelivered),
  lateness, reason, impact, severity and sent/replied times.
- **Vote weights and a weighted blocking threshold** (owner ruling,
  2026-11-07). Each member has a `weight` (integer, 0 to `council.weights.max`,
  default 1). A round's blocking conflicts (`blocker` or `major`, as before)
  block only when the combined weight of the members raising them reaches the
  council's blocking threshold (`council.tally.blocking_threshold`, default 1,
  owner-settable in the lab). A weight-0 member is advisory: its conflict is
  recorded and shown, and never blocks. Blocking conflicts below the threshold
  do not block: the Change is approved with notes and flagged
  `blocking_below_threshold`. Round 2 escalation and the owner override with
  acknowledgements work as before, on the conflicts that do block.
- The tally records the weights it used, the blocking weight, the threshold,
  and the conflicts below the threshold or advisory; the change page shows it
  ("Blocking weight 2 of threshold 3"), and each petition shows the member's
  weight. A decision record's round carries an optional `weighing` object.
- Owner methods `Ledger::setWeight()`, `setProject()` and
  `setBlockingThreshold()`, each validated and audited (`consumer.weight`,
  `consumer.project` by field name only, `council.blocking_threshold`). Like
  every owner method, they are reachable only through the API behind the
  `owner` ability, never from a command.
- Member project fields: `project_name`, `project_description` and
  `project_url` (absolute `http`/`https` only; anything else is refused, and
  the pages refuse it again before drawing a link).
- JSON API: `GET members`, `GET members/{address}`, and the owner writes
  `PUT members/{address}/weight`, `PUT members/{address}/project` and
  `PUT settings/blocking-threshold`.
- Config: `council.tally.blocking_threshold`, `council.tally.max_threshold`,
  `council.weights.max`, `council.members.project_name_max` and
  `council.members.project_description_max`.
- Migration `2026_11_07_000000_add_council_members_weights_and_profiles`:
  additive columns (`council_consumers.weight` default 1 and the three project
  fields, `council_changes.blocking_below_threshold`) and a `council_settings`
  table. No existing row is changed or removed.

### Changed
- API JSON now escapes `<`, `>` and `&` (`JSON_HEX_TAG`, `JSON_HEX_AMP`). The
  decoded strings are identical; a client that compares raw bytes would see
  `<` where it saw `<`.
- `Ledger::openConflicts()` reads a round closed by a 0.5 tally as recorded,
  and weighs an open round against today's weights and threshold. A round closed
  before 0.5 keeps its old meaning: closed rounds are never re-tallied.
- The pages changed (new `Members.tsx`, `Member.tsx` and `parts/nav.ts`; Index
  and Show gained the nav and the weight display): re-publish them.

## [0.4.1] - 2026-11-03

### Security
- **The detector now runs `git` and `php` by absolute path.** On Windows a bare
  name could run a same-named executable from the repository under inspection or
  from the start directory instead of the real program, and CI runs on Ubuntu, so
  nothing here caught it. It now finds each one on `PATH` once per process, reading
  only absolute entries, and never the current directory or the repository. Both
  spawns now also set `windowsHide`.
  - With no `git`, the report now has `ok: false` and an error saying so, instead
    of the misleading `unknown ref`.
  - With no `php`, the coverage note still says `php not on PATH`, as before.
  - A `.cmd` or `.bat` on `PATH` is not run. A real executable later on `PATH` is
    used instead. A batch file was never run here, and the message now names it.
  - `COUNCIL_PHP` still works. A value containing a directory is used as given; a
    bare name now goes through the same lookup.

  **Consumer action: none**, unless the only `git` or `php` you have is in the
  current directory or on a relative `PATH` entry, in which case add it to `PATH`
  by absolute path. If you fetch the detector pinned by hash, re-pin when you take
  this: `council/lib/git.mjs` and `council/lib/extractors/php.mjs` changed. No new
  file was added to the list. The lab pages are untouched, so there is no
  re-publish.

## [0.4.0] - 2026-11-01

Additive. **Consumer action: none.** The new event fires only for a host that
already opted into a notifier (`Council::notifyUsing()`, `Council::notifyGenie()`
or `COUNCIL_NOTIFY_GENIE=true`). With no notifier bound, nothing changes. **The
lab pages are untouched, so there is no re-publish.** `council:pages-check`
stays current.

### Added
- **`awaiting_owner` owner event.** It fires after commit when a Change enters
  `awaiting_owner`. The only way in is a non-whitelisted `propose()`: an
  agent, or a GitHub issue author who is not on the list. Until now such a
  proposal sat silently until someone happened to look (CC-fancy-0003 waited
  unnoticed). A whitelisted proposal fires nothing, and a rolled-back one
  fires nothing.
  - `dedupeKey`: `council:{ref}:awaiting_owner`. A Change cannot re-enter
    `awaiting_owner`, so the key needs no round discriminator.
  - The body carries the ref, the state, `source`, `initiator`, and
    `counts: packages=N classes=N`, plus the line "The owner must decide:
    approve or reject this initiation in the lab." It never carries the title,
    summary, approach, pros or cons.
  - The initiator comes from the proposal's input, so anything that is not
    shaped like an agent address or a GitHub login is sent as `[in the lab]`
    (`OwnerEvent::identity()`).
  - `level` is `info`. The Genie app-inbox contract allows only `info`,
    `success` and `failure`; it has no warning level. A pending proposal is not
    a failure, so the subject says "(owner decision needed)" instead.
- `OwnerEvent::$source`, `OwnerEvent::$initiator` (set on `awaiting_owner`
  only) and `OwnerEvent::needsOwner()`.

### Changed
- **The `escalated` notice says plainly that the owner must decide.** The
  subject gains "(owner decision needed)", and the body gains "The owner must
  decide: approve or reject this Change in the lab." `level` (`failure`) and
  `dedupeKey` are unchanged, so Genie's 10-minute dedupe still matches across
  the upgrade.

## [0.3.0] - 2026-10-31

Additive: a host that does nothing is unaffected. No notifier is bound by
default, and nothing is sent. **The lab pages are untouched, so there is no
re-publish.** `council:pages-check` stays current.

### Added
- **Owner-event notifier** (`ChangeCouncil\Notify\OwnerEventNotifier`): an
  optional hook that tells someone, AFTER COMMIT, when something the owner
  cares about happens. Until now an owner decision in the lab was invisible to
  the clerk until the clerk happened to look. It is queued with the
  connection's after-commit hook, so a rolled-back transition (including one
  inside a host transaction) never notifies. Events (`OwnerEvent::$event`):
  - `initiation_approved` / `initiation_rejected`
  - `override_approve` / `override_reject`
  - `revoked`
  - `round1_closed` / `round2_closed` (every closed tally)
  - `escalated` (sent alongside the round's close when the tally escalates to
    the owner)

  An `OwnerEvent` carries refs, states, a reason code and the tally counts.
  It never carries a title, note, reason or reply text: the same masking rule
  as the feed. A notifier that throws never fails or rolls back the decision.
  The exception is swallowed and recorded as a `notify.failed` audit event,
  without its message.
- **A Genie app-inbox implementation** (`ChangeCouncil\Notify\GenieInboxNotifier`).
  It sends `POST {GENIE_INBOX_URL}/v1/inbox` with a bearer token and these
  fields:
  - `to` (optional)
  - `subject`
  - `body` (plain text, capped at 8 KB)
  - `level`: `success` for approvals, `failure` for escalation, otherwise
    `info`
  - `link` (the Change's lab page, when `council.lab_url` is set)
  - `dedupeKey`: `council:{ref}:{event}`

  **Genie provides the env:** `GENIE_INBOX_URL` and `GENIE_INBOX_TOKEN` are
  given to a site Genie hosts. They are read with `getenv()` AT SEND TIME and
  never through config, because `config:cache` would freeze a token that Genie
  rotates on every restart. They are never stored, logged or audited.

  Every outcome is an audit event carrying the event, the ref and a status
  only:
  - a 2xx is `notify.sent`;
  - a 404 (the token is unknown, rotated or revoked) is `notify.failed` and is
    not retried;
  - a 429 is `notify.failed`, with `retry_after` recorded and no blocking
    retry;
  - any other status is `notify.failed`;
  - a network error is `notify.failed` with status 0;
  - a missing env is `notify.skipped` (`env_missing`): a no-op, not an error.
- **How a host opts in:** set `COUNCIL_NOTIFY_GENIE=true` (config
  `council.notify.genie`) or call `Council::notifyGenie()`, and optionally
  set `COUNCIL_NOTIFY_TO` (otherwise Genie's default agent).
  `Council::notifyUsing($notifierOrClosure)` binds any other notifier.
- **`council:status` reports the notifier.** The list view ends with a
  `notifier:` line. `council:status --notifier [--json]` prints only that
  line, and `Council::notifierStatus()` returns the same for a host status
  page. Each shows whether a notifier is bound and whether each Genie env var
  is present, as booleans only, never the values.

## [0.2.2] - 2026-10-30

**Consumer action: none.**

### Fixed
- **A delivered petition was recorded as `undelivered`.** `council:petitions
  --mark-sent --result=...` only understood the JSON-RPC-wrapped tool result
  (`result.content[].text`). The clerk's agentinbox tool hands back the BARE
  result, `{"ok":true,"delivered":1,...}`, and on the first live round
  (CC-fancy-0001) both petitions were confirmed delivered by Genie yet ledgered
  as undelivered. The bare verdict is now read directly: `ok: true` with a
  positive (or absent) `delivered` count is `sent`; `ok: false` keeps its error
  and the reachability rule; `delivered: 0` is not a delivery. The three
  wrapped refusal depths are unchanged.

## [0.2.1] - 2026-10-29

**One consumer action:** re-publish the pages
(`php artisan vendor:publish --tag=council-pages --force`), then
`php artisan council:pages-check` should report every file current. Nothing
else changes; an old published copy keeps working, it just keeps the defect.

### Fixed
- **The list page gave the owner nothing to press.** Owner decisions are taken
  on a Change's own page, and the only way there from `/council` was the ref in
  the table, drawn as plain text that underlined on hover only. The "awaiting
  your approval" callout linked nowhere. On the first live lab an owner sat on
  the list for hours with a Change waiting, believing there was no way to act,
  and no owner event was ever recorded. The callout now carries one button per
  Change awaiting the owner or escalated to them (`data-action="council-review"`,
  "Approve or reject CC-..." / "Decide CC-..."), and every ref in the table is
  drawn as a link.

## [0.2.0] - 2026-10-29

Additive: nothing changes for a host that does nothing, and the authorization
default is still deny-all. **One consumer action:** re-publish the pages
(`php artisan vendor:publish --tag=council-pages --force`) to get the back link
and the silence-policy control; then `php artisan council:pages-check` should
report every file current. An old published copy keeps working against this
version (it ignores the new props), it just lacks both.

### Added
- **`council.owners`** (env `COUNCIL_OWNERS`, comma-separated login emails) and
  an opt-in owner-only authorizer, `Council::authorizeOwners()`. Owners get
  every ability (view, owner, feed); everyone else gets none. Emails are
  compared trimmed and case-insensitively, and **an empty list allows nobody**.
  It also defines a `council-owner` Gate unless the host already has one. These
  are the semantics fancy-labs built for itself, so a host doing the same can
  switch to this and drop its own class. A host that registers its own hook
  with `Council::authorizeUsing()`, or none, is unaffected: `council.owners` is
  read only by the opt-in authorizer. Note for a host that already publishes
  its own `owners` key in `config/council.php`: it is the same key, so it is
  picked up unchanged.
- **A way back to the host** on both lab pages: `council.host_url` (env
  `COUNCIL_HOST_URL`, default `/`) and `council.host_label` (env
  `COUNCIL_HOST_LABEL`, default "Back to the app"). An empty URL hides it.
- **The consumer silence policy is set on the list page.** `Council/Index`
  draws a `Select` per consumer when the hook allows `owner` for the viewer
  (a new `canSetPolicy` prop; the write is still authorized by the API), so the
  owner can set the policy for a consumer no Change has petitioned yet, which
  the per-Change page could not reach.
- **`council:pages-check [--path=]`** and `ChangeCouncil\Laravel\PublishedPages::check()`:
  compare a host's published pages with the ones this version ships, by
  content with line endings normalised, and exit 1 when any file is stale or
  missing. Read-only. Run it in CI after `composer update`, or assert it in the
  host's own suite.

### Fixed
- **The documented authorization sample was unsafe.** The quick start allowed
  `view` for any signed-in user, which on a host with open registration hands
  the ledger (every Change, every reply, the audit log) to anyone who
  registers. The quick start, the README and the `authorizeUsing()` docblock
  now show the owner-only setup and say why. A test fails if a documented
  sample grants `view` unconditionally again. If you copied the old sample,
  replace it: switch to `Council::authorizeOwners()` or grant `view` to a role
  you control.
- The list page said the silence policy could be set "here via the API". It is
  now set here, on the page (see Added).

## [0.1.0] - 2026-10-27

Phases 1 and 2 of the design (`.ai/plans/change-council.md`). **Nothing gates a
release yet**: the detector is report-only and the decision record is written
and verified, not required. Nothing to migrate; this is the first version.

### Added
- **The detector** (`council/check.mjs`), zero-dependency Node, report-only:
  third-party majors (declared ranges; app lockfiles: composer.lock,
  package-lock.json, pnpm-lock.yaml), platform and peer floors, TS `.d.ts`
  export removal and required-arity raises (committed dist or two built
  directories), PHP public-surface removal through PHP's own tokenizer, Laravel
  migrations, JSON-schema structural diff, CHANGELOG `Deprecated`/`Removed`
  and `BREAKING`, new `@deprecated`, release-notes and commit
  `BREAKING CHANGE:`, and committed `.council/surface/*.json` snapshots for
  everything else (how Prism's classes plug in). Waivers for dev-only, test
  fixtures and internal paths are listed, never counted. Exits 0 always;
  `--strict` refuses until Phase 3.
- **The ledger** (Laravel): Changes, rounds, petitions, votes, consumers,
  decisions, and an append-only, hash-chained audit log (model guard, SQLite
  triggers, `council:verify-log`).
- Initiation per the owner rulings: agent finding, agent request, or GitHub
  issue. Whitelisted initiators (agents, or an issue's author) open round 1;
  every non-whitelisted initiation waits for the owner (HITL) with a required
  pros/cons summary, and no petition leaves first.
- Owner decisions (approve or reject an initiation, override, revoke, silence
  policy) are reachable only through the JSON API behind the host's `owner`
  ability, i.e. the authenticated lab UI. There is no artisan command for any
  of them, because an agent can run any command with any flag.
- Two rounds with frozen, digest-bound approaches; tally with one 24h extension;
  a per-consumer silence policy (`escalate` default, `proceed_and_notify` with
  late votes recorded) and a per-Change owner override; owner override at any
  point with a reason and per-conflict acknowledgement; revoke; withdraw.
- The clerk queue (`council:petitions`, `--mark-sent` read at three refusal
  depths) and strict `council:ingest`: replies are data, matched by nonce,
  digest and the Genie-stamped sender, flagged with the gh-untrusted phrase
  list, rendered as text, never applied.
- Ed25519 decision records (`council:export`) through PHP sodium, verifiable in
  PHP and in Node from the same fixture.
- A JSON API and two Inertia pages built from react-fancy (Changes list,
  Change detail with rounds, votes, audit, owner actions and the silence-policy
  editor), behind a host authorization hook that denies everything by default.
- The Overwatch feed: `feed.v1` pull endpoint and a signed webhook push with
  backoff and dead-letter.
- A consumer seed: MOIC with its declared Prism set, and the Fancy consumers
  from the design, `escalate` by default.

### Fixed

- The detector reads the repository it was given even when run from a git hook.
  A hook exports `GIT_DIR` (and in some setups `GIT_WORK_TREE`/`GIT_INDEX_FILE`),
  which git honours over the working directory, so every read went to the
  hook's repository. `council/lib/git.mjs` now strips the repository-locating
  variables before running git (`cleanGitEnv`); everything else, including
  PATH and ssh settings, passes through. The test fixtures do the same: run
  inside the release pre-push hook they had written their `git init` and
  `git config` into the repository being released. Nothing for a consumer to do.
