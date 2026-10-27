// Declared ranges (composer.json, package.json, pyproject.toml) and lockfiles
// (composer.lock, package-lock.json, pnpm-lock.yaml).
//
// The ranges rule: a library's lockfile does not ship, so its movement is info
// only; consumers resolve their own. Declared RUNTIME ranges count. Dev ranges
// are reported and waived as dev-only, never dropped.

import { finding } from '../finding.mjs';
import { ceilingLowered, compare, format, majorKey, parseVersion, rangeBounds } from '../ranges.mjs';

const DEV_ONLY = { kind: 'dev-only', reason: 'development-only dependency: consumers never install it' };

function json(text) {
    if (text === null) return null;
    try {
        return JSON.parse(text);
    } catch {
        return undefined;
    }
}

/** A runtime/peer/dev dependency map from one manifest. */
function sections(ecosystem, manifest) {
    if (manifest === null || manifest === undefined) return [];
    if (ecosystem === 'composer') {
        const require = manifest.require ?? {};
        const runtime = {};
        const platform = {};
        for (const [name, range] of Object.entries(require)) {
            (name === 'php' || name.startsWith('ext-') || name.startsWith('lib-') ? platform : runtime)[name] = range;
        }
        return [
            { kind: 'runtime', deps: runtime },
            { kind: 'platform', deps: platform },
            { kind: 'dev', deps: manifest['require-dev'] ?? {} },
        ];
    }
    if (ecosystem === 'npm') {
        return [
            { kind: 'runtime', deps: { ...(manifest.dependencies ?? {}), ...(manifest.optionalDependencies ?? {}) } },
            { kind: 'peer', deps: manifest.peerDependencies ?? {} },
            { kind: 'platform', deps: manifest.engines ?? {} },
            { kind: 'dev', deps: manifest.devDependencies ?? {} },
        ];
    }
    return [
        { kind: 'runtime', deps: manifest.dependencies ?? {} },
        { kind: 'platform', deps: manifest.python ? { python: manifest.python } : {} },
    ];
}

/** The two fields of pyproject.toml that matter, read without a TOML library. */
export function parsePyproject(text) {
    if (text === null) return null;
    const python = /^\s*requires-python\s*=\s*["']([^"']+)["']/m.exec(text)?.[1] ?? null;
    const dependencies = {};
    const block = /^\s*dependencies\s*=\s*\[([\s\S]*?)\]/m.exec(text);
    if (block) {
        for (const m of block[1].matchAll(/["']([^"']+)["']/g)) {
            const spec = m[1].split(';')[0].trim();
            const name = /^[A-Za-z0-9._-]+/.exec(spec)?.[0];
            if (name) dependencies[name.toLowerCase()] = spec.slice(name.length).replace(/^\[[^\]]*\]/, '').trim() || '*';
        }
    }
    return { python, dependencies };
}

