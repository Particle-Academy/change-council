// Version ranges, reduced to the two numbers the detector needs: the FLOOR (the
// lowest version a range admits) and the CEILING (the first version it refuses).
// Covers npm, Composer and PEP 440 spellings well enough to compare a range with
// its previous self; anything it cannot read returns null and is reported as
// unreadable, never guessed.

const VERSION = /^v?(\d+)(?:\.(\d+|[x*]))?(?:\.(\d+|[x*]))?/i;

export function parseVersion(text) {
    const m = VERSION.exec(String(text).trim());
    if (!m) return null;
    const num = (p) => (p === undefined || /[x*]/i.test(p) ? null : Number(p));
    return { major: Number(m[1]), minor: num(m[2]), patch: num(m[3]) };
}

const filled = (v) => [v.major, v.minor ?? 0, v.patch ?? 0];

export function compare(a, b) {
    const x = filled(a);
    const y = filled(b);
    for (let i = 0; i < 3; i++) {
        if (x[i] !== y[i]) return x[i] < y[i] ? -1 : 1;
    }
    return 0;
}

/** Semver "major line": 2.x.y -> "2", 0.4.y -> "0.4", 0.0.3 -> "0.0.3". */
export function majorKey(v) {
    if (v.major > 0) return String(v.major);
    if ((v.minor ?? 0) > 0) return `0.${v.minor}`;
    return `0.0.${v.patch ?? 0}`;
}

export const format = (v) => (v === null ? 'none' : v === Infinity ? 'unbounded' : filled(v).join('.'));

function normalise(range) {
    return String(range)
        .replace(/@[a-z]+/gi, '') // composer stability flags
        .replace(/\s*,\s*/g, ' ')
        .replace(/(>=|<=|>|<|=|\^|~=|~|==)\s+/g, '$1')
        .trim();
}

function alternatives(range) {
    return normalise(range).split(/\s*\|\|?\s*/).map((a) => a.trim());
}

function comparators(alternative) {
    const hyphen = /^(\S+)\s+-\s+(\S+)$/.exec(alternative);
    if (hyphen) return [`>=${hyphen[1]}`, `<=${hyphen[2]}`];
    return alternative.split(/\s+/).filter(Boolean);
}

function bump(v, part) {
    if (part === 'major') return { major: v.major + 1, minor: 0, patch: 0 };
    if (part === 'minor') return { major: v.major, minor: (v.minor ?? 0) + 1, patch: 0 };
    return { major: v.major, minor: v.minor ?? 0, patch: (v.patch ?? 0) + 1 };
}

/** [lower, upper) for one comparator; null bounds mean "no bound on that side". */
function bounds(comparator) {
    if (comparator === '' || comparator === '*' || /^[x*]$/i.test(comparator)) return { lower: { major: 0, minor: 0, patch: 0 }, upper: null };
    const m = /^(>=|<=|>|<|==|=|\^|~=|~)?(.*)$/.exec(comparator);
    const op = m[1] ?? '';
    const v = parseVersion(m[2]);
    if (v === null) return undefined;
    switch (op) {
        case '>=':
            return { lower: v, upper: null };
        case '>':
            return { lower: bump(v, 'patch'), upper: null };
        case '<':
            return { lower: null, upper: v };
        case '<=':
            return { lower: null, upper: bump(v, 'patch') };
        case '^': {
            if (v.major > 0 || v.minor === null) return { lower: v, upper: bump(v, 'major') };
            if (v.minor > 0 || v.patch === null) return { lower: v, upper: bump(v, 'minor') };
            return { lower: v, upper: bump(v, 'patch') };
        }
        case '~':
            return { lower: v, upper: v.minor === null ? bump(v, 'major') : bump(v, 'minor') };
        case '~=': // PEP 440 compatible release: ~=3.10 means >=3.10 <4
            return { lower: v, upper: v.patch === null ? bump(v, 'major') : bump(v, 'minor') };
        default: {
            // Bare or "=": wildcards widen, an exact pin is [v, v+patch).
            if (v.minor === null) return { lower: v, upper: bump(v, 'major') };
            if (v.patch === null) return { lower: v, upper: bump(v, 'minor') };
            return { lower: v, upper: bump(v, 'patch') };
        }
    }
}

/** {floor, ceiling} of a whole range, or null when it is not a version range at all. */
export function rangeBounds(range) {
    if (typeof range !== 'string' || /^(file:|link:|workspace:|git|https?:|github:|npm:|dev-)/i.test(range.trim())) return null;
    let floor = null;
    let ceiling = null;
    let read = false;
    for (const alternative of alternatives(range)) {
        let lower = null;
        let upper = null;
        let any = false;
        for (const comparator of comparators(alternative)) {
            const b = bounds(comparator);
            if (b === undefined) continue;
            any = true;
            if (b.lower !== null && (lower === null || compare(b.lower, lower) > 0)) lower = b.lower;
            if (b.upper !== null && (upper === null || compare(b.upper, upper) < 0)) upper = b.upper;
        }
        if (!any && alternative !== '') continue;
        read = true;
        lower ??= { major: 0, minor: 0, patch: 0 };
        if (floor === null || compare(lower, floor) < 0) floor = lower;
        if (upper === null) ceiling = Infinity;
        else if (ceiling !== Infinity && (ceiling === null || compare(upper, ceiling) > 0)) ceiling = upper;
    }
    return read ? { floor, ceiling } : null;
}

export function ceilingLowered(before, after) {
    if (before.ceiling === Infinity) return after.ceiling !== Infinity;
    if (after.ceiling === Infinity) return false;
    return compare(after.ceiling, before.ceiling) < 0;
}
