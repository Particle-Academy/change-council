# CI usage

Phase 1 runs the detector in REPORT mode: it tells an author their release is
major and blocks nothing. The gate (a signed decision record required at the
tagged commit) is Phase 3.

## Supply chain: pinned, hashed, isolated

CI fetches `council/check.mjs` from `Particle-Academy/.github`. Three rules
(Prism review B, 2026-10-27):

1. **Pin to a commit SHA, never a branch.** A branch can move under every
   consumer at once.
2. **Check a content hash** of `check.mjs` (and of every file it imports) after
   the fetch, so a SHA typo or a rewritten history cannot slip in different code.
3. **Never run it inside an `id-token: write` job.** Run it in a read-only job
   that the publish job `needs:`. A compromised check can then at worst fail a
   build; it cannot mint a publish token.

The estate currently runs the unpinned `third-party/check.mjs` inside publish
jobs in about 175 workflows; that fix is with the owner (q_55e074ed). Do not
copy that pattern here.

## GitHub Actions (public or private repos)

```yaml
jobs:
  council:
    name: council (report-only)
    runs-on: ubuntu-latest
    permissions:
      contents: read            # nothing else: no id-token, no write
    steps:
      - uses: actions/checkout@v7
        with:
          fetch-depth: 0        # the detector compares against the previous tag

      - name: Fetch the council detector (pinned)
        uses: actions/checkout@v7
        with:
          repository: Particle-Academy/.github
          ref: <40-char commit SHA>
          path: .council-detector
          persist-credentials: false

      - name: Verify the detector content hash
        run: |
          cd .council-detector/council
          find . -type f \( -name '*.mjs' -o -name '*.php' \) ! -path './test/*' -print0 \
            | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1 > /tmp/got
          echo "<expected tree hash>" > /tmp/want
          diff /tmp/got /tmp/want

      - uses: actions/setup-node@v7
        with:
          node-version: 22

      - name: Change report
        run: |
          node .council-detector/council/check.mjs --repo . --out council-report.json
          # Prism: also pass the release notes the tag will carry
          #   --release-notes RELEASE_NOTES.md

  publish:
    needs: council              # the publish job depends on the read-only one
    permissions:
      contents: read
      id-token: write
    # ...
```

`.github` is public, so a private repo fetches it unauthenticated.

To compute `<expected tree hash>` locally, run the same `find ... | sha256sum`
pipeline in a checkout of the pinned SHA. Change the SHA and the hash together,
in one reviewed commit.

PHP packages publish through Packagist's push webhook, so a workflow cannot
stop a PHP release; there the pre-push hook is the gate (below), and the
workflow only reports.

## Local pre-push (the Fancy estate)

The estate's `.githooks/pre-push` runs `.ai/tools/release-preflight.sh` on
`refs/tags/v*`. Phase 1 adds the detector as a reporting step after the repo's
own tests (check 9), printing the summary and never changing the exit code:

```bash
# in release-preflight.sh, report-only until Phase 3
node "$COUNCIL_DIR/council/check.mjs" --repo "$REPO" --to "refs/tags/v$VERSION" || true
```

`$COUNCIL_DIR` is a checkout of `Particle-Academy/.github` at the pinned SHA,
verified with the same content hash. Prism has no pre-push hook; its local tag
script runs the same command before pushing the tag.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | A report was produced (whatever it found), or the detector failed and said so in the report |
| 2 | Usage error: an unknown flag, a missing value, or `--strict` (Phase 3 only) |
