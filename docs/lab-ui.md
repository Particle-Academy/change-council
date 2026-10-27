# Lab UI

Two Inertia pages, built only from Fancy components (react-fancy: `Table`,
`Badge`, `Card`, `Tabs`, `Timeline`, `Modal`, `Callout`, `Select`, `Input`,
`Textarea`, `Checkbox`, `CodeView`, `StatList`, `Heading`, `Text`, `Button`).

- **`Council/Index`**: every Change with state, classes, packages, vote counts
  and deadline; filters by state, package and class; a stat row; callouts for
  Changes awaiting the owner or escalated, and for petitions still queued for
  the clerk; the consumer registry with each silence policy and census age.
- **`Council/Show`**: one Change. Owner actions (approve or reject a HITL
  initiation, approve or reject by override, revoke) open a modal that requires
  a reason and, when approving over open blocking conflicts, a checkbox per
  conflict. Tabs: Overview (summary, packages, the frozen approach, the
  initiating agent's pros and cons, decisions), Rounds (a card per petitioned
  agent: tier, delivery, verdict, impact table, reason, suggested alternative,
  injection flags, and the raw reply in a read-only `CodeView`), Evidence
  (detector findings, waived ones labelled), Silence policy (consumer-wide and
  per-Change override per petitioned agent), and Audit (the hash-chained log as a
  `Timeline`).

## Safety properties (source-checked by `tests/Unit/PagesTest.php`)

- Reply text is rendered as text only: no `dangerouslySetInnerHTML`, no rendered
  markup. A reply cannot draw links, images or fake controls.
- Imports are limited to Fancy packages, React and `@inertiajs/react`.
- Polling is at most every 15s and paused while the tab is hidden: a lab is
  served by one php-cgi worker.
- Every control carries a `data-action` or `data-field` handle.

## Mounting

See [laravel-quickstart.md](laravel-quickstart.md#3-mount-the-pages).

## Browser check

The pages were type-checked (`tsc --noEmit`, strict) against react-fancy 5.63's
own types, built with Vite, and rendered in a real browser by a scratch host (a
hand-built Laravel kernel, file SQLite seeded with demo Changes, and a minimal
Inertia-protocol renderer). The host is not part of this package.
