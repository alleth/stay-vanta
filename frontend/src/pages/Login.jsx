import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { Card, Form, Button, Alert, Spinner } from '../components/ui'
import BrandMark from '../components/BrandMark'
import ThemeToggle from '../components/ThemeToggle'
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
      navigate('/hub')
    } catch (err) {
      // Surface what the API actually said. A rate-limited attempt comes back
      // as 429 with how long the pause has left — telling that user their
      // password is wrong, for fifteen minutes, would be actively misleading.
      // Login answers with {error}; other endpoints use {message}.
      const data = err?.response?.data
      setError(data?.error ?? data?.message ?? 'Invalid email or password.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="relative flex min-h-screen items-center justify-center px-4">
      {/* Sign-in is reachable straight from the landing page's header, so it
          needs a way back out. Mirrors the Back on Privacy/Terms, and sits
          opposite the toggle. */}
      <Button as={Link} to="/" variant="outline-secondary" size="sm"
        className="absolute left-4 top-4">
        ← Back
      </Button>
      {/* Login renders outside Layout, so it needs its own toggle — otherwise
          the theme can only be changed after signing in. */}
      <ThemeToggle className="absolute right-4 top-4" />
      <Card className="w-full max-w-[400px]">
        <Card.Body className="p-10">
          <BrandMark className="mx-auto mb-3 h-12 w-12" />
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
