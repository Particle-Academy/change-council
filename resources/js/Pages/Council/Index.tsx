import { Head, Link } from '@inertiajs/react';
import { Badge, Callout, Card, Heading, Input, Select, StatList, Table, Text } from '@particle-academy/react-fancy';
import { useMemo, useState } from 'react';
import { type ConsumerRow, type Summary, usePoll } from './parts/api';
import { label, STATES, stateTone } from './parts/tone';

type Props = {
    changes: Summary[];
    consumers: ConsumerRow[];
    filters: { state?: string; package?: string; class?: string };
    pollMs: number;
    endpoints: { changes: string; show: string };
};

/**
 * Council/Index: every Change, filterable, polled slowly. Built only from Fancy
 * components. Nothing here renders text as HTML.
 */
export default function Index({ changes: initial, consumers, filters, pollMs, endpoints }: Props) {
    const [state, setState] = useState(filters.state ?? '');
    const [pkg, setPkg] = useState(filters.package ?? '');
    const [cls, setCls] = useState(filters.class ?? '');

    const query = new URLSearchParams({ ...(state ? { state } : {}), ...(pkg ? { package: pkg } : {}), ...(cls ? { class: cls } : {}) }).toString();
    const [data, , pollError] = usePoll<{ changes: Summary[] }>(`${endpoints.changes}${query ? `?${query}` : ''}`, { changes: initial }, pollMs);

    // Filters apply at once to what is on screen; the poll then brings the server's answer.
    const changes = useMemo(
        () =>
            data.changes.filter(
                (c) =>
                    (!state || c.state === state) &&
                    (!pkg || c.packages.some((p) => p.name.includes(pkg))) &&
                    (!cls || c.classes.includes(cls)),
            ),
        [data.changes, state, pkg, cls],
    );
    const classes = useMemo(() => [...new Set(data.changes.flatMap((c) => c.classes))].sort(), [data.changes]);
    const count = (s: string) => data.changes.filter((c) => c.state === s).length;
    const open = data.changes.filter((c) => c.state === 'round1_open' || c.state === 'round2_open').length;
    const queued = data.changes.reduce((n, c) => n + c.petitions.queued, 0);

    return (
        <>
            <Head title="Change council" />
            <div className="mx-auto max-w-7xl space-y-6 p-6" data-surface="council-index">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <Heading as="h1" size="2xl" weight="semibold">
                            Change council
                        </Heading>
                        <Text color="muted" size="sm">
                            Major package changes and the consumer agents' votes on them. Silence is not consent.
                        </Text>
                    </div>
                    <StatList
                        data-testid="council-stats"
                        items={[
                            { key: 'open', label: 'In a round', value: open },
                            { key: 'owner', label: 'Awaiting you', value: count('awaiting_owner') },
                            { key: 'escalated', label: 'Escalated', value: count('escalated') },
                            { key: 'approved', label: 'Approved', value: count('approved') },
                        ]}
                    />
                </div>

                {(count('awaiting_owner') > 0 || count('escalated') > 0) && (
                    <Callout color="amber" data-testid="council-needs-owner">
                        {count('awaiting_owner')} awaiting your approval to petition, {count('escalated')} escalated to you. No petition leaves for a
                        non-whitelisted initiation until you approve it.
                    </Callout>
                )}
                {queued > 0 && (
                    <Callout color="red" data-testid="council-queue">
                        {queued} petition(s) are queued and not yet sent. The clerk sends them with php artisan council:petitions.
                    </Callout>
                )}
                {pollError && (
                    <Callout color="red" data-testid="council-poll-error">
                        Could not refresh ({pollError}). Showing the last answer.
                    </Callout>
                )}

                <Card>
                    <Card.Body className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Select
                                data-field="filter-state"
                                label="State"
                                value={state}
                                onValueChange={setState}
                                list={[{ value: '', label: 'Any state' }, ...STATES.map((s) => ({ value: s, label: label(s) }))]}
                                id="council-filter-state"
                            />
                            <Input data-field="filter-package" label="Package" value={pkg} onValueChange={setPkg} placeholder="particle-academy/prism" id="council-filter-package" />
                            <Select
                                data-field="filter-class"
                                label="Class"
                                value={cls}
                                onValueChange={setCls}
                                list={[{ value: '', label: 'Any class' }, ...classes.map((c) => ({ value: c, label: c }))]}
                                id="council-filter-class"
                            />
                        </div>

                        {changes.length === 0 ? (
                            <Text color="muted" data-testid="council-empty">
                                No Changes match.
                            </Text>
                        ) : (
                            <Table className="overflow-x-auto" data-testid="council-changes">
                                <Table.Head>
                                    <Table.Row>
                                        <Table.Cell header>Change</Table.Cell>
                                        <Table.Cell header>State</Table.Cell>
                                        <Table.Cell header>Classes</Table.Cell>
                                        <Table.Cell header>Packages</Table.Cell>
                                        <Table.Cell header>Votes</Table.Cell>
                                        <Table.Cell header>Deadline</Table.Cell>
                                    </Table.Row>
                                </Table.Head>
                                <Table.Body>
                                    {changes.map((c) => (
                                        <Table.Row key={c.ref} data-change-ref={c.ref} data-change-state={c.state}>
                                            <Table.Cell>
                                                <Link href={`${endpoints.show}/${c.ref}`} className="font-medium hover:underline" id={`council-open-${c.ref}`}>
                                                    {c.ref}
                                                </Link>
                                                <div className="text-sm">{c.title}</div>
                                                <div className="text-xs text-zinc-500">
                                                    {label(c.initiation_source)} by {c.initiator}
                                                    {c.whitelisted ? '' : ' (not whitelisted)'}
                                                </div>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color={stateTone(c.state)} variant="soft">
                                                    {label(c.state)}
                                                </Badge>
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {c.approved_unopposed && (
                                                        <Badge color="amber" variant="outline" size="sm">
                                                            unopposed
                                                        </Badge>
                                                    )}
                                                    {c.approved_with_notes && (
                                                        <Badge color="slate" variant="outline" size="sm">
                                                            with notes
                                                        </Badge>
                                                    )}
                                                    {c.late_conflict && (
                                                        <Badge color="red" variant="outline" size="sm">
                                                            late conflict
                                                        </Badge>
                                                    )}
                                                </div>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <span className="flex flex-wrap gap-1">
                                                    {c.classes.map((k) => (
                                                        <Badge key={k} color="zinc" variant="outline" size="sm">
                                                            {k}
                                                        </Badge>
                                                    ))}
                                                </span>
                                            </Table.Cell>
                                            <Table.Cell className="text-sm">
                                                {c.packages.map((p) => (
                                                    <div key={`${p.ecosystem}:${p.name}`}>
                                                        {p.name}
                                                        {p.to ? ` -> ${p.to}` : ''}
                                                    </div>
                                                ))}
                                            </Table.Cell>
                                            <Table.Cell className="text-sm whitespace-nowrap">
                                                {c.current_round ? `round ${c.current_round}: ` : ''}
                                                {c.petitions.answered}/{c.petitions.total} answered
                                                {c.petitions.conflicts > 0 && <span className="text-red-600"> - {c.petitions.conflicts} conflict</span>}
                                            </Table.Cell>
                                            <Table.Cell className="text-xs whitespace-nowrap text-zinc-500">{c.deadline ?? '-'}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table>
                        )}
                    </Card.Body>
                </Card>

                <Card>
                    <Card.Body className="space-y-3">
                        <Heading as="h2" size="lg" weight="semibold">
                            Consumers
                        </Heading>
                        <Text color="muted" size="sm">
                            Who gets petitioned. Silence policy is per consumer; set it on a Change's page or here via the API.
                        </Text>
                        <Table className="overflow-x-auto" data-testid="council-consumers">
                            <Table.Head>
                                <Table.Row>
                                    <Table.Cell header>Agent</Table.Cell>
                                    <Table.Cell header>Declares</Table.Cell>
                                    <Table.Cell header>On silence</Table.Cell>
                                    <Table.Cell header>Census</Table.Cell>
                                </Table.Row>
                            </Table.Head>
                            <Table.Body>
                                {consumers.map((c) => (
                                    <Table.Row key={c.address} data-consumer={c.address}>
                                        <Table.Cell>
                                            <div className="font-medium">{c.address}</div>
                                            <div className="flex gap-1">
                                                {c.internal && (
                                                    <Badge color="zinc" size="sm">
                                                        internal: never petitioned
                                                    </Badge>
                                                )}
                                                {c.whitelisted && (
                                                    <Badge color="green" size="sm">
                                                        whitelisted
                                                    </Badge>
                                                )}
                                                <Badge color="slate" variant="outline" size="sm">
                                                    {c.source}
                                                </Badge>
                                            </div>
                                        </Table.Cell>
                                        <Table.Cell className="text-xs">{c.packages.map((p) => p.name).join(', ') || '-'}</Table.Cell>
                                        <Table.Cell>
                                            <Badge color={c.silence_policy === 'escalate' ? 'amber' : 'blue'} variant="soft">
                                                {label(c.silence_policy)}
                                            </Badge>
                                        </Table.Cell>
                                        <Table.Cell className="text-xs text-zinc-500">{c.census_at ?? 'never'}</Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table>
                    </Card.Body>
                </Card>
            </div>
        </>
    );
}