function compareRanges(ctx, { ecosystem, file, kind, name, before, after }) {
    const subject = `${ecosystem}:${name}${kind === 'peer' ? ' (peer)' : ''}`;
    if (before === undefined || after === undefined) {
        // Added or removed. A new runtime dependency is the allowlist's job;
        // a removed one is listed so nobody is surprised.
        if (kind === 'platform' && before === undefined && after !== undefined && (name.startsWith('ext-') || name.startsWith('lib-'))) {
            return [finding({ cls: 'platform-floor', subject, before: null, after, severity: 'major', file, note: 'new platform requirement' })];
        }
        if (before !== undefined && after === undefined && kind !== 'dev') {
            return [finding({ cls: 'third-party-major', subject, before, after: null, severity: 'info', file, note: 'dependency removed' })];
        }
        return [];
    }
    if (before === after) return [];
    const b = rangeBounds(before);
    const a = rangeBounds(after);
    if (b === null || a === null) {
        return [finding({ cls: kind === 'platform' ? 'platform-floor' : 'third-party-major', subject, before, after, severity: 'info', file, note: 'range not readable as a version range; compare by hand' })];
    }
    const raised = compare(a.floor, b.floor) > 0;
    const crossed = raised && majorKey(a.floor) !== majorKey(b.floor);
    const note = `floor ${format(b.floor)} -> ${format(a.floor)}`;

    if (kind === 'platform') {
        if (raised) return [finding({ cls: 'platform-floor', subject, before, after, severity: 'major', file, note })];
        if (ceilingLowered(b, a)) return [finding({ cls: 'platform-floor', subject, before, after, severity: 'major', file, note: `a runtime version was dropped: ceiling ${format(b.ceiling)} -> ${format(a.ceiling)}` })];
        return [];
    }
    if (kind === 'dev') {
        if (!crossed) return [];
        return [finding({ cls: 'third-party-major', subject: `${subject} (dev)`, before, after, severity: 'major', file, note, waived: DEV_ONLY })];
    }
    if (kind === 'peer') {
        if (crossed) return [finding({ cls: 'peer-floor', subject, before, after, severity: 'major', file, note })];
        if (raised) return [finding({ cls: 'peer-floor', subject, before, after, severity: 'minor', file, note })];
        return [];
    }
    if (!crossed) return [];
    if (ctx.isFirstParty(name)) {
        return [finding({ cls: 'peer-floor', subject, before, after, severity: 'major', file, note: `${note} (first-party sibling floor)` })];
    }
    return [finding({ cls: 'third-party-major', subject, before, after, severity: 'major', file, note })];
}

function declared(ctx, file, ecosystem, parse) {
    const before = parse(ctx.show(ctx.from, file));
    const after = parse(ctx.show(ctx.to, file));
    if (before === undefined || after === undefined) {
        ctx.error(`${file} is not valid JSON at ${before === undefined ? ctx.from : ctx.to}`);
        return [];
    }
    const out = [];
    const bs = sections(ecosystem, before);
    const as = sections(ecosystem, after);
    for (const kind of ['runtime', 'peer', 'platform', 'dev']) {
        const bd = bs.find((s) => s.kind === kind)?.deps ?? {};
        const ad = as.find((s) => s.kind === kind)?.deps ?? {};
        for (const name of new Set([...Object.keys(bd), ...Object.keys(ad)])) {
            out.push(...compareRanges(ctx, { ecosystem, file, kind, name, before: bd[name], after: ad[name] }));
        }
    }
    return out;
}

// ------------------------------------------------------------------ lockfiles

function composerLock(text) {
    const lock = json(text);
    if (!lock) return null;
    const out = new Map();
    for (const p of lock.packages ?? []) out.set(p.name, { version: p.version, dev: false });
    for (const p of lock['packages-dev'] ?? []) out.set(p.name, { version: p.version, dev: true });
    return out;
}

function npmLock(text) {
    const lock = json(text);
    if (!lock) return null;
    const out = new Map();
    if (lock.packages) {
        for (const [path, p] of Object.entries(lock.packages)) {
            if (path === '' || !p.version) continue;
            const name = path.slice(path.lastIndexOf('node_modules/') + 'node_modules/'.length);
            const nested = path.indexOf('node_modules/') !== path.lastIndexOf('node_modules/');
            out.set(path, { name, version: p.version, dev: p.dev === true, transitive: nested });
        }
    } else {
        for (const [name, p] of Object.entries(lock.dependencies ?? {})) out.set(name, { name, version: p.version, dev: p.dev === true });
    }
    return out;
}

