import { useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, Form, ListGroup, Table } from '../ui'
import { SkeletonTable } from '../Skeleton'
import ReasonModal from '../ReasonModal'
import { useAuth } from '../../context/AuthContext'
import { P } from '../../auth/permissions'
import { changeEnforcement, getEnforcement } from '../../api/platform'
import { describeError } from '../../utils/apiError'
import { roleLabel } from '../../utils/roles'

const PHASES = {
  report: 'Report only',
  grace: 'Grace',
  read_only: 'Read-only',
  suspend: 'Suspension',
}
const STAGES = [
  ['active', 'Not affected'],
  ['grace', 'Grace'],
  ['read_only', 'Read-only'],
  ['suspended', 'Suspended'],
]
const EVENTS = {
  enforcement_phase_recorded: 'Phase in force when the history began',
  enforcement_phase_raised: 'Raised',
  enforcement_phase_lowered: 'Lowered (rollback)',
  enforcement_ceiling_observed: 'Emergency ceiling observed',
}
const phaseLabel = (p) => (p ? PHASES[p] ?? p : 'None')

/**
 * Subscription enforcement (final review G6, Platform Owner): the phase set,
 * the emergency ceiling and the phase in force; what each phase would enforce
 * now (E-D6); one step forward or any step back, with a reason; and every
 * change with who, why and the request id. Managers never see this (E-D4).
 */
