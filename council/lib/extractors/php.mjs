// PHP public API: both trees' CHANGED files under the autoload paths, run
// through `php council/php/surface.php` (PHP's built-in tokenizer, nothing
// executed). A class can only lose members in a file that changed, so unchanged
// files never need reading. When php is not on PATH the extractor says so in the
// report's coverage; it never reports "no PHP changes" for a comparison it did
// not make.

import { spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { finding, matchesAny } from '../finding.mjs';

const SCRIPT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'php', 'surface.php');
const INTERNAL = { kind: 'internal', reason: 'marked @internal: not part of the public API' };

export function phpPaths(composerText) {
    try {
        const c = JSON.parse(composerText ?? 'null');
        const dirs = [];
        for (const key of ['psr-4', 'psr-0']) for (const v of Object.values(c?.autoload?.[key] ?? {})) dirs.push(...[].concat(v));
        dirs.push(...(c?.autoload?.classmap ?? []));
        const clean = dirs.map((d) => String(d).replace(/^\.\//, '').replace(/\/?$/, '/')).filter((d) => d !== '/');
        return clean.length > 0 ? clean : ['src/'];
    } catch {
        return ['src/'];
    }
}

function run(files) {
    const result = spawnSync(process.env.COUNCIL_PHP ?? 'php', [SCRIPT], { input: JSON.stringify({ files }), encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
    if (result.error) return { error: result.error.code === 'ENOENT' ? 'php not on PATH' : result.error.message };
    if (result.status !== 0) return { error: `surface.php exited ${result.status}: ${result.stderr.trim()}` };
    try {
        return { classes: JSON.parse(result.stdout).classes };
    } catch {
        return { error: 'surface.php printed something that is not JSON' };
    }
}

const sig = (m) => `${m.static ? 'static ' : ''}${m.name}(${m.params.map((p) => `${p.type ? `${p.type} ` : ''}${p.byRef ? '&' : ''}${p.variadic ? '...' : ''}$${p.name}${p.optional && !p.variadic ? ' = ?' : ''}`).join(', ')})${m.returns ? `: ${m.returns}` : ''}`;
const required = (m) => m.params.filter((p) => !p.optional).length;

export function compareClasses(before, after) {
    const out = [];
    const emit = (cls, subject, severity, note, b, a, c) => out.push(finding({ cls: 'api-removal', subject, before: b, after: a, severity, file: c.file, line: c.line, note, waived: c.internal ? INTERNAL : null }));
    for (const [fqcn, b] of Object.entries(before)) {
        const a = after[fqcn];
        if (!a) {
            emit('api-removal', fqcn, 'major', `${b.kind} removed`, b.kind, null, b);
            continue;
        }
        const ctxA = { ...a, internal: a.internal || b.internal };
        if (!b.final && a.final) emit('api-removal', fqcn, 'major', 'class made final: subclasses break', 'extensible', 'final', ctxA);
        if (b.kind !== a.kind) emit('api-removal', fqcn, 'major', `${b.kind} became ${a.kind}`, b.kind, a.kind, ctxA);
        for (const [key, bm] of Object.entries(b.methods)) {
            const am = a.methods[key];
            const subject = `${fqcn}::${bm.name}`;
            const where = { ...ctxA, line: (am ?? bm).line, internal: ctxA.internal || bm.internal };
            if (!am) {
                emit('api-removal', subject, 'major', 'public method removed or no longer public', sig(bm), null, where);
                continue;
            }
            if (bm.static !== am.static) emit('api-removal', subject, 'major', 'static-ness changed', sig(bm), sig(am), where);
            if (required(am) > required(bm)) {
                emit('api-removal', subject, 'major', `required parameters ${required(bm)} -> ${required(am)}`, sig(bm), sig(am), where);
                continue;
            }
            const typeChanged = bm.params.some((p, i) => am.params[i] && (p.type ?? '') !== (am.params[i].type ?? ''));
            const refChanged = bm.params.some((p, i) => am.params[i] && p.byRef !== am.params[i].byRef);
            if (typeChanged || refChanged) {
                emit('api-removal', subject, 'major', typeChanged ? 'parameter type changed' : 'by-reference changed', sig(bm), sig(am), where);
            } else if (!bm.final && am.final) {
                emit('api-removal', subject, 'major', 'method made final', sig(bm), sig(am), where);
            } else if (sig(bm) !== sig(am)) {
                emit('api-removal', subject, 'minor', 'signature changed (renamed/removed optional parameter or return type)', sig(bm), sig(am), where);
            }
        }
        for (const [key, am] of Object.entries(a.methods)) {
            if (b.methods[key]) continue;
            if (a.kind === 'interface' || (am.abstract && a.abstract)) {
                emit('api-removal', `${fqcn}::${am.name}`, 'major', `${a.kind === 'interface' ? 'interface' : 'abstract'} method added: implementers break`, null, sig(am), { ...ctxA, line: am.line });
            }
        }
        for (const [list, label] of [['constants', 'constant'], ['properties', 'property'], ['cases', 'enum case']]) {
            for (const name of b[list]) {
                if (!a[list].includes(name)) emit('api-removal', `${fqcn}::${list === 'properties' ? '$' : ''}${name}`, 'major', `public ${label} removed`, name, null, ctxA);
            }
        }
    }
    return out;
}

export function php(ctx) {
    const dirs = phpPaths(ctx.show(ctx.to, 'composer.json') ?? ctx.show(ctx.from, 'composer.json'));
    const inScope = (p) => p && p.endsWith('.php') && dirs.some((d) => p.startsWith(d)) && !matchesAny(p, ctx.config.testPaths);
    const touched = ctx.changes.filter((c) => inScope(c.path) || inScope(c.oldPath));
    if (touched.length === 0) {
        ctx.coverage('php', 'skipped', 'no PHP file under the autoload paths changed');
        return [];
    }
    const beforeFiles = {};
    const afterFiles = {};
    for (const c of touched) {
        if (c.status !== 'A' && inScope(c.oldPath)) beforeFiles[c.oldPath] = ctx.show(ctx.from, c.oldPath) ?? '';
        if (c.status !== 'D' && inScope(c.path)) afterFiles[c.path] = ctx.show(ctx.to, c.path) ?? '';
    }
    const b = run(beforeFiles);
    const a = b.error ? b : run(afterFiles);
    if (b.error || a.error) {
        ctx.coverage('php', 'skipped', (b.error ?? a.error) + `; ${touched.length} changed PHP file(s) NOT compared`);
        return [];
    }
    ctx.coverage('php', 'ran', `${touched.length} changed file(s) under ${dirs.join(', ')}`);
    return compareClasses(b.classes, a.classes);
}
