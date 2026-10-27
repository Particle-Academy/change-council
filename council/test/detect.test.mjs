// Must-fire and must-not-fire fixtures for every detector class.
//
// A detector proven only on clean input has never been seen to detect anything,
// so each class below has a pair of git states where the finding MUST appear,
// and each waiver has a pair where the finding must NOT count as major.

import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { detect } from '../lib/detect.mjs';
import { counted, makeRepo, pair, waived } from './helpers.mjs';

const run = (repo, options = {}) => detect({ repo: repo.dir, from: 'v1.0.0', to: 'v1.1.0', ...options });

const composer = (extra = {}) => ({ name: 'particle-academy/widget', type: 'library', autoload: { 'psr-4': { 'Widget\\': 'src/' } }, ...extra });
const pkg = (extra = {}) => ({ name: '@particle-academy/widget', version: '1.0.0', main: 'dist/index.js', ...extra });

// ---------------------------------------------------------------- third-party

test('third-party-major: a composer runtime floor crossing a major fires', (t) => {
    const repo = pair(t, { 'composer.json': composer({ require: { 'acme/http': '^2.1' } }) }, { 'composer.json': composer({ require: { 'acme/http': '^3.0' } }) });
    const [finding] = counted(run(repo), 'third-party-major');
    assert.equal(finding.subject, 'composer:acme/http');
    assert.equal(finding.before, '^2.1');
    assert.equal(finding.after, '^3.0');
    assert.equal(finding.file, 'composer.json');
    assert.match(finding.fingerprint, /^[0-9a-f]{64}$/);
});

test('third-party-major: an npm dependency floor crossing a 0.x minor fires (pre-1.0 minors are majors)', (t) => {
    const repo = pair(t, { 'package.json': pkg({ dependencies: { zed: '^0.4.2' } }) }, { 'package.json': pkg({ dependencies: { zed: '^0.5.0' } }) });
    assert.equal(counted(run(repo), 'third-party-major').length, 1);
});

test('third-party-major: a widened range does not fire', (t) => {
    const repo = pair(t, { 'package.json': pkg({ dependencies: { zed: '^2.0' } }) }, { 'package.json': pkg({ dependencies: { zed: '^2.0 || ^3.0' } }) });
    assert.equal(run(repo).findings.filter((f) => f.severity === 'major').length, 0);
});

test('a floor raised within the same major does not fire', (t) => {
    const repo = pair(t, { 'package.json': pkg({ dependencies: { zed: '^2.0' } }) }, { 'package.json': pkg({ dependencies: { zed: '^2.4' } }) });
    assert.equal(run(repo).findings.filter((f) => f.severity === 'major').length, 0);
});

test('first-party sibling floor across a major is a peer-floor finding, not third-party', (t) => {
    const repo = pair(t, { 'composer.json': composer({ require: { 'particle-academy/fancy-cms': '>=0.8 <2.0' } }) }, { 'composer.json': composer({ require: { 'particle-academy/fancy-cms': '>=0.9 <2.0' } }) });
    const report = run(repo);
    assert.equal(counted(report, 'third-party-major').length, 0);
    assert.equal(counted(report, 'peer-floor').length, 1);
});

test('lockfiles: an app composer.lock major moves fire; a library lockfile is info only', (t) => {
    const lock = (v) => ({ packages: [{ name: 'acme/http', version: v }], 'packages-dev': [] });
    const app = pair(t, { 'composer.json': composer({ type: 'project' }), 'composer.lock': lock('2.3.0') }, { 'composer.lock': lock('3.0.1') });
    assert.equal(counted(run(app), 'third-party-major').length, 1);

    const lib = pair(t, { 'composer.json': composer(), 'composer.lock': lock('2.3.0') }, { 'composer.lock': lock('3.0.1') });
    const report = run(lib);
    assert.equal(counted(report, 'third-party-major').length, 0);
    assert.equal(counted(report, 'third-party-major', 'info').length, 1);
});

