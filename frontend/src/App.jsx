import { Navigate, Routes, Route } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import Login from './pages/Login'
import Landing from './pages/Landing'
import Hub from './pages/Hub'
import { useAuth } from './context/AuthContext'
import { canOpen, navItem } from './nav'
import Operations from './pages/Operations'
import PlatformDashboard from './pages/PlatformDashboard'
import Inventory from './pages/Inventory'
import FrontDesk from './pages/FrontDesk'
import Guests from './pages/Guests'
import Pos from './pages/Pos'
import Finance from './pages/Finance'
import Subscribers from './pages/Subscribers'
import Staff from './pages/Staff'
import PrivacyPolicy from './pages/PrivacyPolicy'
import TermsOfService from './pages/TermsOfService'

/**
 * "/" serves the landing page to visitors and forwards signed-in staff to the
 * Hub. `loading` matters here: while the stored token is being resolved there
 * is no user yet, and rendering the landing in that gap would flash marketing
 * copy at someone who is already signed in.
 */
/**
 * /dashboard is the Platform Owner's page. Hotel staff who arrive there (an
 * old bookmark or link from before the naming release) are forwarded to
 * Operations, which replaced their Dashboard. Depends on who is signed in, so it lives here
 * rather than in Cloudflare's _redirects.
 */
function DashboardRoute() {
  const auth = useAuth()
  if (!canOpen(navItem('/dashboard'), auth)) return <Navigate to="/operations" replace />
  return <PlatformDashboard />
}

function RootRoute() {
  const { user, loading } = useAuth()
  if (loading) return null
  return user ? <Navigate to="/hub" replace /> : <Landing />
}

export default function App() {
  return (
    <Routes>
      {/* "/" is the public landing page, so the Hub moved to /hub. Anyone
          already signed in who lands on "/" is sent straight through to it —
          the marketing page has nothing to tell them. */}
      <Route path="/" element={<RootRoute />} />
      <Route path="/login" element={<Login />} />
      <Route path="/privacy" element={<PrivacyPolicy />} />
      <Route path="/terms" element={<TermsOfService />} />

      <Route
        element={
          <ProtectedRoute>
            <Layout />
          </ProtectedRoute>
        }
      >
        {/* /hub is the post-login home — an icon grid of the modules this person may open
            (src/pages/Hub.jsx) that replaces a persistent tab bar; every
            module lives at its own path, and the header brand leads back
            here. */}
        <Route path="hub" element={<Hub />} />
        <Route path="dashboard" element={<DashboardRoute />} />
        <Route
          path="operations"
          element={
            <ProtectedRoute module="/operations">
              <Operations />
            </ProtectedRoute>
          }
        />
        <Route
          path="finance"
          element={
            <ProtectedRoute module="/finance">
              <Finance />
            </ProtectedRoute>
          }
        />
        {/* Old addresses from before the naming release keep working. */}
        <Route path="revenue" element={<Navigate to="/finance" replace />} />
        <Route path="food" element={<Navigate to="/pos" replace />} />
        <Route
          path="inventory"
          element={
            <ProtectedRoute module="/inventory">
              <Inventory />
            </ProtectedRoute>
          }
        />
        <Route
          path="front-desk"
          element={
            <ProtectedRoute module="/front-desk">
              <FrontDesk />
            </ProtectedRoute>
          }
        />
        <Route
          path="guests"
          element={
            <ProtectedRoute module="/guests">
              <Guests />
            </ProtectedRoute>
          }
        />
        <Route
          path="pos"
          element={
            <ProtectedRoute module="/pos">
              <Pos />
            </ProtectedRoute>
          }
        />
        <Route
          path="subscribers"
          element={
            <ProtectedRoute module="/subscribers">
              <Subscribers />
            </ProtectedRoute>
          }
        />
        <Route
          path="staff"
          element={
            <ProtectedRoute module="/staff">
              <Staff />
            </ProtectedRoute>
          }
        />
      </Route>
    </Routes>
  )
}
