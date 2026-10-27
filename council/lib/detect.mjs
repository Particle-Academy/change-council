// The detector: one deterministic pass over two git refs, the same code in a
// local pre-push hook, in our CI and in Prism's Actions-only flow.
//
// It is a FLOOR, not a ceiling. A behaviour change behind an unchanged
// signature is invisible to every extractor here; only a CHANGELOG marker, a
// release-notes declaration, a snapshot expectation or a manual proposal can
// catch it. The report says so every time.

import { readFileSync } from 'node:fs';
import { changelog } from './extractors/changelog.mjs';
import { deprecated } from './extractors/deprecated.mjs';
import { dts } from './extractors/dts.mjs';
import { jsonschema } from './extractors/jsonschema.mjs';
import { manifests } from './extractors/manifests.mjs';
import { migrations } from './extractors/migrations.mjs';
import { notes } from './extractors/notes.mjs';
import { php } from './extractors/php.mjs';
import { surface, surfaceRuleFor } from './extractors/surface.mjs';
import { matchesAny } from './finding.mjs';
import * as G from './git.mjs';

export const DETECTOR_VERSION = '0.1.0';

export const BLIND_SPOT = 'No finding is a floor, not a ceiling: a behaviour change behind an unchanged signature is invisible to every extractor. Declare it (CHANGELOG BREAKING, release-notes "BREAKING CHANGE:", a surface snapshot expectation, or council:propose). Behaviour changes are on you.';

export const DEFAULTS = {
    kind: null,
    firstParty: ['particle-academy/', '@particle-academy/'],
    testPaths: ['tests/**', 'test/**', 'spec/**', '**/__tests__/**', '**/fixtures/**', '**/*.test.*', '**/*.spec.*', '**/*Test.php'],
    internal: ['**/internal/**', '**/Internal/**', '**/*.internal.*', '.github/**', 'sandbox/**', '.council/decisions/**'],
    schemas: ['resources/schema/**/*.json', 'schema/**/*.json', 'schemas/**/*.json', '**/*.schema.json'],
    migrations: ['database/migrations/**/*.php'],
    surfaces: [{ path: '.council/surface/**/*.json', class: 'contract' }],
    dts: ['dist/**/*.d.ts', 'dist/**/*.d.mts', 'dist/**/*.d.cts', 'index.d.ts', 'types/**/*.d.ts'],
    bundled: [],
};

const EXTRACTORS = { manifests, changelog, deprecated, migrations, jsonschema, surface, dts, php, notes };

function loadConfig(show, to, errors) {
    const text = show(to, '.council/contracts.json');
    if (text === null) return { ...DEFAULTS };
    let user;
    try {
        user = JSON.parse(text);
    } catch {
        errors.push('.council/contracts.json is not valid JSON; defaults used');
        return { ...DEFAULTS };
    }
    const merged = { ...DEFAULTS };
    for (const key of ['testPaths', 'internal', 'schemas', 'migrations', 'dts', 'bundled', 'firstParty']) {
        if (Array.isArray(user[key])) merged[key] = [...DEFAULTS[key], ...user[key]];
    }
    if (Array.isArray(user.surfaces)) merged.surfaces = [...user.surfaces, ...DEFAULTS.surfaces];
    if (user.kind === 'app' || user.kind === 'library') merged.kind = user.kind;
    return merged;
}

function inferKind(show, to) {
    try {
        const composer = JSON.parse(show(to, 'composer.json') ?? 'null');
        if (composer?.type === 'project') return 'app';
    } catch {
        /* unreadable manifests are reported by their extractor */
    }
    try {
        const pkg = JSON.parse(show(to, 'package.json') ?? 'null');
        if (pkg?.private === true && !pkg.main && !pkg.exports && !pkg.bin && !pkg.types) return 'app';
    } catch {
        /* as above */
    }
    return 'library';
}

const TEST = { kind: 'test-fixture', reason: 'test fixture: not shipped to consumers' };
const INTERNAL = { kind: 'internal', reason: 'internal-only path: nothing exports or vendors it' };

