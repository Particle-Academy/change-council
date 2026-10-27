// Laravel migrations a package publishes. A new migration whose up() drops,
// renames or retypes something is major; an additive one is info. Editing or
// deleting a migration that already shipped is major on its own: a host that
// ran the old file never runs the new one.

import { finding, matchesAny } from '../finding.mjs';

const DESTRUCTIVE = [
    [/->\s*dropColumns?\s*\(/, 'drops a column'],
    [/Schema::\s*drop(IfExists)?\s*\(/, 'drops a table'],
    [/->\s*renameColumn\s*\(/, 'renames a column'],
    [/Schema::\s*rename\s*\(/, 'renames a table'],
    [/->\s*change\s*\(\s*\)/, 'changes a column definition'],
    [/->\s*drop(Timestamps|TimestampsTz|SoftDeletes|SoftDeletesTz|RememberToken|Morphs|NullableMorphs|Primary)\s*\(/, 'drops a column group'],
];

/** The body of `function up(` by brace matching, with its starting line. */
export function upBody(text) {
    const m = /function\s+up\s*\([^)]*\)[^{]*\{/.exec(text);
    if (!m) return null;
    let depth = 1;
    let i = m.index + m[0].length;
    const start = i;
    for (; i < text.length && depth > 0; i++) {
        if (text[i] === '{') depth++;
        else if (text[i] === '}') depth--;
    }
    return { body: text.slice(start, i - 1), line: text.slice(0, start).split('\n').length };
}

export function migrations(ctx) {
    const out = [];
    for (const change of ctx.changes) {
        if (!matchesAny(change.path, ctx.config.migrations) && !matchesAny(change.oldPath, ctx.config.migrations)) continue;
        const subject = `migration ${change.path}`;
        if (change.status === 'D') {
            out.push(finding({ cls: 'schema', subject: `migration ${change.oldPath}`, severity: 'major', file: change.oldPath, note: 'a shipped migration was deleted' }));
            continue;
        }
        if (change.status !== 'A') {
            out.push(finding({ cls: 'schema', subject, severity: 'major', file: change.path, note: 'a shipped migration was edited; hosts that already ran it never see the change' }));
            continue;
        }
        const text = ctx.show(ctx.to, change.path) ?? '';
        const up = upBody(text);
        const hits = [];
        if (up !== null) {
            up.body.split('\n').forEach((l, idx) => {
                for (const [pattern, what] of DESTRUCTIVE) {
                    if (pattern.test(l)) hits.push({ what, line: up.line + idx });
                }
            });
        }
        if (hits.length === 0) {
            out.push(finding({ cls: 'schema', subject, severity: 'info', file: change.path, note: 'additive migration' }));
        } else {
            out.push(finding({ cls: 'schema', subject, severity: 'major', file: change.path, line: hits[0].line, note: [...new Set(hits.map((h) => h.what))].join(', ') }));
        }
    }
    return out;
}
