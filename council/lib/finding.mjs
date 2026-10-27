import { createHash } from 'node:crypto';

export const SEVERITIES = ['major', 'minor', 'info'];

/**
 * One finding. `fingerprint = sha256(class|subject|before|after)`: it comes only
 * from git content, so the same change fingerprints the same on every machine,
 * and it is what an approved Change "covers".
 */
export function finding({ cls, subject, before = null, after = null, severity, file = null, line = null, note = null, waived = null }) {
    if (!SEVERITIES.includes(severity)) throw new Error(`bad severity ${severity}`);
    const b = before === null ? '' : String(before);
    const a = after === null ? '' : String(after);
    return {
        class: cls,
        subject,
        before: before === null ? null : String(before),
        after: after === null ? null : String(after),
        severity,
        file,
        line,
        note,
        waived,
        fingerprint: createHash('sha256').update(`${cls}|${subject}|${b}|${a}`).digest('hex'),
    };
}

/** Minimal glob: `**` spans directories, `*` and `?` do not. Case-sensitive. */
export function globToRegExp(glob) {
    let out = '';
    for (let i = 0; i < glob.length; i++) {
        const c = glob[i];
        if (c === '*') {
            if (glob[i + 1] === '*') {
                const slash = glob[i + 2] === '/';
                out += slash ? '(?:.*/)?' : '.*';
                i += slash ? 2 : 1;
            } else {
                out += '[^/]*';
            }
        } else if (c === '?') {
            out += '[^/]';
        } else {
            out += c.replace(/[.+^${}()|[\]\\]/g, '\\$&');
        }
    }
    return new RegExp(`^${out}$`);
}

export function matchesAny(path, globs) {
    return globs.some((g) => globToRegExp(g).test(path));
}
