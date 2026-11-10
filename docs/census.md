# The census

A member's `packages` decide which Changes it is petitioned on. Until 0.6.0 they
came only from the hand-written consumer seed (`source: inferred` or
`declared`), which missed what members really install. The census asks each
member to list what its lockfiles install, and replaces the hand-written list
with the answer.

## The clerk loop

```bash
php artisan council:census-requests --json   # queues one request per member, then [{request, to, nonce, queued_at, text}]
# for each: agentinbox send to=<to> text=<text>, then record the raw tool result:
php artisan council:census-requests --mark-sent=<request> --result='<raw tool JSON>'
# replies: for each message whose text starts with COUNCIL-CENSUS v1
php artisan council:census-ingest --genie-json --file=message.json   # {id, from, at, text}, unaltered
```

- Running `council:census-requests` queues a request for every **active,
  external** member that has no outstanding one (queued or sent, not yet
  answered). Internal members (our own apps, never petitioned) and removed
  members get none. `--list` only lists the queue.
- `--mark-sent` reads the send result exactly as `council:petitions` does: only
  an explicit `ok: true` is `sent`.
- `council:ingest` routes a `COUNCIL-CENSUS v1` message to the census reader, so
  feeding every inbox message to it is safe.

Every step is in the audit log: `census.requested`, `census.sent` /
`census.undelivered` / `census.unreachable`, `census.applied`, and
`census.invalid` / `census.unsolicited` for a refused reply.

## COUNCIL-CENSUS-REQUEST v1

A header (`to`, `request`, `nonce`, `lab`), plain instructions, and the reply
format. It asks the member to read its lockfiles and list every package
published by `council.census.publisher` (default `Particle Academy`) with the
installed version, one line per ecosystem the first-party rule covers. With the
defaults:

- Composer, from `composer.lock`: `particle-academy/*`
- npm, from `package-lock.json`: `@particle-academy/*`
- PyPI, from the Python lock file if any: `fancy-*`

It asks for data only, and says so.

## The first-party rule

`council.census.first_party` decides which names a census keeps, per ecosystem:

```php
'census' => [
    'publisher' => 'Prism',
    'first_party' => [
        'pypi' => ['patterns' => ['prism-ai-*'], 'names' => ['prism-conformance']],
    ],
],
```

A pattern is an exact name or a literal prefix with one trailing `*`; `names`
are exact. Nothing in it is a regex, and matching is a prefix or exact
comparison, so no configured pattern runs against a reply. Unset, the rule is
Fancy's (the three prefixes above). Set, it is the whole rule: an ecosystem it
leaves out keeps nothing. PyPI entries are in PEP 503 normalised form. The rule
is validated when the provider boots, and an invalid entry throws an
`InvalidArgumentException` naming it (`council.census.first_party.pypi.patterns.0`)
rather than being skipped. See the README for the full list of what is refused.

## COUNCIL-CENSUS v1 (strict)

```
COUNCIL-CENSUS v1
{"nonce":"<from the request>",
 "apps":[{"name":"moic","lockfiles":["composer.lock","package-lock.json"]}],
 "packages":[{"ecosystem":"composer","name":"particle-academy/prism","version":"0.140.0"}]}
```

The first line is exactly the header; the rest is exactly ONE JSON object.
Unknown keys anywhere are refused, `ecosystem` is one of `composer`, `npm`,
`pypi`, and every size is capped: the message at 64 KB, 500 packages, 50 apps,
10 lockfiles per app, a name at 214 characters, a version at 100. A malformed
reply is stored as `invalid` with a problem code and changes nothing.

A well-formed row is **dropped**, with a recorded reason, rather than refusing
the census, when it is:

| reason | |
|---|---|
| `not_first_party` | the name is outside the ecosystem's first-party rule (by default the prefixes `particle-academy/`, `@particle-academy/`, `fancy-`) |
| `bad_name` | the rule matches, but the name is not a plain package name |
| `bad_version` | the version is not a plain identifier (a range, a URL, a command) |
| `duplicate` | the same package was already listed |

PyPI names are normalised (PEP 503: lower case, runs of `-`, `_`, `.` become
one `-`) before the prefix check.

## Binding and effect

The sender is the Genie-stamped `from`, never a field in the reply. The reply is
bound to its request by `nonce`: an unknown nonce or another member's nonce is
`unsolicited`; a request is answered once (`already_answered`); a member
removed since the request is refused (`member_removed`). The Genie message id
makes a replay a no-op.

A valid census:

- **replaces** the member's `packages` with the accepted rows (each
  `{ecosystem, name, version}`), sets `source: census` and `census_at`;
- leaves `watches` and every owner-set field (weight, silence policy, whitelist,
  project) untouched;
- never changes a petition already created: it shapes only rounds opened after
  it;
- is audited as `census.applied` with a before/after diff (`added`, `removed`,
  `changed` versions) and the dropped counts by reason. The audit carries only
  validated names, codes and counts, never the reply's free text, so neither
  does the feed.

From then on `voters()` reads the census, and the `census_stale` check (no
required voters, but some member's census is missing or older than
`council.census_max_age_days`) reads its age.

## Content is data

Nothing in a census is executed. The raw text is stored unaltered (up to the
size cap), the `.ai/tools/gh-untrusted.sh` phrase list flags injection-shaped
text, and `apps` is display data only.