function applyWaivers(findings, config) {
    for (const f of findings) {
        if (f.waived || !f.file) continue;
        if (matchesAny(f.file, config.testPaths)) f.waived = TEST;
        else if (matchesAny(f.file, config.internal)) f.waived = INTERNAL;
    }
    return findings;
}

/**
 * @param {{repo: string, from?: string|null, to?: string, releaseNotes?: string|null, fromDts?: string, toDts?: string}} options
 */
export function detect(options) {
    const repo = options.repo ?? process.cwd();
    const report = {
        schema: 'change-council/report.v1',
        detector: { version: DETECTOR_VERSION, mode: 'report-only' },
        ok: true,
        repo,
        from: null,
        to: options.to ?? 'HEAD',
        fromSha: null,
        toSha: null,
        kind: null,
        declared: null,
        findings: [],
        summary: { major: 0, minor: 0, info: 0, waived: 0 },
        coverage: {},
        notes: [],
        errors: [],
    };
    const fail = (message) => {
        report.ok = false;
        report.errors.push(message);
        return report;
    };

    try {
        report.toSha = G.resolveRef(repo, report.to);
        report.from = options.from ?? G.previousTag(repo, report.to);
        if (report.from === null) {
            report.notes.push('No previous tag (v*) before the compared commit: nothing to compare, so no Change is needed.');
            report.notes.push(BLIND_SPOT);
            return report;
        }
        report.fromSha = G.resolveRef(repo, report.from);
    } catch (error) {
        return fail(error.message);
    }

    const show = (ref, path) => (path ? G.show(repo, ref, path) : null);
    const config = loadConfig(show, report.toSha, report.errors);
    report.kind = config.kind ?? inferKind(show, report.toSha);
    const fileCache = new Map();
    let releaseNotes = null;
    if (options.releaseNotes) {
        try {
            releaseNotes = readFileSync(options.releaseNotes, 'utf8');
        } catch (error) {
            fail(`--release-notes ${options.releaseNotes}: ${error.message}`);
        }
    }

    const ctx = {
        repo,
        from: report.fromSha,
        to: report.toSha,
        options,
        config,
        kind: report.kind,
        releaseNotes,
        changes: G.changes(repo, report.fromSha, report.toSha),
        show,
        filesAt: (ref) => {
            if (!fileCache.has(ref)) fileCache.set(ref, G.files(repo, ref));
            return fileCache.get(ref);
        },
        tagMessage: () => G.tagMessage(repo, report.to),
        commitMessages: () => G.commitMessages(repo, report.fromSha, report.toSha),
        isFirstParty: (name) => config.firstParty.some((prefix) => name.startsWith(prefix)),
        surfaceRule: surfaceRuleFor(config),
        isSurface: (path) => surfaceRuleFor(config)(path) !== null,
        error: (message) => fail(message),
        coverage: (name, status, detail) => {
            report.coverage[name] = { status, detail };
        },
        declare: (value) => {
            report.declared = value;
        },
    };

    for (const [name, extractor] of Object.entries(EXTRACTORS)) {
        try {
            const found = extractor(ctx);
            report.findings.push(...found);
            report.coverage[name] ??= { status: 'ran', detail: `${found.length} finding(s)` };
        } catch (error) {
            report.coverage[name] = { status: 'error', detail: error.message };
            fail(`${name}: ${error.message}`);
        }
    }

    applyWaivers(report.findings, config);
    const order = { major: 0, minor: 1, info: 2 };
    report.findings.sort((a, b) => (a.waived ? 1 : 0) - (b.waived ? 1 : 0) || order[a.severity] - order[b.severity] || a.class.localeCompare(b.class) || a.subject.localeCompare(b.subject));
    for (const f of report.findings) {
        if (f.waived) report.summary.waived++;
        else report.summary[f.severity]++;
    }
    report.notes.push(BLIND_SPOT);
    return report;
}
