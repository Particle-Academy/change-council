// The detector reads the repository it was GIVEN, even when git's own
// environment points somewhere else. A git hook (pre-push, which is where a
// release runs this) exports GIT_DIR and friends; git honours them over `cwd`,
// so without this every read went to the hook's repository instead. Found when
// the release preflight passed and the same suite failed inside the pre-push
// hook (48 tests, "core.bare and core.worktree do not make sense").

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { realpathSync } from 'node:fs';

import { git, cleanGitEnv } from '../lib/git.mjs';
import { makeRepo } from './helpers.mjs';

function withEnv(vars, fn) {
    const saved = {};
    for (const [key, value] of Object.entries(vars)) {
        saved[key] = process.env[key];
        process.env[key] = value;
    }
    try {
        return fn();
    } finally {
        for (const [key, value] of Object.entries(saved)) {
            if (value === undefined) delete process.env[key];
            else process.env[key] = value;
        }
    }
}

test('git() reads the given repo even when GIT_DIR and GIT_WORK_TREE point at another', (t) => {
    const wanted = makeRepo(t);
    const other = makeRepo(t);
    wanted.write({ 'a.txt': 'a' }).commit('wanted');
    other.write({ 'b.txt': 'b' }).commit('other');

    const top = withEnv({ GIT_DIR: `${other.dir}/.git`, GIT_WORK_TREE: other.dir, GIT_INDEX_FILE: `${other.dir}/.git/index` }, () =>
        git(wanted.dir, ['rev-parse', '--show-toplevel']).trim(),
    );

    assert.equal(realpathSync(top), realpathSync(wanted.dir));
});

test('cleanGitEnv drops the repository-locating variables and keeps everything else', () => {
    const env = cleanGitEnv({
        GIT_DIR: 'x', GIT_WORK_TREE: 'x', GIT_INDEX_FILE: 'x', GIT_OBJECT_DIRECTORY: 'x',
        GIT_ALTERNATE_OBJECT_DIRECTORIES: 'x', GIT_COMMON_DIR: 'x', GIT_PREFIX: 'x',
        GIT_NAMESPACE: 'x', PATH: '/bin', HOME: '/home/me', GIT_SSH_COMMAND: 'ssh',
    });

    assert.deepEqual(Object.keys(env).sort(), ['GIT_SSH_COMMAND', 'HOME', 'PATH']);
});
