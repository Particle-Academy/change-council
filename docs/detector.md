# The detector

`council/check.mjs` compares two git refs and reports changes a consumer of the
package could feel. Its findings are what a Change "covers". It is plain Node
with zero dependencies (built-ins only), and the same file runs in a local
pre-push hook, in GitHub Actions on public repos, and behind
`php artisan council:detect`.

**Phase 1 is report-only.** It exits 0 whatever it finds, including when the
detector itself fails (the report then says `"ok": false` and lists the error).
`--strict` is reserved for the Phase 3 gate and refuses to run (exit 2) rather
than report a pass that nothing gated.

```bash
node council/check.mjs                                  # previous v* tag -> HEAD
node council/check.mjs --from v1.4.0 --to v1.5.0 --json --out report.json
node council/check.mjs --release-notes notes.md        # Prism's "BREAKING CHANGE:" line
node council/check.mjs --from-dts old/dist --to-dts new/dist   # dist/ not committed
```

The previous ref defaults to `git describe --tags --abbrev=0 --match 'v*' <to>^`.
With no previous tag there is nothing to compare, and the report says so.

## Findings

```json
{"class": "platform-floor", "subject": "composer:php", "before": "^8.3", "after": "^8.4",
 "severity": "major", "file": "composer.json", "line": null, "note": "floor 8.3.0 -> 8.4.0",
 "waived": null, "fingerprint": "<sha256(class|subject|before|after)>"}
```

The fingerprint comes only from git content, so the same change fingerprints the
same on every machine. The report (`change-council/report.v1`) also carries
`summary`, `coverage` (what each extractor did, or why it skipped), `notes`,
`errors` and `declared` (`no-breaking-changes` when the release notes say so).

## What it catches

| Class | Fires on | Does not fire on |
|---|---|---|
| `third-party-major` | A runtime dependency's declared floor crossing a major (`require`, `dependencies`, `optionalDependencies`, pyproject `dependencies`). A pre-1.0 minor counts as a major. In an APP, a locked version crossing a major (`composer.lock`, `package-lock.json`, `pnpm-lock.yaml`). | A widened range; a floor raised within the same major; a LIBRARY's lockfile (reported as info: consumers resolve their own) unless the dependency is listed in `bundled`. |
| `platform-floor` | composer `php`, a new `ext-*`, `engines.*`, `requires-python` floor raised, or a runtime version dropped (ceiling lowered). | A ceiling raised. |
| `peer-floor` | A peer dependency floor crossing a major (major) or raised within it (minor); a first-party sibling floor crossing a major. | A peer range widened. |
| `api-removal` | TS: an export removed from an entry `.d.ts` (following `export {..} from` and `export *` into hashed chunks), or a function's minimum required argument count raised. PHP: a public class, method, constant, property or enum case removed or made non-public; a new required parameter; a parameter type or by-ref change; a class or method made final; a static-ness change; a method added to an interface or an abstract method to an abstract class. | Additions. Any other `.d.ts` text change, and renamed/removed optional PHP parameters or return-type changes, are `minor`. |
| `schema` | A new migration whose `up()` drops, renames or retypes; an edited or deleted shipped migration; a JSON schema property removed, `required` added, enum value removed, type narrowed, `additionalProperties` tightened to false, `const` changed, a bound tightened, `oneOf`/`anyOf` alternatives removed, a schema file deleted; a new `vN+1` file beside the old one (deprecation-shaped). | Additive migrations (a `down()` that drops is not read); new optional properties; new enum values. |
| `deprecation` | A `### Deprecated` or `### Removed` bullet in a CHANGELOG entry that is new between the refs; a new `@deprecated` in source (keyed by the declaration it precedes, so a moved tag does not re-fire). | The CHANGELOG preamble; `@deprecated` in `dist/` (counted once, from source). |
| `declared-breaking` | A `BREAKING` marker in a new CHANGELOG entry; `BREAKING CHANGE:` in `--release-notes`, in the annotated tag message of `--to`, or in a commit message between the refs. | `No breaking changes.` (recorded on the report as `declared`). |
| `contract` and any custom class | A committed surface snapshot losing an item (major), an item's value changing (the snapshot's `changed` severity, default major). | An item added (info). |

### Surface snapshots: how Prism's classes plug in

Anything the built-in extractors cannot read (MCP tool names and input schemas,
artisan signatures, published config keys, event names, ACP
`RESERVED_META_KEYS`, a support matrix, a pricing table, corpus expectations)
goes in a committed snapshot that the repo's OWN suite generates, with a test
that fails when the snapshot is stale. The detector diffs it without knowing what
it describes:

```json
{"schema": "change-council/surface.v1", "class": "support-matrix", "changed": "major",
 "items": {"claude-cli": ">=2.1.295"}}
```

- Default location: `.council/surface/**/*.json`, class `contract`.
- A bare object (keys are items) or a bare array of strings also works.
- `changed: "info"` makes value movement info-only (a price change) while a
  removed row stays major.
- Other paths map in through `.council/contracts.json`, e.g. Prism's corpus:
  `{"surfaces": [{"path": "suites/*/cases.json", "class": "corpus-expectation"}]}`.

## Waivers (listed in the report, never counted, never silent)

| Kind | Applies to |
|---|---|
| `dev-only` | `devDependencies`, `require-dev`, and dev entries in a lockfile. |
| `test-fixture` | Files under `tests/`, `test/`, `spec/`, `__tests__/`, `fixtures/`, `*.test.*`, `*.spec.*`, `*Test.php`. |
| `internal` | `**/internal/**`, `**/Internal/**`, `*.internal.*`, `.github/`, `sandbox/`, a PHP class or method tagged `@internal`, and any glob in `contracts.json` `internal`. |

## `.council/contracts.json` (optional)

```json
{
  "kind": "library",
  "internal": ["resources/schema/private/**"],
  "testPaths": [],
  "schemas": ["contracts/*.json"],
  "migrations": [],
  "surfaces": [{"path": "suites/*/cases.json", "class": "corpus-expectation", "changed": "major"}],
  "dts": [],
  "bundled": ["some-lib-inlined-by-tsup"],
  "firstParty": ["@prism/"]
}
```

Lists extend the defaults. `kind` is inferred otherwise: composer `type: project`,
or a private `package.json` with no `main`/`exports`/`bin`/`types`, is an app.

## What it cannot see, said plainly

- **A behaviour change behind an unchanged signature.** A method that now
  returns 5 instead of 30, a default flipped, a check that now fails closed:
  every extractor sees nothing (there is a test asserting exactly that). Only a
  CHANGELOG `BREAKING` marker, a release-notes `BREAKING CHANGE:`, a snapshot
  expectation, or `council:propose` catches it. The report says this on every
  run.
- Protected PHP members (they matter to subclasses) are not compared.
- `.d.ts` that is not committed is not compared unless you pass two built
  directories. Most of our TS repos do not commit `dist/`.
- TypeScript type narrowing that keeps the symbol and its required arity is
  `minor`; the detector does not reason about types.
- `pnpm-lock.yaml`: direct dependencies of the root importer only.
- Config keys, routes, env vars, artisan signatures and event names are seen
  only through a surface snapshot.
- A transitive dependency moving in a library: by design (consumers resolve
  their own), unless it is bundled.
- Without `php` on PATH the PHP extractor skips and says so in `coverage`.

It is a floor, not a ceiling.
