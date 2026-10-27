// TypeScript public API from built `.d.ts`, without the compiler. Reads the
// ENTRY declaration files (package.json `types` / `exports[*].types`, else every
// .d.ts whose name is not a hashed chunk) and follows `export {..} from` and
// `export *` into chunks, so tsup's hashed chunk names never read as a change.
//
// Major: an exported name removed; a function's minimum required parameter
// count raised. Minor: any other declaration text change (typed-only narrowing,
// per the design, is minor unless it removes a symbol).
//
// dist/ is usually NOT committed. Then pass two built directories:
// --from-dts <dir> --to-dts <dir> (e.g. two `npm pack` outputs, unpacked).

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, posix, relative } from 'node:path';
import { finding, matchesAny } from '../finding.mjs';

const HASHED = /-[A-Za-z0-9_]{8}\.d\.[mc]?ts$/;
const DTS = /\.d\.[mc]?ts$/;

function stripComments(text) {
    let out = '';
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (c === '"' || c === "'" || c === '`') {
            let j = i + 1;
            while (j < text.length && text[j] !== c) j += text[j] === '\\' ? 2 : 1;
            out += text.slice(i, j + 1);
            i = j;
        } else if (c === '/' && text[i + 1] === '*') {
            const end = text.indexOf('*/', i + 2);
            i = end === -1 ? text.length : end + 1;
        } else if (c === '/' && text[i + 1] === '/') {
            const end = text.indexOf('\n', i);
            i = end === -1 ? text.length : end - 1;
        } else {
            out += c;
        }
    }
    return out;
}

function statements(text) {
    const src = stripComments(text);
    const out = [];
    let depth = 0;
    let start = 0;
    const bodied = /^(export\s+)?(declare\s+)?(default\s+)?(abstract\s+)?(interface|class|namespace|module|enum|const\s+enum)\b/;
    for (let i = 0; i < src.length; i++) {
        const c = src[i];
        if (c === '"' || c === "'" || c === '`') {
            let j = i + 1;
            while (j < src.length && src[j] !== c) j += src[j] === '\\' ? 2 : 1;
            i = j;
            continue;
        }
        if (c === '(' || c === '{' || c === '[') depth++;
        else if (c === ')' || c === ']') depth--;
        else if (c === '}') {
            depth--;
            if (depth === 0 && bodied.test(src.slice(start, i).trim())) {
                out.push(src.slice(start, i + 1).trim());
                start = i + 1;
            }
        } else if (c === ';' && depth === 0) {
            out.push(src.slice(start, i).trim());
            start = i + 1;
        }
    }
    const tail = src.slice(start).trim();
    if (tail) out.push(tail);
    return out.filter(Boolean);
}

export function parseDts(text) {
    const decls = new Map();
    const exports = new Map();
    const stars = [];
    for (const s of statements(text)) {
        let m;
        if ((m = /^export\s+(?:type\s+)?\{([\s\S]*)\}\s*(?:from\s+['"]([^'"]+)['"])?$/.exec(s))) {
            for (const spec of m[1].split(',').map((x) => x.trim()).filter(Boolean)) {
                const parts = spec.replace(/^type\s+/, '').split(/\s+as\s+/);
                exports.set((parts[1] ?? parts[0]).trim(), { local: parts[0].trim(), from: m[2] ?? null });
            }
        } else if ((m = /^export\s+\*\s+as\s+([\w$]+)\s+from\s+['"]([^'"]+)['"]$/.exec(s))) {
            exports.set(m[1], { local: '*', from: m[2] });
        } else if ((m = /^export\s+\*\s+from\s+['"]([^'"]+)['"]$/.exec(s))) {
            stars.push(m[1]);
        } else if (/^export\s+default\b/.test(s)) {
            exports.set('default', { local: 'default', from: null });
            decls.set('default', [s]);
        } else if ((m = /^(export\s+)?(?:declare\s+)?(?:abstract\s+)?(?:function|const\s+enum|const|let|var|class|interface|type|enum|namespace|module)\s+([\w$]+)/.exec(s))) {
            const name = m[2];
            decls.set(name, [...(decls.get(name) ?? []), s.replace(/\s+/g, ' ')]);
            if (m[1]) exports.set(name, { local: name, from: null });
        }
    }
    return { decls, exports, stars };
}

