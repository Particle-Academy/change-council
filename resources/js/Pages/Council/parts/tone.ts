/** Badge colours for states, verdicts and severities: one table, every page. */

type Tone = 'zinc' | 'blue' | 'amber' | 'red' | 'green' | 'violet' | 'slate' | 'orange';

export const STATES = ['awaiting_owner', 'round1_open', 'awaiting_revision', 'round2_open', 'escalated', 'approved', 'rejected', 'withdrawn', 'revoked'] as const;

export function stateTone(state: string): Tone {
    switch (state) {
        case 'awaiting_owner':
        case 'escalated':
            return 'amber';
        case 'round1_open':
        case 'round2_open':
            return 'blue';
        case 'awaiting_revision':
            return 'violet';
        case 'approved':
            return 'green';
        case 'rejected':
        case 'revoked':
            return 'red';
        default:
            return 'zinc';
    }
}

export function verdictTone(verdict: string | null): Tone {
    if (verdict === 'approve') return 'green';
    if (verdict === 'conflict') return 'red';
    if (verdict === 'abstain') return 'slate';
    return 'zinc';
}

export function severityTone(severity: string): Tone {
    if (severity === 'blocker') return 'red';
    if (severity === 'major') return 'orange';
    return 'amber';
}

export const label = (value: string): string => value.replace(/_/g, ' ');
