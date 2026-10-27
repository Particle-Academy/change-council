// Git access, read-only. Every input the detector compares comes from git
// objects (or two explicit directories for built .d.ts), never from the working
// tree, so a fingerprint is the same on every machine that has the same refs.

import { execFileSync } from 'node:child_process';

export class GitError extends Error {}

// Variables that tell git WHICH repository to use. A git hook exports them
// (pre-push sets GIT_DIR, and others in some setups), and git honours them over
// `cwd`, so a detector run from a release hook would read the hook's repository
// instead of the one it was given. Everything else (PATH, HOME, ssh settings)
// passes through.
const REPO_LOCATING = new Set([
    'GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY',
    'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_COMMON_DIR', 'GIT_PREFIX', 'GIT_NAMESPACE',
]);

export function cleanGitEnv(env = process.env) {
    const out = {};
    for (const [key, value] of Object.entries(env)) {
        if (!REPO_LOCATING.has(key.toUpperCase())) out[key] = value;
    }
    return out;
}

export function git(repo, args, { allowFail = false } = {}) {
    try {
        return execFileSync('git', args, { cwd: repo, env: cleanGitEnv(), encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] });
    } catch (error) {
        if (allowFail) {
            return null;
        }
        const stderr = String(error.stderr ?? '').trim();
        throw new GitError(`git ${args.join(' ')} failed: ${stderr || error.message}`);
    }
}

export function resolveRef(repo, ref) {
    const sha = git(repo, ['rev-parse', '--verify', '--quiet', `${ref}^{commit}`], { allowFail: true });
    if (sha === null || sha.trim() === '') {
        throw new GitError(`unknown ref "${ref}"`);
    }
    return sha.trim();
}

/** The newest v* tag reachable from the parent of `to`, or null. */
export function previousTag(repo, to) {
    const out = git(repo, ['describe', '--tags', '--abbrev=0', '--match', 'v*', `${to}^`], { allowFail: true });
    return out === null ? null : out.trim() || null;
}

/** File content at a ref, or null when the path does not exist there. */
export function show(repo, ref, path) {
    return git(repo, ['show', `${ref}:${path}`], { allowFail: true });
}

/** Every file at a ref. */
export function files(repo, ref) {
    return git(repo, ['ls-tree', '-r', '--name-only', ref]).split('\n').filter(Boolean);
}

/** Changed paths between two refs, with rename detection: [{status, path, oldPath}]. */
export function changes(repo, from, to) {
    const out = git(repo, ['diff', '--name-status', '-M', '--no-color', from, to]);
    const result = [];
    for (const line of out.split('\n')) {
        if (line.trim() === '') continue;
        const parts = line.split('\t');
        const status = parts[0][0];
        if (status === 'R' || status === 'C') {
            result.push({ status, oldPath: parts[1], path: parts[2] });
        } else {
            result.push({ status, oldPath: parts[1], path: parts[1] });
        }
    }
    return result;
}

/** The annotated tag message of `ref` if it is an annotated tag, else null. */
export function tagMessage(repo, ref) {
    const type = git(repo, ['cat-file', '-t', ref], { allowFail: true });
    if (type === null || type.trim() !== 'tag') {
        return null;
    }
    return git(repo, ['tag', '-l', '--format=%(contents)', ref], { allowFail: true });
}

/** Full commit messages in from..to. */
export function commitMessages(repo, from, to) {
    const out = git(repo, ['log', '--format=%B%x00', `${from}..${to}`], { allowFail: true }) ?? '';
    return out.split('\0').map((m) => m.trim()).filter(Boolean);
}