function target(fromFile, spec, files) {
    if (!spec.startsWith('.')) return null;
    const base = posix.normalize(posix.join(posix.dirname(fromFile), spec));
    const stem = base.replace(/\.(m|c)?js$/, '');
    for (const candidate of [`${stem}.d.ts`, `${stem}.d.mts`, `${stem}.d.cts`, `${base}.d.ts`, `${base}/index.d.ts`, base]) {
        if (files.has(candidate)) return candidate;
    }
    return null;
}

/** Every exported name of a file, including star re-exports. */
function exportNames(file, files, parsed, seen = new Set()) {
    if (seen.has(file) || !files.has(file)) return new Set();
    seen.add(file);
    const p = parsed(file);
    const names = new Set(p.exports.keys());
    for (const spec of p.stars) {
        const t = target(file, spec, files);
        if (t) for (const n of exportNames(t, files, parsed, seen)) if (n !== 'default') names.add(n);
    }
    return names;
}

/** The declaration texts behind an exported name, following re-exports. */
function resolve(file, name, files, parsed, depth = 0) {
    if (depth > 8 || !files.has(file)) return null;
    const p = parsed(file);
    const e = p.exports.get(name);
    if (e) {
        if (e.from) {
            const t = target(file, e.from, files);
            return t ? resolve(t, e.local, files, parsed, depth + 1) ?? resolveLocal(t, e.local, files, parsed, depth + 1) : null;
        }
        return p.decls.get(e.local) ?? null;
    }
    for (const spec of p.stars) {
        const t = target(file, spec, files);
        const r = t ? resolve(t, name, files, parsed, depth + 1) : null;
        if (r) return r;
    }
    return p.decls.get(name) ?? null;
}

function resolveLocal(file, name, files, parsed, depth) {
    return parsed(file).decls.get(name) ?? null;
}

function paramList(text) {
    const at = text.indexOf('(');
    if (at === -1 || !/^(export\s+)?(declare\s+)?function\b/.test(text)) return null;
    let depth = 0;
    for (let i = at; i < text.length; i++) {
        if (text[i] === '(' || text[i] === '{' || text[i] === '[' || text[i] === '<') depth++;
        else if (text[i] === ')' || text[i] === '}' || text[i] === ']' || (text[i] === '>' && text[i - 1] !== '=')) depth--;
        if (depth === 0) return text.slice(at + 1, i);
    }
    return null;
}

function splitTop(list) {
    const out = [];
    let depth = 0;
    let cur = '';
    for (let i = 0; i < list.length; i++) {
        const c = list[i];
        if ('({[<'.includes(c)) depth++;
        else if (')}]'.includes(c) || (c === '>' && list[i - 1] !== '=')) depth--;
        if (c === ',' && depth === 0) {
            out.push(cur.trim());
            cur = '';
        } else cur += c;
    }
    if (cur.trim()) out.push(cur.trim());
    return out;
}

/** Fewest required arguments across overloads, or null when not a function. */
export function minRequired(texts) {
    let min = null;
    for (const t of texts) {
        const list = paramList(t);
        if (list === null) return null;
        const required = splitTop(list).filter((p) => !p.startsWith('...') && !/^this\s*:/.test(p) && !/^[\w$]+\?\s*:/.test(p) && !p.includes('=')).length;
        min = min === null ? required : Math.min(min, required);
    }
    return min;
}

