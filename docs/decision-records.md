# Decision records

An approved Change exports a signed record into the package repo:

```bash
php artisan council:export CC-fancy-0007 --to=/path/to/package-repo
# writes .council/decisions/CC-fancy-0007.json; commit it before tagging
```

Phase 2 writes and verifies records. Requiring one at release time (the gate)
is Phase 3.

## change-council/decision.v1

```json
{
  "schema": "change-council/decision.v1",
  "ref": "CC-fancy-0007",
  "origin": "fancy",
  "state": "approved",
  "decided_by": "tally",
  "decided_at": "2026-10-30T12:00:00Z",
  "approved_unopposed": false,
  "packages": [{"ecosystem": "composer", "name": "particle-academy/fancy-flow-php"}],
  "covers": {"versions": ">=0.52.0 <0.53.0", "fingerprints": ["<detector fingerprint>", "..."]},
  "waivers": [{"fingerprint": "...", "kind": "dev-only"}],
  "rounds": [{"number": 1, "digest": "sha256:...", "outcomes": {"approve": 2, "conflict": 0, "abstain": 1, "silent": 0, "unreachable": 0, "undelivered": 0, "invalid": 0}}],
  "ledger_head": "<audit chain head hash at export>",
  "key_id": "a7c07fb4189b2972",
  "signature": "<base64 Ed25519>"
}
```

- `covers.fingerprints` are the non-waived findings in the Change's evidence;
  `covers.versions` comes from `links.versions` when set.
- The record holds counts, never free text from any reply.
- `decided_by` is `tally` or `owner:<id>`.

## Signing

- Ed25519 through PHP's built-in sodium (`sodium_crypto_sign_detached`). No
  dependency.
- The signature covers the **canonical JSON** of every field except `signature`:
  object keys sorted by byte order (recursively), list order kept, no whitespace,
  `/` and non-ASCII unescaped, U+2028/2029 unescaped, floats refused.
- `key_id` is the first 16 hex characters of sha256(raw 32-byte public key).
- `php artisan council:keygen` prints a pair. The secret goes in
  `COUNCIL_SIGNING_KEY`; the public key is published where gates read it
  (design: `Particle-Academy/.github` `council/keys.json`, an owner commit,
  pinned like the detector).

## Verifying

PHP: `app(ChangeCouncil\Records\DecisionRecords::class)->verify($record)` returns
`{ok, reason}` with reason `unknown_schema`, `unknown_key`, `bad_signature` or
`not_canonical`.

Node (built-ins only): `council/lib/record.mjs` `verifyRecord(record, {key_id: base64})`.

Both suites verify the same fixture (`tests/fixtures/decision-record.json`,
signed by PHP, with a `fixture_text` field covering the canonical-JSON corners:
non-ASCII, `/`, a control character, a newline, a quote, U+2028), and both
reject it with any byte flipped. Regenerate with
`php tests/fixtures/make-decision-fixture.php`.

## The audit chain

`council_events` is append-only: the model refuses update and delete, SQLite
triggers refuse them underneath, and every row is hash-chained:

```
hash = sha256(prev_hash + "\n" + canonical({seq, change_id, at, actor, type, data}))
```

`php artisan council:verify-log` recomputes the chain and exits 1 at the first
`hash_mismatch` (an edited row), `broken_link` (a re-hashed row breaks the next
link) or `sequence_gap` (a deleted row). The chain head goes into every decision
record, so a record pins the ledger state it was cut from.

On MySQL and PostgreSQL there are no triggers yet; the model guard and the
hash chain still apply.
