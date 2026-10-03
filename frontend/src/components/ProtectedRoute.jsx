import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { canOpen, navItem } from '../nav'
import BrandSplash from './BrandSplash'

// Guards routes that require authentication and, given `module` (a path in
// nav.js), that the person may open that module: same scope and permission as
// its Hub tile. UX only; the server checks every request itself.
export default function ProtectedRoute({ children, module }) {
  const auth = useAuth()

  if (auth.loading) return <BrandSplash />
  if (!auth.user) return <Navigate to="/login" replace />
  // Not theirs to open — back to the Hub, not the landing page.
  if (module && !canOpen(navItem(module), auth)) return <Navigate to="/hub" replace />

  return children
}
