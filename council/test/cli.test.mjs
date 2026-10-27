// The CLI contract: report-only in Phase 1. It exits 0 whatever it finds,
// prints a human summary, writes the JSON report, and refuses to pretend that
// --strict (Phase 3) exists.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { pair, runCli } from './helpers.mjs';

const breakingRepo = (t) => pair(t, { 'composer.json': { name: 'x/y', require: { 'acme/http': '^2.1', php: '^8.3' } } }, { 'composer.json': { name: 'x/y', require: { 'acme/http': '^3.0', php: '^8.4' } } });

test('major findings still exit 0: Phase 1 never fails a release', (t) => {
    const repo = breakingRepo(t);
    const result = runCli(['--repo', repo.dir], repo.dir);
    assert.equal(result.code, 0, result.stderr);
    assert.match(result.stdout, /MAJOR \(2\)/);
    assert.match(result.stdout, /REPORT-ONLY/);
    assert.match(result.stdout, /behaviour changes/i);
});

test('--json prints the structured report and --out writes it', (t) => {
    const repo = breakingRepo(t);
    const out = join(repo.dir, 'council-report.json');
    const result = runCli(['--repo', repo.dir, '--json', '--out', out], repo.dir);
    assert.equal(result.code, 0, result.stderr);
    const printed = JSON.parse(result.stdout);
    const written = JSON.parse(readFileSync(out, 'utf8'));
    assert.equal(printed.schema, 'change-council/report.v1');
    assert.equal(printed.summary.major, 2);
    assert.deepEqual(written.findings.map((f) => f.fingerprint), printed.findings.map((f) => f.fingerprint));
});

test('--strict is reserved for Phase 3 and refuses rather than silently passing', (t) => {
    const repo = breakingRepo(t);
    const result = runCli(['--repo', repo.dir, '--strict'], repo.dir);
    assert.equal(result.code, 2);
    assert.match(result.stderr, /Phase 3/);
});

test('a detector error is loud in the report but still exits 0 in report mode', (t) => {
    const repo = breakingRepo(t);
    const result = runCli(['--repo', repo.dir, '--from', 'no-such-ref', '--json'], repo.dir);
    assert.equal(result.code, 0);
    const report = JSON.parse(result.stdout);
    assert.equal(report.ok, false);
    assert.match(report.errors.join(' '), /no-such-ref/);
});

test('an unknown flag is a usage error, not a silent pass', (t) => {
    const repo = breakingRepo(t);
    const result = runCli(['--repo', repo.dir, '--frmo', 'v1.0.0'], repo.dir);
    assert.equal(result.code, 2);
    assert.match(result.stderr, /unknown option/i);
});
