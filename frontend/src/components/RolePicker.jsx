import { Form } from './ui'

/**
 * Pick a role a person can be given (Phase 3c-2): the roles the server says
 * you may give (`assignable_roles`), built-ins first, then custom roles (the
 * Platform Owner's alone), with what the chosen one grants and how many
 * people hold it. `value` / `onChange` work with the role's id.
 */
export default function RolePicker({ roles, value, onChange, disabled = false, label = 'Role' }) {
  const builtIn = roles.filter((r) => r.kind !== 'custom')
  const custom = roles.filter((r) => r.kind === 'custom')
  const chosen = roles.find((r) => r.id === value)
  const option = (r) => <option key={r.id} value={r.id}>{r.name}</option>

  return (
    <Form.Group className="mb-4">
      <Form.Label>{label}</Form.Label>
      <Form.Select
        value={value ?? ''}
        onChange={(e) => onChange(Number(e.target.value))}
        disabled={disabled || roles.length === 1}
      >
        {custom.length === 0 ? builtIn.map(option) : (
          <>
            <optgroup label="Built-in">{builtIn.map(option)}</optgroup>
            <optgroup label="Custom">{custom.map(option)}</optgroup>
          </>
        )}
      </Form.Select>
      {chosen && (
        <Form.Text muted>
          {chosen.description ? `${chosen.description} · ` : ''}
          {chosen.permission_count} permission{chosen.permission_count === 1 ? '' : 's'}
          {' · '}held by {chosen.holders} {chosen.holders === 1 ? 'person' : 'people'}
        </Form.Text>
      )}
    </Form.Group>
  )
}