function entriesFor(files, manifest) {
    const declared = new Set();
    const collect = (v) => {
        if (typeof v === 'string' && DTS.test(v)) declared.add(posix.normalize(v.replace(/^\.\//, '')));
        else if (v && typeof v === 'object') for (const [k, x] of Object.entries(v)) if (k === 'types' || k === 'typings' || typeof x === 'object' || k === 'default' || k === 'import' || k === 'require') collect(x);
    };
    if (manifest) {
        collect(manifest.types);
        collect(manifest.typings);
        collect(manifest.exports);
    }
    const listed = [...declared].filter((f) => files.has(f));
    if (listed.length > 0) return listed;
    return [...files.keys()].filter((f) => !HASHED.test(f));
}

function compareSets(before, after, label, out) {
    const parsedB = memo(before.files);
    const parsedA = memo(after.files);
    for (const entry of before.entries) {
        const namesB = exportNames(entry, before.files, parsedB);
        const namesA = after.files.has(entry) ? exportNames(entry, after.files, parsedA) : new Set();
        for (const name of namesB) {
            const subject = `${label(entry)}#${name}`;
            const file = label(entry).replace(/^dts:/, '');
            if (!namesA.has(name)) {
                out.push(finding({ cls: 'api-removal', subject, severity: 'major', file, note: after.files.has(entry) ? 'export removed' : 'entry declaration file removed' }));
                continue;
            }
            const tb = resolve(entry, name, before.files, parsedB);
            const ta = resolve(entry, name, after.files, parsedA);
            if (tb === null || ta === null || tb.join('\n') === ta.join('\n')) continue;
            const rb = minRequired(tb);
            const ra = minRequired(ta);
            if (rb !== null && ra !== null && ra > rb) {
                out.push(finding({ cls: 'api-removal', subject, before: tb.join(' | '), after: ta.join(' | '), severity: 'major', file, note: `required parameters ${rb} -> ${ra}` }));
            } else {
                out.push(finding({ cls: 'api-removal', subject, before: tb.join(' | '), after: ta.join(' | '), severity: 'minor', file, note: 'declaration changed (typed-only change; minor unless a symbol is removed)' }));
            }
        }
    }
}

function memo(files) {
    const cache = new Map();
    return (file) => {
        if (!cache.has(file)) cache.set(file, parseDts(files.get(file)));
        return cache.get(file);
    };
}

function readDir(dir) {
    const files = new Map();
    const walk = (d) => {
        for (const name of readdirSync(d)) {
            const full = join(d, name);
            if (statSync(full).isDirectory()) walk(full);
            else if (DTS.test(name)) files.set(relative(dir, full).split('\\').join('/'), readFileSync(full, 'utf8'));
        }
    };
    walk(dir);
    return files;
}

export function dts(ctx) {
    const out = [];
    if (ctx.options.fromDts || ctx.options.toDts) {
        if (!ctx.options.fromDts || !ctx.options.toDts) {
            ctx.error('--from-dts and --to-dts must be given together');
            return out;
        }
        const bf = readDir(ctx.options.fromDts);
        const af = readDir(ctx.options.toDts);
        compareSets({ files: bf, entries: entriesFor(bf, null) }, { files: af, entries: entriesFor(af, null) }, (e) => `dts:${e}`, out);
        ctx.coverage('dts', 'ran', `${bf.size} -> ${af.size} files from directories`);
        return out;
    }
    const touched = ctx.changes.some((c) => matchesAny(c.path, ctx.config.dts) || matchesAny(c.oldPath, ctx.config.dts) || c.path === 'package.json');
    const load = (ref) => new Map(ctx.filesAt(ref).filter((f) => DTS.test(f) && matchesAny(f, ctx.config.dts)).map((f) => [f, ctx.show(ref, f) ?? '']));
    if (!touched) {
        ctx.coverage('dts', 'skipped', 'no committed .d.ts changed (pass --from-dts/--to-dts when dist is not committed)');
        return out;
    }
    const bf = load(ctx.from);
    const af = load(ctx.to);
    if (bf.size === 0 && af.size === 0) {
        ctx.coverage('dts', 'skipped', 'no committed .d.ts at either ref (pass --from-dts/--to-dts when dist is not committed)');
        return out;
    }
    const manifest = (ref) => {
        try {
            return JSON.parse(ctx.show(ref, 'package.json') ?? 'null');
        } catch {
            return null;
        }
    };
    compareSets({ files: bf, entries: entriesFor(bf, manifest(ctx.from)) }, { files: af, entries: entriesFor(af, manifest(ctx.to)) }, (e) => e, out);
    ctx.coverage('dts', 'ran', `${bf.size} -> ${af.size} committed files`);
    return out;
}
