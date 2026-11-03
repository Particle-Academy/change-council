// A same-named executable in the repository under inspection, or in the directory
// the detector was started from, must never run in place of git or php. "Reads git
// objects, not the working tree" does not help, because the executable sits in the
// tree.
//
// Each test starts with a CONTROL that spawns the bare name in the same environment
// and requires the plant to fire: without it, a machine that does not search the
// cwd would pass every assertion here on vulnerable code. The ambient
// NoDefaultCurrentDirectoryInExePath is exactly that, so it is removed and the
// removal is asserted.

import { test, before, after, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chmodSync, copyFileSync, linkSync, mkdirSync, mkdtempSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { makeRepo, pair, runCli } from './helpers.mjs';

const WINDOWS = process.platform === 'win32';
const windowsOnly = { skip: !WINDOWS };
const posixOnly = { skip: WINDOWS };

let root;
let nodeOptions;
let plantBinary;
let saved;
let caseNumber = 0;

const markers = (dir) => readdirSync(dir).filter((file) => file.endsWith('.marker'));

function scratch() {
    const dir = join(root, `case-${++caseNumber}`);
    mkdirSync(dir);
    return dir;
}

// Node is the one real executable every run of this suite has. Under another name,
// with the preload in NODE_OPTIONS, it is a plant. `version` rather than `--version`:
// node answers its own --version before running a preload, so that form never fires.
function plant(dir, name) {
    linkSync(plantBinary, join(dir, name));
}

const bare = (name, cwd) => spawnSync(name, ['version'], { cwd, encoding: 'utf8', windowsHide: true });

before(() => {
    root = mkdtempSync(join(tmpdir(), 'council-plant-'));
    if (!WINDOWS) return;
    plantBinary = join(root, 'plant-binary.exe');
    copyFileSync(process.execPath, plantBinary);
    const script = join(root, 'plant.cjs');
    writeFileSync(script, [
        'const { basename, dirname, join } = require("node:path");',
        'const self = basename(process.execPath).toLowerCase();',
        'if (self === "git.exe" || self === "php.exe") {',
        '  require("node:fs").writeFileSync(join(dirname(process.execPath), "ran-" + self + ".marker"), "ran");',
        '  process.exit(7);',
        '}',
        '',
    ].join('\n'));
    nodeOptions = `--require "${script.replaceAll('\\', '/')}"`;
});

after(() => {
    rmSync(root, { recursive: true, force: true });
});

beforeEach(() => {
    saved = { ...process.env };
    delete process.env.NoDefaultCurrentDirectoryInExePath;
    assert.equal(process.env.NoDefaultCurrentDirectoryInExePath, undefined);
    if (WINDOWS) process.env.NODE_OPTIONS = nodeOptions;
});

afterEach(() => {
    for (const key of Object.keys(process.env)) if (!(key in saved)) delete process.env[key];
    Object.assign(process.env, saved);
});

test('a git.exe in the repository runs under a bare name (control), and not through the detector', windowsOnly, (t) => {
    const repo = makeRepo(t);
    repo.write({ 'a.txt': 'a' }).commit('first', 'v1.0.0');
    repo.write({ 'a.txt': 'b' }).commit('second', 'v1.1.0');
    plant(repo.dir, 'git.exe');

    const control = bare('git', repo.dir);
    assert.equal(control.status, 7);
    assert.deepEqual(markers(repo.dir), ['ran-git.exe.marker']);
    rmSync(join(repo.dir, 'ran-git.exe.marker'));

    const cli = runCli(['--repo', repo.dir, '--json'], scratch());
    const report = JSON.parse(cli.stdout);
    assert.equal(report.ok, true, JSON.stringify(report.errors));
    assert.match(report.toSha ?? report.to, /\S/);
    assert.deepEqual(markers(repo.dir), []);
});

test('a git.exe in the start directory is not run, when the repository is that directory', windowsOnly, (t) => {
    const repo = makeRepo(t);
    repo.write({ 'a.txt': 'a' }).commit('first', 'v1.0.0');
    repo.write({ 'a.txt': 'b' }).commit('second', 'v1.1.0');
    plant(repo.dir, 'git.exe');

    const report = JSON.parse(runCli(['--repo', '.', '--json'], repo.dir).stdout);
    assert.equal(report.ok, true, JSON.stringify(report.errors));
    assert.deepEqual(markers(repo.dir), []);
});

test('a php.exe in the start directory runs under a bare name (control), and not through the detector', windowsOnly, (t) => {
    const repo = pair(t,
        { 'composer.json': { autoload: { 'psr-4': { 'A\\': 'src/' } } }, 'src/A.php': '<?php\nnamespace A;\nclass A { public function gone() {} }\n' },
        { 'src/A.php': '<?php\nnamespace A;\nclass A {}\n' },
    );
    const start = scratch();
    plant(start, 'php.exe');

    assert.equal(bare('php', start).status, 7);
    assert.deepEqual(markers(start), ['ran-php.exe.marker']);
    rmSync(join(start, 'ran-php.exe.marker'));

    const cli = runCli(['--repo', repo.dir, '--json'], start);
    assert.ok(JSON.parse(cli.stdout).schema, cli.stdout);
    assert.deepEqual(markers(start), []);
});

test('git only in the repository fails closed, and the report says why', windowsOnly, (t) => {
    const repo = makeRepo(t);
    repo.write({ 'a.txt': 'a' }).commit('first', 'v1.0.0');
    plant(repo.dir, 'git.exe');
    process.env.PATH = `${process.env.SystemRoot}\\System32`;

    const cli = runCli(['--repo', repo.dir, '--json'], scratch());
    const report = JSON.parse(cli.stdout);
    assert.equal(report.ok, false);
    assert.match(JSON.stringify(report.errors), /current directory is never used/);
    assert.deepEqual(markers(repo.dir), []);
});

test('a dot or an empty PATH entry is not searched', windowsOnly, (t) => {
    const repo = makeRepo(t);
    repo.write({ 'a.txt': 'a' }).commit('first', 'v1.0.0');
    plant(repo.dir, 'git.exe');
    const real = process.env.PATH;

    for (const path of [`.;${real}`, `;${real}`]) {
        process.env.PATH = path;
        assert.equal(bare('git', repo.dir).status, 7, path);
        rmSync(join(repo.dir, 'ran-git.exe.marker'));

        const report = JSON.parse(runCli(['--repo', '.', '--json'], repo.dir).stdout);
        assert.equal(report.ok, true, JSON.stringify(report.errors));
        assert.deepEqual(markers(repo.dir), [], path);
    }
});

test('an absolute PATH entry is found, identified by the plant it runs there', windowsOnly, (t) => {
    const repo = makeRepo(t);
    repo.write({ 'a.txt': 'a' }).commit('first', 'v1.0.0');
    const onPath = scratch();
    plant(onPath, 'git.exe');
    process.env.PATH = onPath;

    const report = JSON.parse(runCli(['--repo', repo.dir, '--json'], scratch()).stdout);
    assert.equal(report.ok, false);
    assert.deepEqual(markers(onPath), ['ran-git.exe.marker']);
});

test('a batch-file git on PATH is not run, and the report says why', windowsOnly, (t) => {
    const repo = makeRepo(t);
    repo.write({ 'a.txt': 'a' }).commit('first', 'v1.0.0');
    const onPath = scratch();
    writeFileSync(join(onPath, 'git.cmd'), `@echo ran > "${join(onPath, 'ran-git.cmd.marker')}"\r\n`);
    process.env.PATH = `${onPath};${process.env.SystemRoot}\\System32`;

    const report = JSON.parse(runCli(['--repo', repo.dir, '--json'], scratch()).stdout);
    assert.equal(report.ok, false);
    assert.match(JSON.stringify(report.errors), /batch file/);
    assert.deepEqual(markers(onPath), []);
});

test('COUNCIL_PHP still names the php to run', (t) => {
    const repo = pair(t,
        { 'composer.json': { autoload: { 'psr-4': { 'A\\': 'src/' } } }, 'src/A.php': '<?php\nnamespace A;\nclass A { public function gone() {} }\n' },
        { 'src/A.php': '<?php\nnamespace A;\nclass A {}\n' },
    );
    process.env.COUNCIL_PHP = join(scratch(), 'no-such-php');

    const report = JSON.parse(runCli(['--repo', repo.dir, '--json'], scratch()).stdout);
    assert.ok(report.schema);
    assert.match(JSON.stringify(report), /php not on PATH/);
});

test('a dot on PATH is not searched on POSIX (control first)', posixOnly, (t) => {
    const repo = makeRepo(t);
    repo.write({ 'a.txt': 'a' }).commit('first', 'v1.0.0');
    const script = join(repo.dir, 'git');
    writeFileSync(script, '#!/bin/sh\necho ran > "$(dirname "$0")/ran-git.marker"\nexit 7\n');
    chmodSync(script, 0o755);
    process.env.PATH = `.:${process.env.PATH}`;

    assert.equal(bare('git', repo.dir).status, 7);
    rmSync(join(repo.dir, 'ran-git.marker'));

    const report = JSON.parse(runCli(['--repo', '.', '--json'], repo.dir).stdout);
    assert.equal(report.ok, true, JSON.stringify(report.errors));
    assert.deepEqual(markers(repo.dir), []);
});
