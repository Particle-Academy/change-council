// The human summary. Plain ASCII, one finding per line, majors first. Waived
// findings are listed, never hidden: a waiver is a decision someone can read.

const line = (f) => {
    const move = f.before !== null || f.after !== null ? `  ${f.before ?? '-'} -> ${f.after ?? '-'}` : '';
    const where = f.file ? `  (${f.file}${f.line ? `:${f.line}` : ''})` : '';
    return `  [${f.class}] ${f.subject}${move}${where}${f.note ? `\n      ${f.note}` : ''}`;
};

export function summarise(report) {
    const out = [];
    out.push(`change-council detector ${report.detector.version} (REPORT-ONLY: Phase 1 never fails a release)`);
    out.push(`repo: ${report.repo}`);
    out.push(`compare: ${report.from ?? '(none)'} -> ${report.to}${report.toSha ? ` (${report.toSha.slice(0, 12)})` : ''}   kind: ${report.kind ?? '?'}`);
    if (!report.ok) {
        out.push('');
        out.push('DETECTOR ERROR - this report is incomplete:');
        for (const e of report.errors) out.push(`  ! ${e}`);
    }
    const groups = [
        ['MAJOR', report.findings.filter((f) => !f.waived && f.severity === 'major')],
        ['minor', report.findings.filter((f) => !f.waived && f.severity === 'minor')],
        ['info', report.findings.filter((f) => !f.waived && f.severity === 'info')],
        ['waived (listed, not counted)', report.findings.filter((f) => f.waived)],
    ];
    for (const [label, list] of groups) {
        if (list.length === 0) continue;
        out.push('');
        out.push(`${label} (${list.length})`);
        for (const f of list) out.push(line(f) + (f.waived ? `\n      waived: ${f.waived.kind} - ${f.waived.reason}` : ''));
    }
    if (report.findings.length === 0 && report.ok) {
        out.push('');
        out.push('No findings.');
    }
    if (report.declared) out.push(`\ndeclared: ${report.declared}`);
    const coverage = Object.entries(report.coverage);
    if (coverage.length > 0) {
        out.push('');
        out.push('coverage:');
        for (const [name, c] of coverage) out.push(`  ${name}: ${c.status}${c.detail ? ` - ${c.detail}` : ''}`);
    }
    out.push('');
    out.push(`summary: ${report.summary.major} major, ${report.summary.minor} minor, ${report.summary.info} info, ${report.summary.waived} waived`);
    if (report.summary.major > 0) out.push('A release containing these needs an approved Change (Phase 3 will gate on it; today this is a report).');
    for (const n of report.notes) out.push(n);
    return out.join('\n') + '\n';
}
