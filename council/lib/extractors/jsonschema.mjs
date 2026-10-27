// JSON Schema structural diff, our own code, no library. Breaking for a
// consumer: a property removed, a new `required`, an enum value removed, a type
// narrowed, `additionalProperties` tightened to false, a const changed, a bound
// tightened, a schema file deleted. Additions are info. A new `vN+1` file beside
// the old one is major but deprecation-shaped (the old version is on its way out).

import { finding, matchesAny } from '../finding.mjs';

const pointer = (path) => (path === '' ? '#' : `#${path}`);
const types = (s) => (s.type === undefined ? null : new Set([].concat(s.type)));
const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

function diff(before, after, path, emit) {
    if (!isObj(before) || !isObj(after)) return;

    const bt = types(before);
    const at = types(after);
    if (bt !== null && at !== null) {
        for (const t of bt) if (!at.has(t)) emit(`${path}/type/${t}`, 'major', 'type narrowed', t, [...at].join('|'));
    } else if (bt === null && at !== null) {
        emit(`${path}/type`, 'major', 'type constrained', null, [...at].join('|'));
    }

    if (Array.isArray(before.enum) && Array.isArray(after.enum)) {
        const keep = new Set(after.enum.map((v) => JSON.stringify(v)));
        for (const v of before.enum) {
            if (!keep.has(JSON.stringify(v))) emit(`${path}/enum/${typeof v === 'string' ? v : JSON.stringify(v)}`, 'major', 'enum value removed', JSON.stringify(v), null);
        }
        const had = new Set(before.enum.map((v) => JSON.stringify(v)));
        for (const v of after.enum) if (!had.has(JSON.stringify(v))) emit(`${path}/enum/${typeof v === 'string' ? v : JSON.stringify(v)}`, 'info', 'enum value added', null, JSON.stringify(v));
    } else if (!Array.isArray(before.enum) && Array.isArray(after.enum)) {
        emit(`${path}/enum`, 'major', 'enum introduced', null, JSON.stringify(after.enum));
    }

    if ('const' in before || 'const' in after) {
        if (JSON.stringify(before.const) !== JSON.stringify(after.const)) emit(`${path}/const`, 'major', 'const changed', JSON.stringify(before.const), JSON.stringify(after.const));
    }

    const breq = new Set(before.required ?? []);
    for (const r of after.required ?? []) if (!breq.has(r)) emit(`${path}/required/${r}`, 'major', 'newly required', null, r);

    if (before.additionalProperties !== false && after.additionalProperties === false) {
        emit(`${path}/additionalProperties`, 'major', 'additionalProperties tightened to false', JSON.stringify(before.additionalProperties ?? true), 'false');
    } else if (isObj(before.additionalProperties) && isObj(after.additionalProperties)) {
        diff(before.additionalProperties, after.additionalProperties, `${path}/additionalProperties`, emit);
    }

    for (const [key, tighter] of [['minLength', 1], ['minimum', 1], ['minItems', 1], ['exclusiveMinimum', 1], ['maxLength', -1], ['maximum', -1], ['maxItems', -1], ['exclusiveMaximum', -1]]) {
        const b = before[key];
        const a = after[key];
        if (a === undefined || b === a) continue;
        if (b === undefined || Math.sign(a - b) === tighter) emit(`${path}/${key}`, 'major', `${key} tightened`, b ?? null, a);
    }
    if (after.pattern !== undefined && before.pattern !== after.pattern) emit(`${path}/pattern`, 'major', 'pattern changed', before.pattern ?? null, after.pattern);

    for (const key of ['properties', '$defs', 'definitions', 'patternProperties']) {
        const bp = isObj(before[key]) ? before[key] : {};
        const ap = isObj(after[key]) ? after[key] : {};
        for (const name of Object.keys(bp)) {
            if (!(name in ap)) emit(`${path}/${key}/${name}`, 'major', `${key === 'properties' ? 'property' : 'definition'} removed`, name, null);
            else diff(bp[name], ap[name], `${path}/${key}/${name}`, emit);
        }
        for (const name of Object.keys(ap)) if (!(name in bp)) emit(`${path}/${key}/${name}`, 'info', `${key === 'properties' ? 'property' : 'definition'} added`, null, name);
    }

    if (isObj(before.items) && isObj(after.items)) diff(before.items, after.items, `${path}/items`, emit);

    for (const key of ['oneOf', 'anyOf']) {
        if (Array.isArray(before[key]) && Array.isArray(after[key])) {
            if (after[key].length < before[key].length) emit(`${path}/${key}`, 'major', `${key} lost alternatives`, before[key].length, after[key].length);
            before[key].forEach((b, i) => after[key][i] !== undefined && diff(b, after[key][i], `${path}/${key}/${i}`, emit));
        }
    }
}

export function diffSchemas(before, after) {
    const out = [];
    diff(before, after, '', (path, severity, note, b, a) => out.push({ path: pointer(path), severity, note, before: b, after: a }));
    return out;
}

const version = (path) => /\.v(\d+)\.json$/.exec(path);

export function jsonschema(ctx) {
    const out = [];
    for (const change of ctx.changes) {
        const inScope = (p) => p && matchesAny(p, ctx.config.schemas) && !ctx.isSurface(p);
        if (!inScope(change.path) && !inScope(change.oldPath)) continue;
        if (change.status === 'D') {
            out.push(finding({ cls: 'schema', subject: `${change.oldPath}#`, severity: 'major', file: change.oldPath, note: 'schema file removed' }));
            continue;
        }
        const afterText = ctx.show(ctx.to, change.path);
        let after;
        try {
            after = JSON.parse(afterText);
        } catch {
            ctx.error(`${change.path} is not valid JSON at ${ctx.to}`);
            continue;
        }
        if (change.status === 'A') {
            const v = version(change.path);
            const older = v ? ctx.filesAt(ctx.to).filter((p) => p !== change.path && p.replace(/\.v\d+\.json$/, '') === change.path.replace(/\.v\d+\.json$/, '') && Number(version(p)?.[1]) < Number(v[1])) : [];
            if (older.length > 0) {
                out.push(finding({ cls: 'schema', subject: `${change.path}#`, severity: 'major', file: change.path, after: `v${v[1]}`, note: `new schema version beside ${older.map((p) => `v${version(p)[1]}`).join(', ')}: deprecation-shaped, the old version is on its way out` }));
            } else {
                out.push(finding({ cls: 'schema', subject: `${change.path}#`, severity: 'info', file: change.path, note: 'new schema' }));
            }
            continue;
        }
        let before;
        try {
            before = JSON.parse(ctx.show(ctx.from, change.oldPath));
        } catch {
            continue;
        }
        for (const d of diffSchemas(before, after)) {
            out.push(finding({ cls: 'schema', subject: `${change.path}${d.path}`, before: d.before, after: d.after, severity: d.severity, file: change.path, note: d.note }));
        }
    }
    return out;
}
