import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import BrandSplash from './BrandSplash'

// Guards routes that require authentication, and optionally a set of roles.
export default function ProtectedRoute({ children, roles }) {
  const { user, loading } = useAuth()

  if (loading) return <BrandSplash />
  if (!user) return <Navigate to="/login" replace />
  // Wrong role for this module — back to the Hub, not the landing page.
  if (roles && !roles.includes(user.role)) return <Navigate to="/hub" replace />

  return children
}
