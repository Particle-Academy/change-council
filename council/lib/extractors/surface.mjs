// Committed public-surface snapshots: the generic mechanism. A repo commits
// `.council/surface/*.json` (MCP tools, config keys, artisan signatures, ACP
// meta keys, a support matrix, a pricing table, corpus expectations), generated
// by its own suite with a test that fails when stale. The detector diffs them
// without knowing what they describe, which is how Prism's classes plug in.
//
// Shape (both accepted):
//   {"schema":"change-council/surface.v1","class":"contract","changed":"major","items":{...}}
//   a bare object (keys are items) or a bare array of strings (each is an item)
//
// An item removed is major. An item added is info. An item whose value changed
// takes the snapshot's `changed` severity (default major), so a pricing table
// can declare price movement info-only while a support matrix stays major.

import { finding, matchesAny } from '../finding.mjs';

function items(doc) {
    if (Array.isArray(doc)) return Object.fromEntries(doc.map((v) => [typeof v === 'string' ? v : JSON.stringify(v), true]));
    if (doc && typeof doc === 'object') return doc.schema === 'change-council/surface.v1' ? doc.items ?? {} : doc;
    return {};
}

/** Canonical JSON for value comparison: object key order never counts as a change. */
function canonical(value) {
    if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
    if (value && typeof value === 'object') return `{${Object.keys(value).sort().map((k) => `${JSON.stringify(k)}:${canonical(value[k])}`).join(',')}}`;
    return JSON.stringify(value);
}

const short = (v) => {
    const s = canonical(v);
    return s.length > 300 ? `${s.slice(0, 297)}...` : s;
};

export function surface(ctx) {
    const out = [];
    for (const change of ctx.changes) {
        const rule = ctx.surfaceRule(change.path) ?? ctx.surfaceRule(change.oldPath);
        if (!rule) continue;
        const parse = (text) => {
            if (text === null) return null;
            try {
                return JSON.parse(text);
            } catch {
                ctx.error(`${change.path} is not valid JSON`);
                return undefined;
            }
        };
        const before = change.status === 'A' ? null : parse(ctx.show(ctx.from, change.oldPath));
        const after = change.status === 'D' ? null : parse(ctx.show(ctx.to, change.path));
        if (before === undefined || after === undefined) continue;
        const doc = after ?? before;
        const cls = (doc && !Array.isArray(doc) && doc.schema === 'change-council/surface.v1' && doc.class) || rule.class || 'contract';
        const changed = (doc && !Array.isArray(doc) && doc.changed) || rule.changed || 'major';
        if (after === null) {
            out.push(finding({ cls, subject: `${change.oldPath}#`, severity: 'major', file: change.oldPath, note: 'surface snapshot removed' }));
            continue;
        }
        if (before === null) {
            out.push(finding({ cls, subject: `${change.path}#`, severity: 'info', file: change.path, note: 'new surface snapshot' }));
            continue;
        }
        const b = items(before);
        const a = items(after);
        for (const key of Object.keys(b)) {
            if (!(key in a)) out.push(finding({ cls, subject: `${change.path}#${key}`, before: short(b[key]), severity: 'major', file: change.path, note: 'removed from the public surface' }));
            else if (canonical(b[key]) !== canonical(a[key])) out.push(finding({ cls, subject: `${change.path}#${key}`, before: short(b[key]), after: short(a[key]), severity: changed, file: change.path, note: 'changed' }));
        }
        for (const key of Object.keys(a)) {
            if (!(key in b)) out.push(finding({ cls, subject: `${change.path}#${key}`, after: short(a[key]), severity: 'info', file: change.path, note: 'added to the public surface' }));
        }
    }
    return out;
}

export function surfaceRuleFor(config) {
    return (path) => {
        if (!path) return null;
        for (const rule of config.surfaces) if (matchesAny(path, [rule.path])) return rule;
        return null;
    };
}
