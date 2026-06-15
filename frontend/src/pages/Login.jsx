import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Card, Form, Button, Alert, Spinner } from 'react-bootstrap'
import { useAuth } from '../context/AuthContext'

export default function Login() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function handleSubmit(e) {
    e.preventDefault()
    setError(null)
    setBusy(true)
    try {
      await login(email, password)
      navigate('/')
    } catch {
      setError('Invalid email or password.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="d-flex align-items-center justify-content-center min-vh-100">
      <Card style={{ width: 380 }} className="shadow-sm">
        <Card.Body className="p-4">
          <h1 className="h4 text-center mb-1 fw-bold">
            Stay<span className="text-warning">Vanta</span>
          </h1>
          <p className="text-center text-muted small mb-4">
            All-in-One Hotel &amp; Resort Management
          </p>
          {error && <Alert variant="danger">{error}</Alert>}
          <Form onSubmit={handleSubmit}>
            <Form.Group className="mb-3">
              <Form.Label>Email</Form.Label>
              <Form.Control
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoFocus
              />
            </Form.Group>
            <Form.Group className="mb-4">
              <Form.Label>Password</Form.Label>
              <Form.Control
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
            </Form.Group>
            <Button type="submit" className="w-100" disabled={busy}>
              {busy ? <Spinner size="sm" /> : 'Sign in'}
            </Button>
          </Form>
        </Card.Body>
      </Card>
    </div>
  )
}