test('lockfiles: package-lock.json and pnpm-lock.yaml majors fire in an app', (t) => {
    const npmLock = (v) => ({ lockfileVersion: 3, packages: { '': { name: 'app' }, 'node_modules/zed': { version: v } } });
    const npm = pair(t, { 'package.json': { name: 'app', private: true }, 'package-lock.json': npmLock('1.9.0') }, { 'package-lock.json': npmLock('2.0.0') });
    assert.equal(counted(run(npm), 'third-party-major').length, 1);

    const pnpmLock = (v) => `lockfileVersion: '9.0'\n\nimporters:\n\n  .:\n    dependencies:\n      zed:\n        specifier: ^${v}\n        version: ${v}\n    devDependencies:\n      tooling:\n        specifier: ^${v}\n        version: ${v}\n`;
    const pnpm = pair(t, { 'package.json': { name: 'app', private: true }, 'pnpm-lock.yaml': pnpmLock('1.2.0') }, { 'pnpm-lock.yaml': pnpmLock('2.0.0') });
    const report = run(pnpm);
    assert.equal(counted(report, 'third-party-major').length, 1, 'zed fires');
    assert.equal(counted(report, 'third-party-major')[0].subject, 'npm:zed');
    assert.equal(waived(report, 'dev-only').length, 1, 'the dev-only tooling move is waived, not dropped');
});

// ---------------------------------------------------------------- platform / peer floors

test('platform-floor: composer php floor raised fires', (t) => {
    const repo = pair(t, { 'composer.json': composer({ require: { php: '^8.3' } }) }, { 'composer.json': composer({ require: { php: '^8.4' } }) });
    const [finding] = counted(run(repo), 'platform-floor');
    assert.equal(finding.subject, 'composer:php');
});

test('platform-floor: engines.node raised fires; a ceiling raise does not', (t) => {
    const raised = pair(t, { 'package.json': pkg({ engines: { node: '>=20' } }) }, { 'package.json': pkg({ engines: { node: '>=22' } }) });
    assert.equal(counted(run(raised), 'platform-floor').length, 1);

    const ceiling = pair(t, { 'composer.json': composer({ require: { php: '^8.4' } }) }, { 'composer.json': composer({ require: { php: '^8.4 || ^9.0' } }) });
    assert.equal(run(ceiling).findings.filter((f) => f.severity === 'major').length, 0);
});

test('platform-floor: python requires-python raised fires', (t) => {
    const toml = (v) => `[project]\nname = "widget"\nrequires-python = ">=${v}"\ndependencies = [\n  "httpx>=0.27",\n]\n`;
    const repo = pair(t, { 'pyproject.toml': toml('3.10') }, { 'pyproject.toml': toml('3.12') });
    assert.equal(counted(run(repo), 'platform-floor').length, 1);
});

test('peer-floor: dropping a peer major fires', (t) => {
    const repo = pair(t, { 'package.json': pkg({ peerDependencies: { react: '^18.0 || ^19.0' } }) }, { 'package.json': pkg({ peerDependencies: { react: '^19.0' } }) });
    const [finding] = counted(run(repo), 'peer-floor');
    assert.equal(finding.subject, 'npm:react (peer)');
});

// ---------------------------------------------------------------- schema

const migration = (up, down = '') => `<?php\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n${up}\n    }\n\n    public function down(): void\n    {\n${down}\n    }\n};\n`;

test('schema: a new migration that drops a column fires', (t) => {
    const repo = pair(t, { 'database/migrations/2026_01_01_000000_create_things.php': migration("        Schema::create('things', fn ($t) => $t->id());") }, {
        'database/migrations/2026_02_01_000000_drop_name.php': migration("        Schema::table('things', function (Blueprint $table) {\n            $table->dropColumn('name');\n        });"),
    });
    const [finding] = counted(run(repo), 'schema');
    assert.match(finding.subject, /drop_name/);
    assert.equal(finding.line > 0, true);
});

test('schema: an additive migration whose down() drops is NOT major', (t) => {
    const repo = pair(t, { 'README.md': 'x' }, {
        'database/migrations/2026_02_01_000000_add_note.php': migration("        Schema::table('things', fn ($t) => $t->string('note')->nullable());", "        Schema::table('things', fn ($t) => $t->dropColumn('note'));"),
    });
    const report = run(repo);
    assert.equal(counted(report, 'schema').length, 0);
    assert.equal(counted(report, 'schema', 'info').length, 1);
});

test('schema: editing an already-shipped migration fires', (t) => {
    const path = 'database/migrations/2026_01_01_000000_create_things.php';
    const repo = pair(t, { [path]: migration("        Schema::create('things', fn ($t) => $t->id());") }, { [path]: migration("        Schema::create('things', function ($t) { $t->id(); $t->string('x'); });") });
    assert.equal(counted(run(repo), 'schema').length, 1);
});

