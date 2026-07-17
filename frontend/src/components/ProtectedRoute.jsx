import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import BrandSplash from './BrandSplash'

// Guards routes that require authentication, and optionally a set of roles.
export default function ProtectedRoute({ children, roles }) {
  const { user, loading } = useAuth()

  if (loading) return <BrandSplash />
  if (!user) return <Navigate to="/login" replace />
  if (roles && !roles.includes(user.role)) return <Navigate to="/" replace />

  return children
}