export default function EnforcementPanel({ onChanged }) {
  const { can } = useAuth()
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [target, setTarget] = useState(null)
  const [rollbackTo, setRollbackTo] = useState('')

  useEffect(() => {
    getEnforcement()
      .then(setData)
      .catch((ex) => setError(describeError(ex, 'Could not load subscription enforcement.')))
  }, [])

  if (error && !data) return <Alert variant="danger">{error}</Alert>
  if (!data) return <SkeletonTable rows={4} />

  const order = data.phases
  const at = order.indexOf(data.phase)
  const next = order[at + 1] ?? null
  const earlier = order.slice(0, at)
  const canChange = can(P.PLATFORM_ENFORCEMENT_MANAGE)
  const enforced = data.preview.enforced_by_phase

  const describe = (phase) => {
    const counts = enforced[phase] ?? {}
    return STAGES.filter(([k]) => k !== 'active')
      .map(([k, label]) => `${label}: ${counts[k] ?? 0}`).join(' · ')
  }

  return (
    <Card className="mb-4">
      <Card.Header>Subscription enforcement</Card.Header>
      <Card.Body>
        {error && <Alert variant="danger">{error}</Alert>}
        <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Fact label="Phase set" value={phaseLabel(data.phase)} />
          <Fact label="Emergency ceiling" value={phaseLabel(data.ceiling)}
            note={data.ceiling ? 'APP_SUBSCRIPTION_ENFORCEMENT is set: it lowers the phase in force' : 'Not set'} />
          <Fact label="In force" value={phaseLabel(data.effective)} strong />
        </div>

        <h2 className="mb-2 text-sm font-semibold uppercase tracking-[0.04em] text-muted">
          What each phase would enforce now ({data.preview.properties} properties)
        </h2>
        <Table>
          <thead>
            <tr>
              <th>Phase</th>
              {STAGES.map(([k, label]) => <th key={k} className="text-right">{label}</th>)}
            </tr>
          </thead>
          <tbody>
            {order.map((p) => (
              <tr key={p}>
                <td>
                  {phaseLabel(p)}
                  {p === data.phase && <Badge bg="primary" className="ml-2">current</Badge>}
                  {p === next && <Badge bg="secondary" className="ml-2">next step</Badge>}
                </td>
                {STAGES.map(([k]) => (
                  <td key={k} className="text-right tabular-nums">{enforced[p]?.[k] ?? 0}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </Table>
        <p className="mt-2 mb-4 text-xs text-muted">
          By their payment dates: {STAGES.map(([k, label]) => `${label.toLowerCase()} ${data.preview.stages[k] ?? 0}`).join(', ')}.
          A phase enforces a stage only up to its own level.
        </p>

        {canChange && (
          <div className="mb-4 flex flex-wrap items-end gap-3">
            {next && (
              <Button variant="danger" onClick={() => setTarget(next)}>Raise to {phaseLabel(next)}</Button>
            )}
            {earlier.length > 0 && (
              <div className="flex items-end gap-2">
                <Form.Group>
                  <Form.Label className="text-xs">Roll back to</Form.Label>
                  <Form.Select size="sm" value={rollbackTo} onChange={(e) => setRollbackTo(e.target.value)}>
                    <option value="">Choose a phase…</option>
                    {earlier.map((p) => <option key={p} value={p}>{phaseLabel(p)}</option>)}
                  </Form.Select>
                </Form.Group>
                <Button variant="outline-secondary" size="sm" disabled={!rollbackTo} onClick={() => setTarget(rollbackTo)}>
                  Roll back
                </Button>
              </div>
            )}
          </div>
        )}

        <h2 className="mb-2 text-sm font-semibold uppercase tracking-[0.04em] text-muted">History</h2>
        {data.history.length === 0 ? (
          <p className="mb-0 text-sm text-muted">No changes recorded.</p>
        ) : (
          <ListGroup>
            {data.history.map((e) => (
              <ListGroup.Item key={e.id} className="px-0 py-3">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{EVENTS[e.event] ?? e.event}</span>
                  <span className="ml-auto text-xs text-muted">{new Date(e.at).toLocaleString()}</span>
                </div>
                <div className="text-sm">
                  {e.event === 'enforcement_ceiling_observed'
                    ? `Ceiling ${phaseLabel(e.before)} → ${phaseLabel(e.after)}`
                    : `${e.before ? `${phaseLabel(e.before)} → ` : ''}${phaseLabel(e.after)}`}
                  {e.snapshot?.moved_stricter > 0 && ` · ${e.snapshot.moved_stricter} propert${e.snapshot.moved_stricter === 1 ? 'y' : 'ies'} moved to a stricter stage`}
                </div>
                <div className="text-sm text-muted">
                  {e.source === 'import' && 'Not recorded: who set it, when and why were never kept'}
                  {e.source === 'system' && 'Observed by the app at start-up (when it was seen, not when it was set)'}
                  {e.source === 'web' && `${e.actor ?? 'Unknown user'}${e.actor_role ? ` (${roleLabel(e.actor_role)})` : ''}`}
                </div>
                {e.reason && <div className="text-sm">“{e.reason}”</div>}
                <div className="text-xs text-muted">Reference: {String(e.request_id).slice(0, 8)}</div>
              </ListGroup.Item>
            ))}
          </ListGroup>
        )}
      </Card.Body>

      <ReasonModal
        show={target !== null}
        title={target && order.indexOf(target) > at ? `Raise enforcement to ${phaseLabel(target)}` : `Roll back to ${phaseLabel(target)}`}
        description={target && `Applies to every property on its next request. ${phaseLabel(target)} would enforce now: ${describe(target)}. `
          + 'Recorded with your name, this reason and the request.'}
        confirmLabel={target && order.indexOf(target) > at ? 'Raise' : 'Roll back'}
        onHide={() => setTarget(null)}
        onConfirm={async (reason) => {
          // A refusal (skipped phase, same phase) stays in the dialog with its reference.
          setData(await changeEnforcement(target, reason))
          setTarget(null)
          setRollbackTo('')
          onChanged?.()
        }}
      />
    </Card>
  )
}

function Fact({ label, value, note, strong = false }) {
  return (
    <div className="rounded-md border border-line p-3">
      <div className="text-xs uppercase tracking-[0.04em] text-muted">{label}</div>
      <div className={`sv-serif text-xl ${strong ? 'font-bold text-accent' : 'font-semibold'}`}>{value}</div>
      {note && <div className="text-xs text-muted">{note}</div>}
    </div>
  )
}
