#!/usr/bin/env node
// change-council detector CLI. Zero dependencies: Node built-ins only, so CI can
// fetch this file (pinned by commit SHA and content hash) and run it with the
// Node every runner already has.
//
//   node council/check.mjs [--repo .] [--from <ref>] [--to <ref>]
//                          [--release-notes <file>] [--from-dts <dir> --to-dts <dir>]
//                          [--json] [--out <file>] [--report]
//
// Phase 1 is REPORT-ONLY: it exits 0 whatever it finds, including a detector
// error (which is reported loudly in the output instead). `--strict` is reserved
// for the Phase 3 gate and refuses to run until that gate exists, rather than
// silently passing a release someone believed was gated.

import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { detect } from './lib/detect.mjs';
import { summarise } from './lib/summary.mjs';

const VALUE = new Set(['--repo', '--from', '--to', '--release-notes', '--from-dts', '--to-dts', '--out']);
const FLAG = new Set(['--json', '--report', '--strict', '--help']);

function parse(argv) {
    const options = {};
    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i];
        const [name, inline] = arg.includes('=') ? [arg.slice(0, arg.indexOf('=')), arg.slice(arg.indexOf('=') + 1)] : [arg, null];
        if (VALUE.has(name)) {
            const value = inline ?? argv[++i];
            if (value === undefined) throw new Error(`${name} needs a value`);
            options[name.slice(2)] = value;
        } else if (FLAG.has(name)) {
            options[name.slice(2)] = true;
        } else {
            throw new Error(`unknown option ${arg}`);
        }
    }
    return options;
}

let options;
try {
    options = parse(process.argv.slice(2));
} catch (error) {
    process.stderr.write(`change-council: ${error.message}\nUsage: node council/check.mjs [--repo .] [--from ref] [--to ref] [--release-notes file] [--from-dts dir --to-dts dir] [--json] [--out file]\n`);
    process.exit(2);
}

if (options.help) {
    process.stdout.write('node council/check.mjs [--repo .] [--from ref] [--to ref] [--release-notes file] [--from-dts dir --to-dts dir] [--json] [--out file]\nReport-only (Phase 1): always exits 0. See docs/ci.md.\n');
    process.exit(0);
}

if (options.strict) {
    process.stderr.write('change-council: --strict is reserved for the Phase 3 release gate, which does not exist yet.\nRefusing rather than reporting a pass nobody gated. Run without --strict for the report.\n');
    process.exit(2);
}

const report = detect({
    repo: resolve(options.repo ?? '.'),
    from: options.from ?? null,
    to: options.to ?? 'HEAD',
    releaseNotes: options['release-notes'] ?? null,
    fromDts: options['from-dts'] ? resolve(options['from-dts']) : undefined,
    toDts: options['to-dts'] ? resolve(options['to-dts']) : undefined,
});

const json = JSON.stringify(report, null, 2) + '\n';
if (options.out) writeFileSync(options.out, json);
process.stdout.write(options.json ? json : summarise(report));
if (!options.json && options.out) process.stdout.write(`report written to ${options.out}\n`);
process.exit(0);
