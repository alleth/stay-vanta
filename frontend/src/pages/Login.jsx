import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { Card, Form, Button, Alert, Spinner } from '../components/ui'
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
    <div className="flex min-h-screen items-center justify-center px-4">
      <Card className="w-full max-w-[400px] shadow-sm">
        <Card.Body className="p-10">
          <h1 className="sv-serif mb-1 text-center text-[2rem] font-bold">
            Stay<span className="sv-accent">Vanta</span>
          </h1>
          <p className="mb-6 text-center text-sm text-muted">
            All-in-One Hotel &amp; Resort Management
          </p>
          {error && <Alert variant="danger">{error}</Alert>}
          <Form onSubmit={handleSubmit}>
            <Form.Group className="mb-4">
              <Form.Label>Email</Form.Label>
              <Form.Control
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoFocus
              />
            </Form.Group>
            <Form.Group className="mb-6">
              <Form.Label>Password</Form.Label>
              <Form.Control
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
            </Form.Group>
            <Button type="submit" className="w-full" disabled={busy}>
              {busy ? <Spinner size="sm" /> : 'Sign in'}
            </Button>
          </Form>
          <p className="mt-6 mb-0 text-center text-sm text-muted">
            <Link to="/privacy" className="text-muted no-underline hover:text-body">
              Privacy Policy
            </Link>
            <span className="mx-2">·</span>
            <Link to="/terms" className="text-muted no-underline hover:text-body">
              Terms of Service
            </Link>
          </p>
        </Card.Body>
      </Card>
    </div>
  )
}
