// The decision record signed by PHP verifies in Node, and a flipped byte does
// not. Same fixture as tests/Unit/DecisionRecordTest.php.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { canonical, verifyRecord } from '../lib/record.mjs';

const fixtures = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'tests', 'fixtures');
const record = JSON.parse(readFileSync(join(fixtures, 'decision-record.json'), 'utf8'));
const keys = JSON.parse(readFileSync(join(fixtures, 'decision-keys.json'), 'utf8'));

test('a record signed in PHP verifies in Node (canonical JSON agrees across languages)', () => {
    assert.deepEqual(verifyRecord(record, keys), { ok: true, reason: null });
});

test('a flipped byte in any signed field fails', () => {
    for (const mutate of [
        (r) => (r.state = 'approvee'),
        (r) => (r.covers.fingerprints[0] = r.covers.fingerprints[0].replace(/^./, (c) => (c === 'a' ? 'b' : 'a'))),
        (r) => (r.fixture_text = r.fixture_text.replace('/', '\\/')),
        (r) => (r.rounds[0].outcomes.approve = 3),
    ]) {
        const copy = structuredClone(record);
        mutate(copy);
        assert.equal(verifyRecord(copy, keys).ok, false);
    }
});

test('an unknown key id and a mangled signature fail with their own reasons', () => {
    assert.equal(verifyRecord({ ...record, key_id: 'ffffffffffffffff' }, keys).reason, 'unknown_key');
    assert.equal(verifyRecord({ ...record, signature: Buffer.alloc(64).toString('base64') }, keys).reason, 'bad_signature');
});

test('canonical JSON sorts keys, keeps list order and refuses floats', () => {
    assert.equal(canonical({ b: 1, a: { y: 'é/', x: [3, 1] } }), '{"a":{"x":[3,1],"y":"é/"},"b":1}');
    assert.throws(() => canonical({ n: 1.5 }));
});
