import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

// Guards routes that require authentication, and optionally a set of roles.
export default function ProtectedRoute({ children, roles }) {
  const { user, loading } = useAuth()

  if (loading) return <div className="p-5 text-center">Loading…</div>
  if (!user) return <Navigate to="/login" replace />
  if (roles && !roles.includes(user.role)) return <Navigate to="/" replace />

  return children
}
