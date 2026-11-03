// Git access, read-only. Every input the detector compares comes from git
// objects (or two explicit directories for built .d.ts), never from the working
// tree, so a fingerprint is the same on every machine that has the same refs.

import { execFileSync } from 'node:child_process';
import { accessSync, constants, statSync } from 'node:fs';

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

// Which file a bare program name runs must not depend on the working directory.
// On Windows a spawn with `cwd` searches that directory ahead of PATH, and reading
// only git objects does not help, because the executable sits in the tree. So walk
// PATH here, absolute entries only, and spawn the absolute path it returns.
//
// Kept identical to `executable.ts` in fancy-git-js and `ExecutableResolver` in
// fancy-git-php; all three read the same `executable-lookup-cases.json`. It lives
// in this file so the detector's list of files to pin does not grow.

// What CreateProcess can start without a shell.
const RUNNABLE_EXTENSIONS = new Set(['.com', '.exe']);
// cmd.exe re-parses a batch file's arguments, so one is never started.
const BATCH_EXTENSIONS = new Set(['.bat', '.cmd']);
const DEFAULT_PATHEXT = '.COM;.EXE;.BAT;.CMD';

export function isAbsoluteEntry(entry, platform) {
    if (platform !== 'win32') return entry.startsWith('/');
    // Not path.win32.isAbsolute: it accepts "\foo", which is relative to the
    // current drive and so still depends on the working directory.
    return /^[A-Za-z]:[\\/]/.test(entry) || /^[\\/]{2}[^\\/]+[\\/][^\\/]+/.test(entry);
}

function readEnv(env, key, platform) {
    if (platform !== 'win32') return env[key];
    for (const [name, value] of Object.entries(env)) {
        if (name.toUpperCase() === key) return value;
    }
    return undefined;
}

function isRunnableFile(path, platform) {
    try {
        if (!statSync(path).isFile()) return false;
        if (platform !== 'win32') accessSync(path, constants.X_OK);
        return true;
    } catch {
        return false;
    }
}

/** { path, batch }: the file to run or null, and the first batch file seen (never returned as path). */
export function findExecutable(name, { platform = process.platform, env = process.env, isFile = (path) => isRunnableFile(path, platform) } = {}) {
    const windows = platform === 'win32';
    const separator = windows ? '\\' : '/';

    const hasExtension = /\.(?:com|exe|bat|cmd)$/i.test(name);
    const extensions = !windows || hasExtension
        ? ['']
        : (readEnv(env, 'PATHEXT', platform) || DEFAULT_PATHEXT).split(';').filter(Boolean);

    let batch = null;
    for (const raw of (readEnv(env, 'PATH', platform) ?? '').split(windows ? ';' : ':')) {
        const entry = windows ? raw.replace(/^"(.*)"$/, '$1') : raw;
        if (!isAbsoluteEntry(entry, platform)) continue;
        const directory = entry.replace(windows ? /[\\/]+$/ : /\/+$/, '');
        for (const extension of extensions) {
            const candidate = `${directory}${separator}${name}${extension}`;
            if (!isFile(candidate)) continue;
            if (!windows) return { path: candidate, batch };
            const kind = /\.[^.\\/]*$/.exec(candidate)?.[0].toLowerCase() ?? '';
            if (RUNNABLE_EXTENSIONS.has(kind)) return { path: candidate, batch };
            if (BATCH_EXTENSIONS.has(kind)) batch ??= candidate;
        }
    }
    return { path: null, batch };
}

const resolved = new Map();

/**
 * The absolute path to spawn for `name`, cached for the life of the process. An
 * absolute `name` is returned as given. A failure throws and is not cached.
 */
export function resolveExecutable(name) {
    if (isAbsoluteEntry(name, process.platform)) return name;
    const cached = resolved.get(name);
    if (cached !== undefined) return cached;
    const { path, batch } = findExecutable(name);
    if (path !== null) {
        resolved.set(name, path);
        return path;
    }
    if (batch !== null) {
        throw new GitError(`Found "${batch}" on PATH, but a batch file cannot be run without a shell. Add a real ${name} executable to PATH.`);
    }
    throw new GitError(`Could not find the "${name}" executable. Only absolute PATH entries are searched; the current directory is never used. Add ${name} to PATH.`);
}

export function git(repo, args, { allowFail = false } = {}) {
    // Outside the try: `allowFail` means "this git command may fail", not "git may
    // be missing", which would otherwise read as an unknown ref.
    const executable = resolveExecutable('git');
    try {
        return execFileSync(executable, args, { cwd: repo, env: cleanGitEnv(), encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true });
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
