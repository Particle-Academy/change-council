// A NEW `@deprecated` tag in source. The subject is the declaration the tag
// belongs to (the first code line after it), so an unchanged deprecation that
// merely moved lines does not fire again.

import { finding } from '../finding.mjs';

const SOURCE = /\.(php|ts|tsx|mts|cts|js|jsx|mjs|cjs|py)$/;

/** [{subject, line}] for every @deprecated in a file. */
export function tags(text) {
    if (text === null) return [];
    const lines = text.split(/\r?\n/);
    const out = [];
    lines.forEach((line, index) => {
        if (!/@deprecated\b/.test(line)) return;
        let declaration = '';
        for (let j = index + 1; j < lines.length; j++) {
            const t = lines[j].trim();
            if (t === '' || t.startsWith('*') || t.startsWith('/*') || t.startsWith('//') || t.startsWith('#') || t.startsWith('"""')) continue;
            declaration = t.replace(/\s*\{\s*$/, '').slice(0, 160);
            break;
        }
        out.push({ subject: declaration || `line ${index + 1}`, line: index + 1 });
    });
    return out;
}

export function deprecated(ctx) {
    const out = [];
    for (const change of ctx.changes) {
        // dist/ is built from source: counting both would report one tag twice.
        if (change.status === 'D' || !SOURCE.test(change.path) || /(^|\/)dist\//.test(change.path)) continue;
        const before = new Set(tags(ctx.show(ctx.from, change.oldPath)).map((t) => t.subject));
        for (const tag of tags(ctx.show(ctx.to, change.path))) {
            if (before.has(tag.subject)) continue;
            out.push(finding({ cls: 'deprecation', subject: `${change.path}: ${tag.subject}`, severity: 'major', file: change.path, line: tag.line, note: 'new @deprecated' }));
        }
    }
    return out;
}
