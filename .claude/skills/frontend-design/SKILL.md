---
name: frontend-design
description: Use when building or modifying any UI in frontend/src (pages, modals, cards, tables, forms, loading states) — encodes StayVanta's pure-Tailwind v4 design system so new UI matches the existing app instead of introducing ad hoc styles, a new component library, or Bootstrap-era patterns.
---

# StayVanta frontend design

This app is **pure Tailwind v4** — Bootstrap's CSS is not loaded. `frontend/src/components/ui.jsx`
is a small hand-rolled kit that mirrors the react-bootstrap API surface the pages were originally
written against (`variant`, `size`, `show`/`onHide`, compound components like `Card.Body`), but
every style inside it is a plain Tailwind class. Before writing new UI, check this skill instead
of reaching for raw HTML + ad hoc classes, a new npm UI library, or remembered react-bootstrap
class names (`btn btn-primary` etc. do not exist here).

## Rule 1 — build with the kit, not raw elements

Import from `../components/ui`: `Button`, `Spinner`, `Badge`, `Card` (+ `.Header/.Body/.Footer`),
`Table`, `Modal` (+ `.Header/.Title/.Body/.Footer`), `Form` (+ `.Group/.Label/.Control/.Select/.Check/.Text`),
`Alert`, `Tabs`/`Tab`, `InputGroup` (+ `.Text`), `ButtonGroup`, `Pagination` (+ `.Prev/.Next`), `ListGroup` (+ `.Item`).

A raw `<button>`, `<input>`, `<table>`, or a hand-built modal overlay in a page file is a signal
you should be using one of these instead — check `ui.jsx` for the component before writing new
markup for something that looks like a button, card, form field, table, dialog, alert, or tab strip.

- `Button`: `variant` is one of `primary | secondary | success | danger | warning | outline-primary
  | outline-secondary | outline-success | outline-danger | link`; `size="sm"` for compact. Default
  `type="button"` (forms must opt in explicitly if a button should submit).
- `Card`: plain `<Card>` + `Card.Header`/`Card.Body`/`Card.Footer`. Body defaults to `p-4` padding
  unless the className already sets `p*-`.
- `Table`: wraps in its own horizontal-scroll container and applies `.sv-table` styling (see Rule 2)
  — pass `hover` for row-hover highlighting. Don't add your own `overflow-x-auto` wrapper.
- `Modal`: controlled via `show`/`onHide` (not a portal-managed open state of its own); `size` is
  `undefined | 'lg' | 'xl'`. Handles Escape-to-close and body-scroll-lock already — don't re-implement.
- `Form.Control`/`Form.Select`: `size="sm"` for compact controls (used throughout filter bars).
- `Alert`: `variant` is `danger | success | warning | info | secondary`; pass `dismissible` +
  `onClose` for a close button.
- `Tabs`/`Tab`: tab state lives in the *parent* page (`Tabs` just reads `eventKey`/`title` off each
  `<Tab>` child) — don't add separate state management inside a page for which tab is active if
  `Tabs` already owns it via `defaultActiveKey`.

## Rule 2 — use the design tokens, not arbitrary colors

Tokens live in `frontend/src/index.css`'s `@theme` block and are themselves Tailwind utilities —
never hardcode a hex value or an arbitrary Tailwind gray/slate shade for something a token already
covers:

| Token | Utility classes | Use for |
|---|---|---|
| `--color-canvas` | `bg-canvas` | app background |
| `--color-surface` | `bg-surface` | cards, modals, inputs |
| `--color-line` | `border-line` | hairline borders/dividers |
| `--color-subtle` | `bg-subtle` | quiet fills — chips, active nav, hover rows |
| `--color-body` | `text-body` | primary text |
| `--color-muted` | `text-muted` | secondary/caption text |
| `--color-ink` / `--color-ink-hover` | `bg-ink` / `hover:bg-ink-hover`, `text-ink` | primary actions (buttons, links) |
| `--color-accent` | `text-accent`, `bg-accent`, `border-accent` (+ `focus:ring-accent/…`) | the one accent (amber) — sparing use: focus rings, highlights, the boot splash |
| `--color-accent-soft` | `bg-accent-soft` | amber tint background |

