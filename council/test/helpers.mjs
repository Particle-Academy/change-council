// Fixture repos for the detector suite: a real git repository per test, built
// in a temp directory, with two tagged states. Every class gets a pair where the
// finding MUST fire, and every waiver gets a pair where it must NOT count.

import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { cleanGitEnv } from '../lib/git.mjs';

export const CHECK = join(dirname(fileURLToPath(import.meta.url)), '..', 'check.mjs');

export function makeRepo(t) {
    const dir = mkdtempSync(join(tmpdir(), 'council-fixture-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    // cleanGitEnv: fixtures must be built in THIS directory even when the suite
    // runs inside a git hook that exports GIT_DIR (the release preflight does).
    const git = (...args) => execFileSync('git', args, { cwd: dir, env: cleanGitEnv(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    git('init', '-q', '-b', 'main');
    git('config', 'user.email', 'fixture@example.com');
    git('config', 'user.name', 'Fixture');
    git('config', 'commit.gpgsign', 'false');
    git('config', 'tag.gpgsign', 'false');
    git('config', 'core.autocrlf', 'false');

    const repo = {
        dir,
        git,
        write(files) {
            for (const [path, content] of Object.entries(files)) {
                const full = join(dir, path);
                mkdirSync(dirname(full), { recursive: true });
                writeFileSync(full, typeof content === 'string' ? content : JSON.stringify(content, null, 2) + '\n');
            }
            return repo;
        },
        remove(...paths) {
            for (const path of paths) {
                rmSync(join(dir, path), { force: true, recursive: true });
            }
            return repo;
        },
        commit(message = 'change', tag = null, tagMessage = null) {
            git('add', '-A');
            git('commit', '-q', '--allow-empty', '-m', message);
            if (tag !== null) {
                if (tagMessage === null) {
                    git('tag', tag);
                } else {
                    git('tag', '-a', tag, '-m', tagMessage);
                }
            }
            return repo;
        },
    };

    return repo;
}

/** Two states: `before` tagged v1.0.0, `after` committed on top and tagged v1.1.0. */
export function pair(t, before, after, { tagMessage = null, removeAfter = [] } = {}) {
    const repo = makeRepo(t);
    repo.write(before).commit('before', 'v1.0.0');
    repo.remove(...removeAfter).write(after).commit('after', 'v1.1.0', tagMessage);
    return repo;
}

export function runCli(args, cwd) {
    const result = spawnSync(process.execPath, [CHECK, ...args], { cwd, encoding: 'utf8' });
    return { code: result.status, stdout: result.stdout, stderr: result.stderr };
}

/** The findings of one class that COUNT (not waived). */
export function counted(report, cls, severity = 'major') {
    return report.findings.filter((f) => f.class === cls && f.severity === severity && !f.waived);
}

export function waived(report, kind) {
    return report.findings.filter((f) => f.waived && f.waived.kind === kind);
}