/** pnpm-lock.yaml direct dependencies (v6+ importers['.'], or v5 top level). */
export function pnpmLock(text) {
    if (text === null) return null;
    const out = new Map();
    const lines = text.split(/\r?\n/);
    let inImporterRoot = false;
    let sectionDev = null;
    let sectionIndent = -1;
    let current = null;
    const hasImporters = lines.some((l) => /^importers:\s*$/.test(l));
    for (const raw of lines) {
        if (raw.trim() === '' || raw.trim().startsWith('#')) continue;
        const indent = raw.length - raw.trimStart().length;
        const line = raw.trim();
        if (hasImporters) {
            if (indent === 0) {
                inImporterRoot = false;
                sectionDev = null;
                continue;
            }
            if (indent === 2) {
                inImporterRoot = /^['"]?\.['"]?:$/.test(line);
                sectionDev = null;
                continue;
            }
            if (!inImporterRoot) continue;
        } else if (indent === 0) {
            sectionDev = null;
        }
        const sectionMatch = /^(dependencies|devDependencies|optionalDependencies):\s*$/.exec(line);
        if (sectionMatch) {
            sectionDev = sectionMatch[1] === 'devDependencies';
            sectionIndent = indent;
            current = null;
            continue;
        }
        if (sectionDev === null) continue;
        if (indent <= sectionIndent) {
            sectionDev = null;
            continue;
        }
        const entry = /^['"]?([^'":]+(?:\/[^'":]+)?)['"]?:\s*(.*)$/.exec(line);
        if (!entry) continue;
        if (indent === sectionIndent + 2) {
            current = entry[1];
            if (entry[2] !== '') out.set(current, { version: entry[2].replace(/\(.*$/, ''), dev: sectionDev });
            continue;
        }
        if (current !== null && entry[1] === 'version') {
            out.set(current, { version: entry[2].replace(/\(.*$/, '').trim(), dev: sectionDev });
        }
    }
    return out;
}

function lockfile(ctx, file, parse, ecosystem) {
    const before = parse(ctx.show(ctx.from, file));
    const after = parse(ctx.show(ctx.to, file));
    if (before === null || after === null) return [];
    const out = [];
    for (const [key, a] of after) {
        const b = before.get(key);
        if (!b) continue;
        const bv = parseVersion(b.version);
        const av = parseVersion(a.version);
        if (bv === null || av === null || compare(av, bv) <= 0 || majorKey(av) === majorKey(bv)) continue;
        const name = a.name ?? key;
        const subject = `${ecosystem}:${name}${a.transitive ? ` (transitive: ${key})` : ''}`;
        const note = `locked ${b.version} -> ${a.version}`;
        if (a.dev || b.dev) {
            out.push(finding({ cls: 'third-party-major', subject: `${subject} (dev, lockfile)`, before: b.version, after: a.version, severity: 'major', file, note, waived: DEV_ONLY }));
        } else if (ctx.kind === 'app' || ctx.config.bundled.includes(name)) {
            out.push(finding({ cls: 'third-party-major', subject, before: b.version, after: a.version, severity: 'major', file, note: ctx.kind === 'app' ? note : `${note} (bundled into dist)` }));
        } else {
            out.push(finding({ cls: 'third-party-major', subject, before: b.version, after: a.version, severity: 'info', file, note: `${note}; library lockfile, consumers resolve their own` }));
        }
    }
    return out;
}

export function manifests(ctx) {
    const changed = new Set(ctx.changes.map((c) => c.path));
    const out = [];
    if (changed.has('composer.json')) out.push(...declared(ctx, 'composer.json', 'composer', json));
    if (changed.has('package.json')) out.push(...declared(ctx, 'package.json', 'npm', json));
    if (changed.has('pyproject.toml')) out.push(...declared(ctx, 'pyproject.toml', 'pypi', parsePyproject));
    if (changed.has('composer.lock')) out.push(...lockfile(ctx, 'composer.lock', composerLock, 'composer'));
    if (changed.has('package-lock.json')) out.push(...lockfile(ctx, 'package-lock.json', npmLock, 'npm'));
    if (changed.has('pnpm-lock.yaml')) out.push(...lockfile(ctx, 'pnpm-lock.yaml', pnpmLock, 'npm'));
    return out;
}
