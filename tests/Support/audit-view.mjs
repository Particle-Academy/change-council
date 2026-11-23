// Runs the pages' plain-TS audit helper for tests/Unit/AuditViewTest.php.
// Reads a JSON list of audit events on stdin and prints what the helper made of
// them. The pages import siblings without an extension (the host's Vite resolves
// them), so a hook adds `.ts` for Node here; nothing else is resolved or loaded.
import { registerHooks } from 'node:module';
import { readFileSync } from 'node:fs';

registerHooks({
    resolve(specifier, context, next) {
        return next(/^\.\/[A-Za-z]+$/.test(specifier) ? `${specifier}.ts` : specifier, context);
    },
});

const helper = await import(new URL('../../resources/js/Pages/Council/parts/audit.ts', import.meta.url).href);
const events = JSON.parse(readFileSync(0, 'utf8'));
const views = events.map((event) => helper.describeAuditEvent(event));

process.stdout.write(
    JSON.stringify({
        views,
        raw: events.map((event) => helper.rawAuditRecord(event)),
        types: helper.AUDIT_TYPES,
        filters: Object.fromEntries(helper.AUDIT_FILTERS.map((f) => [f.value, helper.filterAuditEvents(events, f.value).map((e) => e.seq)])),
    }),
);
