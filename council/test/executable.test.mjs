// Runs on every platform, including Ubuntu CI: the PATH walk takes its platform
// and its file check as inputs. The cases are the same file in fancy-git-js and
// fancy-git-php, so a behaviour change has to be made in all three.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { findExecutable, isAbsoluteEntry, resolveExecutable, GitError } from '../lib/git.mjs';

const cases = JSON.parse(readFileSync(new URL('./fixtures/executable-lookup-cases.json', import.meta.url), 'utf8'));

test('the lookup fixture has cases to run', () => {
    assert.ok(cases.length > 20);
});

for (const c of cases) {
    test(`findExecutable: ${c.name}`, () => {
        const files = new Set(c.files);
        const found = findExecutable(c.name_to_find, {
            platform: c.platform,
            env: { PATH: c.PATH, ...(c.PATHEXT === null ? {} : { PATHEXT: c.PATHEXT }) },
            isFile: (path) => files.has(path),
        });
        assert.deepEqual(found, c.expect);
    });
}

test('findExecutable reads Path and Pathext in any case on Windows', () => {
    const found = findExecutable('git', {
        platform: 'win32',
        env: { Path: 'C:\\real', pathext: '.EXE' },
        isFile: (path) => path === 'C:\\real\\git.EXE',
    });
    assert.equal(found.path, 'C:\\real\\git.EXE');
});

test('isAbsoluteEntry does not take a cwd-dependent entry for an absolute one', () => {
    for (const entry of ['C:x', 'C:', '\\x', '/x', '.', '', '\\\\server']) assert.equal(isAbsoluteEntry(entry, 'win32'), false, entry);
    for (const entry of ['C:\\x', 'c:/x', '\\\\server\\share']) assert.equal(isAbsoluteEntry(entry, 'win32'), true, entry);
    for (const entry of ['.', '', 'bin', 'C:\\x']) assert.equal(isAbsoluteEntry(entry, 'linux'), false, entry);
    assert.equal(isAbsoluteEntry('/usr/bin', 'linux'), true);
});

test('resolveExecutable returns an absolute path as given', () => {
    assert.equal(resolveExecutable(process.execPath), process.execPath);
});

test('resolveExecutable fails closed with a clear error when nothing is found', () => {
    assert.throws(() => resolveExecutable('no-such-program-change-council'), (error) => {
        assert.ok(error instanceof GitError);
        assert.match(error.message, /current directory is never used/);
        return true;
    });
});

test('resolveExecutable caches a success and does not cache a failure', () => {
    const saved = process.env.PATH;
    try {
        process.env.PATH = '';
        assert.throws(() => resolveExecutable('git'), /Could not find/);
        process.env.PATH = saved;
        const first = resolveExecutable('git');
        process.env.PATH = '';
        assert.equal(resolveExecutable('git'), first);
    } finally {
        process.env.PATH = saved;
    }
});
