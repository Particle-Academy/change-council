// CHANGELOG.md: `### Deprecated` / `### Removed` bullets and `BREAKING` markers
// in entries that are NEW between the two refs (a version heading that did not
// exist before, or lines added under [Unreleased]). The preamble above the first
// `## ` heading is never read, because it is where the marker is explained.

import { finding } from '../finding.mjs';

const FILES = ['CHANGELOG.md', 'changelog.md', 'CHANGES.md'];

/** [{key, lines: [{text, line}]}] for every `## ` entry. */
export function entries(text) {
    if (text === null) return [];
    const out = [];
    let current = null;
    text.split(/\r?\n/).forEach((raw, index) => {
        const heading = /^##\s+(.*)$/.exec(raw);
        if (heading && !raw.startsWith('###')) {
            const key = heading[1].replace(/\s+[-–—]\s+.*$/, '').replace(/[[\]]/g, '').trim().toLowerCase();
            current = { key, lines: [] };
            out.push(current);
            return;
        }
        if (current !== null) current.lines.push({ text: raw, line: index + 1 });
    });
    return out;
}

const bullet = (text) => text.replace(/^\s*[-*+]\s+/, '').replace(/\*\*/g, '').trim();

export function changelog(ctx) {
    const out = [];
    for (const file of FILES) {
        if (!ctx.changes.some((c) => c.path === file)) continue;
        const before = entries(ctx.show(ctx.from, file));
        const after = entries(ctx.show(ctx.to, file));
        const beforeLines = new Map(before.map((e) => [e.key, new Set(e.lines.map((l) => l.text.trim()))]));
        for (const entry of after) {
            const old = beforeLines.get(entry.key);
            let section = null;
            for (const { text, line } of entry.lines) {
                const sub = /^###\s+(.*)$/.exec(text);
                if (sub) {
                    section = sub[1].trim().toLowerCase();
                    continue;
                }
                const trimmed = text.trim();
                if (trimmed === '' || (old && old.has(trimmed))) continue;
                const isBullet = /^\s*[-*+]\s+/.test(text);
                if (isBullet && (section === 'deprecated' || section === 'removed')) {
                    out.push(finding({ cls: 'deprecation', subject: `changelog ${section}: ${bullet(text)}`, severity: 'major', file, line, note: `${file} ### ${section === 'deprecated' ? 'Deprecated' : 'Removed'}` }));
                }
                if (/\bBREAKING\b/.test(text)) {
                    out.push(finding({ cls: 'declared-breaking', subject: `changelog: ${bullet(text)}`, severity: 'major', file, line, note: 'BREAKING marker' }));
                }
            }
        }
    }
    return out;
}
