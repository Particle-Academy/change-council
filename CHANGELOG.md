# Changelog

Notable changes to `particle-academy/change-council`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases; read those entries before
upgrading. The wire formats (`COUNCIL-PETITION v1`, `COUNCIL-REPLY v1`,
`change-council/report.v1`, `change-council/decision.v1`,
`change-council/feed.v1`, `change-council/surface.v1`) are versioned on their
own: a breaking change to one of them ships as a new version beside the old.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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