const eventsSchema = (overrides = {}) => ({
    $schema: 'https://json-schema.org/draft/2020-12/schema',
    type: 'object',
    required: ['type'],
    properties: {
        type: { type: 'string', enum: ['run.started', 'run.finished'] },
        data: { type: 'object' },
        note: { type: 'string' },
    },
    ...overrides,
});

test('schema: JSON schema property removed, required added, enum narrowed and additionalProperties tightened all fire', (t) => {
    const after = eventsSchema({
        required: ['type', 'data'],
        additionalProperties: false,
        properties: { type: { type: 'string', enum: ['run.started'] }, data: { type: 'object' } },
    });
    const repo = pair(t, { 'resources/schema/events.v1.json': eventsSchema() }, { 'resources/schema/events.v1.json': after });
    const subjects = counted(run(repo), 'schema').map((f) => f.subject).sort();
    assert.deepEqual(subjects, [
        'resources/schema/events.v1.json#/additionalProperties',
        'resources/schema/events.v1.json#/properties/note',
        'resources/schema/events.v1.json#/properties/type/enum/run.finished',
        'resources/schema/events.v1.json#/required/data',
    ]);
});

test('schema: a new optional property is info only', (t) => {
    const after = eventsSchema();
    after.properties.extra = { type: 'string' };
    const repo = pair(t, { 'resources/schema/events.v1.json': eventsSchema() }, { 'resources/schema/events.v1.json': after });
    const report = run(repo);
    assert.equal(counted(report, 'schema').length, 0);
    assert.equal(counted(report, 'schema', 'info').length, 1);
});

test('schema: a new vN+1 file beside the old one is major (deprecation-shaped)', (t) => {
    const repo = pair(t, { 'resources/schema/events.v1.json': eventsSchema() }, { 'resources/schema/events.v2.json': eventsSchema() });
    const [finding] = counted(run(repo), 'schema');
    assert.match(finding.note, /v1/);
});

// ---------------------------------------------------------------- contract / surface snapshots

test('contract: a committed surface snapshot losing an item fires; adding one is info', (t) => {
    const snap = (items) => ({ schema: 'change-council/surface.v1', class: 'contract', items });
    const repo = pair(t, { '.council/surface/mcp-tools.json': snap({ list_changes: { input: ['state'] }, get_change: { input: ['ref'] } }) }, {
        '.council/surface/mcp-tools.json': snap({ list_changes: { input: ['state'] }, propose_change: { input: ['title'] } }),
    });
    const report = run(repo);
    const [removed] = counted(report, 'contract');
    assert.equal(removed.subject, '.council/surface/mcp-tools.json#get_change');
    assert.equal(counted(report, 'contract', 'info').length, 1);
});

test('surface snapshots carry Prism classes through the generic mechanism, with a per-snapshot changed severity', (t) => {
    const matrix = (claude) => ({ schema: 'change-council/surface.v1', class: 'support-matrix', items: { 'claude-cli': claude } });
    const pricing = (price) => ({ schema: 'change-council/surface.v1', class: 'pricing', changed: 'info', items: { 'claude-x': { input: price } } });
    const repo = pair(t, { '.council/surface/support.json': matrix('>=2.1.0'), '.council/surface/pricing.json': pricing(3) }, { '.council/surface/support.json': matrix('>=2.1.295'), '.council/surface/pricing.json': pricing(4) });
    const report = run(repo);
    assert.equal(counted(report, 'support-matrix').length, 1, 'a support-matrix floor change is major');
    assert.equal(counted(report, 'pricing').length, 0, 'a price-only change is not major');
    assert.equal(counted(report, 'pricing', 'info').length, 1, 'but it is listed');
});

test('contracts.json maps a non-default path to a surface class (Prism corpus rows)', (t) => {
    const cases = (expected) => ({ 'case-1': { input: 'a', expected }, 'case-2': { input: 'b', expected: 'B' } });
    const contracts = { surfaces: [{ path: 'suites/*/cases.json', class: 'corpus-expectation' }] };
    const repo = pair(t, { '.council/contracts.json': contracts, 'suites/parse/cases.json': cases('A') }, { 'suites/parse/cases.json': cases('AA') });
    assert.equal(counted(run(repo), 'corpus-expectation').length, 1);
});

// ---------------------------------------------------------------- deprecation

const changelog = (body) => `# Changelog\n\n**BREAKING** marks anything that can stop working on upgrade.\n\n## [Unreleased]\n\n${body}\n## [1.0.0] - 2026-01-01\n\n### Added\n- First.\n`;

