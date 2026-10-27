// Decision-record verification in Node, with built-ins only: the canonical
// JSON of design 8.2 and Ed25519 through node:crypto. Must agree byte for byte
// with ChangeCouncil\Support\CanonicalJson (PHP); a shared fixture is verified
// by both suites. Phase 2 only verifies; the gate that requires a record is
// Phase 3.

import { createPublicKey, verify } from 'node:crypto';

/** Keys sorted by code unit (the same as byte order for ASCII keys), lists kept, floats refused. */
export function canonical(value) {
    if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
    if (value !== null && typeof value === 'object') {
        return `{${Object.keys(value).sort().map((k) => `${JSON.stringify(k)}:${canonical(value[k])}`).join(',')}}`;
    }
    if (typeof value === 'number' && !Number.isInteger(value)) throw new Error('canonical JSON refuses floats');
    return JSON.stringify(value);
}

// The fixed DER prefix of an Ed25519 SubjectPublicKeyInfo; the raw 32-byte key follows.
const SPKI_PREFIX = Buffer.from('302a300506032b6570032100', 'hex');

export function publicKeyFromRaw(base64) {
    const raw = Buffer.from(base64, 'base64');
    if (raw.length !== 32) throw new Error('an Ed25519 public key is 32 bytes');
    return createPublicKey({ key: Buffer.concat([SPKI_PREFIX, raw]), format: 'der', type: 'spki' });
}

/** @returns {{ok: boolean, reason: string|null}} */
export function verifyRecord(record, keys) {
    if (record?.schema !== 'change-council/decision.v1') return { ok: false, reason: 'unknown_schema' };
    const key = keys[record.key_id];
    if (typeof key !== 'string') return { ok: false, reason: 'unknown_key' };
    const signature = Buffer.from(String(record.signature ?? ''), 'base64');
    if (signature.length !== 64) return { ok: false, reason: 'bad_signature' };
    const { signature: _omit, ...signed } = record;
    let message;
    try {
        message = Buffer.from(canonical(signed), 'utf8');
    } catch {
        return { ok: false, reason: 'not_canonical' };
    }
    return verify(null, message, publicKeyFromRaw(key), signature) ? { ok: true, reason: null } : { ok: false, reason: 'bad_signature' };
}