Semantic (non-token) Tailwind colors are still fine for status/severity — `emerald-*` (success),
`red-*` (danger), `amber-*` (warning), `sky-*` (info) — `Badge`/`Alert`/`Button` variants already
encode these; reuse the variant rather than picking a shade by hand.

Custom utility classes from `index.css` `@layer components` (use these, don't recreate them):
- `.sv-serif` — bold, tight letter-spacing display heading treatment (see Rule 3).
- `.sv-accent` — text in the accent color.
- `.sv-table` / `.sv-table-hover` — applied automatically by `<Table>`; don't hand-style `<td>`/`<th>`.
- `.sv-group` — seams a row of inputs/buttons into one bordered control (used by `InputGroup`,
  `ButtonGroup`) — collapses inner radii and overlaps borders. Reuse this instead of hand-rolling
  a joined-control look.

## Rule 3 — page layout conventions

**Page title.** The five domain-module pages (Inventory, Front Desk, Guests, Food & Orders, Staff)
use the plain form — match this for any new module page:
```jsx
<h1 className="mb-0 text-2xl font-bold">Page Name</h1>
```
Dashboard, Login, and Subscribers use a heavier hero treatment instead — only reach for this on a
landing/auth-style screen, not a routine module page:
```jsx
<h1 className="sv-serif mb-1 text-[2rem] font-bold">Page Name</h1>
<p className="mb-6 text-muted">One-line subtitle.</p>
```

**Section labels** inside a page (grouping a block of cards/tables under a heading), per
`Dashboard.jsx`'s `SectionTitle`:
```jsx
<h2 className="mb-4 text-sm font-semibold uppercase tracking-[0.04em] text-muted">Section Name</h2>
```

**Stat/summary tiles** (dashboard cards, count cards): a `Card` with a `text-sm uppercase
tracking-[0.04em] text-muted` label and a large `sv-serif font-bold` value below it — see
`Tiles`/`Card` usage in `Dashboard.jsx` for the exact shape before inventing a new stat-card layout.

## Rule 4 — loading states: skeletons, not spinners

For **initial page/table/card data loads**, use the Tailwind skeleton placeholders from
`frontend/src/components/Skeleton.jsx` — never a spinner for this case:
- `SkeletonTable` — a card-style loading table (heading bar + row bars).
- `SkeletonTableRows` — loading `<tr>`s to drop inside an already-rendered `<table>`'s `<tbody>`
  (matches column count via `cols`).
- `SkeletonCards` — a grid of card-shaped loading blocks (dashboards, stat rows).
- `Skeleton` — the raw bar primitive if none of the above fit.

Reserve `<Spinner size="sm" />` for **inline action feedback** only — a button mid-submit, a
search-as-you-type combobox waiting on a debounced lookup (see `FrontDesk.jsx`'s guest search).
If you're about to render a spinner for a whole page or table's first load, use a Skeleton instead.

## Rule 5 — forms

Use `useSubmit` (`frontend/src/hooks/useSubmit.js`) to wrap a modal/page form's async submit
handler instead of hand-rolling `busy`/`error` state: `const { run, busy, err } = useSubmit(async () => { ... })`,
wire `<Form onSubmit={run}>`, disable the submit `Button` on `busy` (optionally showing `<Spinner
size="sm" />` inside it), and render `err` in an `Alert variant="danger"`. It already extracts a
readable message from CakePHP's `{ errors: { field: { rule: message } } }` validation-error shape,
so don't re-implement that parsing.

Format money with `formatMoney` from `frontend/src/utils/format.js` (Philippine peso) — never
hand-format currency with `toFixed`/`toLocaleString` inline.

## Before finishing UI work

Run `npm run lint` from `frontend/` — flags unused vars/imports and hook-dependency issues, both
common when copying a pattern from another page. Then actually look at the rendered page (dev
server + browser, or ask the user to check) rather than relying on lint alone — lint doesn't catch
a broken layout or a variant that renders but looks wrong.