test('deprecation: CHANGELOG Deprecated and Removed sections in a new entry fire, one per bullet', (t) => {
    const repo = pair(t, { 'CHANGELOG.md': changelog('') }, { 'CHANGELOG.md': changelog('### Deprecated\n- `Action`, use `Button`.\n\n### Removed\n- The `legacy` option.\n- The `old()` helper.\n') });
    assert.equal(counted(run(repo), 'deprecation').length, 3);
});

test('deprecation: the preamble BREAKING explanation is not a marker', (t) => {
    const repo = pair(t, { 'CHANGELOG.md': changelog('') }, { 'CHANGELOG.md': changelog('### Added\n- A thing.\n') });
    assert.equal(run(repo).findings.filter((f) => f.severity === 'major').length, 0);
});

test('deprecation: a new @deprecated in source fires, an existing one does not', (t) => {
    const before = 'export function a() {}\n/** @deprecated use b */\nexport function old() {}\n';
    const after = 'export function a() {}\n/** @deprecated use b */\nexport function old() {}\n/**\n * @deprecated use c\n */\nexport function older() {}\n';
    const repo = pair(t, { 'src/index.ts': before }, { 'src/index.ts': after });
    const found = counted(run(repo), 'deprecation');
    assert.equal(found.length, 1);
    assert.match(found[0].subject, /older/);
    assert.equal(found[0].line, 5);
});

// ---------------------------------------------------------------- api removal

test('api-removal: a TS export removed from a committed entry .d.ts fires, an addition does not', (t) => {
    const p = pkg({ types: 'dist/index.d.ts' });
    const before = "export { Card, CardProps } from './Card-AbCdEf12.js';\nexport declare function useThing(id: string): void;\nexport declare const VERSION: string;\n";
    const after = "export { CardProps } from './Card-Zz9Yy8Xx.js';\nexport declare function useThing(id: string): void;\nexport declare const VERSION: string;\nexport declare const EXTRA: number;\n";
    const repo = pair(t, { 'package.json': p, 'dist/index.d.ts': before, 'dist/Card-AbCdEf12.d.ts': 'declare function Card(): void;\nexport { Card };\n' }, { 'dist/index.d.ts': after, 'dist/Card-Zz9Yy8Xx.d.ts': 'export {};\n' }, { removeAfter: ['dist/Card-AbCdEf12.d.ts'] });
    const found = counted(run(repo), 'api-removal');
    assert.deepEqual(found.map((f) => f.subject), ['dist/index.d.ts#Card']);
});

test('api-removal: a new required TS parameter fires; an optional one does not', (t) => {
    const p = pkg({ types: 'dist/index.d.ts' });
    const repo = pair(t, { 'package.json': p, 'dist/index.d.ts': 'export declare function open(id: string, opts?: Options): void;\nexport declare function close(id: string): void;\n' }, {
        'dist/index.d.ts': 'export declare function open(id: string, mode: Mode, opts?: Options): void;\nexport declare function close(id: string, force?: boolean): void;\n',
    });
    const report = run(repo);
    assert.deepEqual(counted(report, 'api-removal').map((f) => f.subject), ['dist/index.d.ts#open']);
});

test('api-removal: .d.ts from two built directories (dist is not committed)', (t) => {
    const base = mkdtempSync(join(tmpdir(), 'council-dts-'));
    t.after(() => rmSync(base, { recursive: true, force: true }));
    mkdirSync(join(base, 'a'));
    mkdirSync(join(base, 'b'));
    writeFileSync(join(base, 'a', 'index.d.ts'), 'export declare function keep(): void;\nexport declare function gone(): void;\n');
    writeFileSync(join(base, 'b', 'index.d.ts'), 'export declare function keep(): void;\n');
    const repo = pair(t, { 'package.json': pkg() }, { 'README.md': 'x' });
    const found = counted(run(repo, { fromDts: join(base, 'a'), toDts: join(base, 'b') }), 'api-removal');
    assert.deepEqual(found.map((f) => f.subject), ['dts:index.d.ts#gone']);
});

const phpClass = (body, head = 'final class Client') => `<?php\n\nnamespace Widget;\n\n${head}\n{\n${body}\n}\n`;

