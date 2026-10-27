// Declared breaking changes outside the CHANGELOG: Prism's release-notes
// "BREAKING CHANGE:" line (from --release-notes, or the annotated tag message of
// --to), and conventional-commit `BREAKING CHANGE:` footers in from..to.
// "No breaking changes." is recorded on the report, never flagged.

import { finding } from '../finding.mjs';

const LINE = /^\s*BREAKING[ -]CHANGE:\s*(.*)$/gm;

export function scan(text, source) {
    const out = [];
    if (!text) return out;
    for (const m of text.matchAll(LINE)) {
        const what = m[1].trim() || '(no description)';
        out.push(finding({ cls: 'declared-breaking', subject: `${source}: ${what}`, severity: 'major', note: `declared in ${source}` }));
    }
    return out;
}

export function notes(ctx) {
    const out = [];
    const sources = [];
    if (ctx.releaseNotes !== null) sources.push(['release-notes', ctx.releaseNotes]);
    const tag = ctx.tagMessage(ctx.to);
    if (tag) sources.push(['release-notes', tag]);
    for (const [source, text] of sources) {
        out.push(...scan(text, source));
        if (/^\s*No breaking changes\.?\s*$/im.test(text)) ctx.declare('no-breaking-changes');
    }
    for (const message of ctx.commitMessages()) out.push(...scan(message, 'commit'));
    const seen = new Set();
    return out.filter((f) => !seen.has(f.fingerprint) && seen.add(f.fingerprint));
}