test('api-removal: a removed PHP public method and a new required parameter fire', (t) => {
    const before = phpClass('    public function send(string $to): void {}\n    public function close(): void {}\n    private function hidden(): void {}');
    const after = phpClass('    public function send(string $to, string $from): void {}\n    private function close(): void {}');
    const repo = pair(t, { 'composer.json': composer(), 'src/Client.php': before }, { 'src/Client.php': after });
    const report = run(repo);
    assert.equal(report.coverage.php.status, 'ran', JSON.stringify(report.coverage.php));
    const subjects = counted(report, 'api-removal').map((f) => f.subject).sort();
    assert.deepEqual(subjects, ['Widget\\Client::close', 'Widget\\Client::send']);
});

test('a doc comment inside a PHP parameter list is not part of the type (found on fancy-flow-php)', (t) => {
    const method = (doc) => `    public function run(\n        array $a = [],\n        /** ${doc} */\n        ?int $max = null,\n    ): void {}`;
    const repo = pair(t, { 'composer.json': composer(), 'src/Client.php': phpClass(method('How many may run.')) }, { 'src/Client.php': phpClass(method('How many may be held at once.')) });
    assert.deepEqual(run(repo).findings, []);
});

test('api-removal: a PHP class moved between files is not a removal; a deleted class is', (t) => {
    const repo = pair(t, { 'composer.json': composer(), 'src/A.php': phpClass('    public function a(): void {}', 'class A'), 'src/B.php': phpClass('    public function b(): void {}', 'class B') }, {
        'src/Moved/A.php': phpClass('    public function a(): void {}', 'class A'),
    }, { removeAfter: ['src/A.php', 'src/B.php'] });
    assert.deepEqual(counted(run(repo), 'api-removal').map((f) => f.subject), ['Widget\\B']);
});

test('api-removal: a method added to a PHP interface fires (implementers break)', (t) => {
    const repo = pair(t, { 'composer.json': composer(), 'src/Port.php': phpClass('    public function a(): void;', 'interface Port') }, { 'src/Port.php': phpClass('    public function a(): void;\n    public function b(): void;', 'interface Port') });
    assert.deepEqual(counted(run(repo), 'api-removal').map((f) => f.subject), ['Widget\\Port::b']);
});

// ---------------------------------------------------------------- declared breaking

test('declared-breaking: a CHANGELOG BREAKING marker in the new entry fires', (t) => {
    const repo = pair(t, { 'CHANGELOG.md': changelog('') }, { 'CHANGELOG.md': changelog('### Changed\n- **BREAKING:** kind ids are namespaced.\n') });
    assert.equal(counted(run(repo), 'declared-breaking').length, 1);
});

test('declared-breaking: Prism release-notes "BREAKING CHANGE:" in an annotated tag fires', (t) => {
    const repo = pair(t, { 'README.md': 'a' }, { 'README.md': 'b' }, { tagMessage: 'v1.1.0\n\nBREAKING CHANGE: approvals now fail closed.\n' });
    const [finding] = counted(run(repo), 'declared-breaking');
    assert.equal(finding.subject, 'release-notes: approvals now fail closed.');
});

test('declared-breaking: a --release-notes file is read; "No breaking changes." is recorded, not flagged', (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'council-notes-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    const breaking = join(dir, 'breaking.md');
    const none = join(dir, 'none.md');
    writeFileSync(breaking, 'Release v1.1.0\n\nBREAKING CHANGE: the thread-row schema adds a mode column.\n');
    writeFileSync(none, 'Release v1.1.0\n\nNo breaking changes.\n');
    const repo = pair(t, { 'README.md': 'a' }, { 'README.md': 'b' });

    assert.equal(counted(run(repo, { releaseNotes: breaking }), 'declared-breaking').length, 1);
    const clean = run(repo, { releaseNotes: none });
    assert.equal(clean.findings.length, 0);
    assert.equal(clean.declared, 'no-breaking-changes');
});

test('declared-breaking: a BREAKING CHANGE: footer in a commit between the refs fires', (t) => {
    const repo = makeRepo(t);
    repo.write({ 'a.txt': '1' }).commit('one', 'v1.0.0');
    repo.write({ 'a.txt': '2' }).commit('feat: rework\n\nBREAKING CHANGE: the queue name changed.');
    repo.write({ 'a.txt': '3' }).commit('chore', 'v1.1.0');
    assert.equal(counted(run(repo), 'declared-breaking').length, 1);
});

// ---------------------------------------------------------------- waivers (must NOT count)

test('waiver: a devDependency major is waived dev-only and reported, never counted', (t) => {
    const repo = pair(t, { 'package.json': pkg({ devDependencies: { vitest: '^3.0' } }) }, { 'package.json': pkg({ devDependencies: { vitest: '^4.0' } }) });
    const report = run(repo);
    assert.equal(report.summary.major, 0);
    assert.equal(waived(report, 'dev-only').length, 1);
});

test('waiver: a composer require-dev major is waived dev-only', (t) => {
    const repo = pair(t, { 'composer.json': composer({ 'require-dev': { 'pestphp/pest': '^3.0' } }) }, { 'composer.json': composer({ 'require-dev': { 'pestphp/pest': '^4.0' } }) });
    const report = run(repo);
    assert.equal(report.summary.major, 0);
    assert.equal(waived(report, 'dev-only').length, 1);
});

test('waiver: a test fixture schema losing a property is waived test-fixture', (t) => {
    const after = eventsSchema();
    delete after.properties.note;
    const repo = pair(t, { 'tests/fixtures/events.schema.json': eventsSchema() }, { 'tests/fixtures/events.schema.json': after });
    const report = run(repo);
    assert.equal(report.summary.major, 0);
    assert.equal(waived(report, 'test-fixture').length, 1);
});

test('waiver: an internal-only PHP file losing a method is waived internal (default path and @internal)', (t) => {
    const repo = pair(t, {
        'composer.json': composer(),
        'src/Internal/Helper.php': phpClass('    public function a(): void {}', 'final class Helper').replace('namespace Widget;', 'namespace Widget\\Internal;'),
        'src/Tagged.php': `<?php\n\nnamespace Widget;\n\n/** @internal */\nfinal class Tagged\n{\n    public function a(): void {}\n}\n`,
    }, {
        'src/Internal/Helper.php': phpClass('', 'final class Helper').replace('namespace Widget;', 'namespace Widget\\Internal;'),
        'src/Tagged.php': `<?php\n\nnamespace Widget;\n\n/** @internal */\nfinal class Tagged\n{\n}\n`,
    });
    const report = run(repo);
    assert.equal(report.summary.major, 0, JSON.stringify(report.findings));
    assert.equal(waived(report, 'internal').length, 2);
});

test('waiver: contracts.json internal globs waive a schema nothing exports', (t) => {
    const after = eventsSchema();
    delete after.properties.note;
    const repo = pair(t, { '.council/contracts.json': { internal: ['resources/schema/private/**'] }, 'resources/schema/private/x.json': eventsSchema() }, { 'resources/schema/private/x.json': after });
    const report = run(repo);
    assert.equal(report.summary.major, 0);
    assert.equal(waived(report, 'internal').length, 1);
});

// ---------------------------------------------------------------- general

test('no previous tag: nothing to compare, and the report says so', (t) => {
    const repo = makeRepo(t);
    repo.write({ 'a.txt': '1' }).commit('one');
    const report = detect({ repo: repo.dir });
    assert.equal(report.findings.length, 0);
    assert.equal(report.from, null);
    assert.match(report.notes.join(' '), /no previous tag/i);
});

test('the previous tag is found from HEAD^ when --from is omitted', (t) => {
    const repo = pair(t, { 'composer.json': composer({ require: { 'acme/http': '^2.1' } }) }, { 'composer.json': composer({ require: { 'acme/http': '^3.0' } }) });
    const report = detect({ repo: repo.dir });
    assert.equal(report.from, 'v1.0.0');
    assert.equal(counted(report, 'third-party-major').length, 1);
});

test('fingerprints come only from git content: the same change in two repos has the same fingerprint', (t) => {
    const make = () => pair(t, { 'composer.json': composer({ require: { 'acme/http': '^2.1' } }) }, { 'composer.json': composer({ require: { 'acme/http': '^3.0' } }) });
    const a = counted(run(make()), 'third-party-major')[0].fingerprint;
    const b = counted(run(make()), 'third-party-major')[0].fingerprint;
    assert.equal(a, b);
});

test('UNDETECTABLE, said plainly: a behaviour change behind an unchanged signature produces no finding', (t) => {
    const repo = pair(t, { 'composer.json': composer(), 'src/Client.php': phpClass('    public function timeout(): int { return 30; }') }, { 'src/Client.php': phpClass('    public function timeout(): int { return 5; }') });
    const report = run(repo);
    assert.equal(report.findings.length, 0);
    assert.match(report.notes.join(' '), /behaviour/);
});
