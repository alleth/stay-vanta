import { Navigate, Routes, Route } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import Login from './pages/Login'
import Landing from './pages/Landing'
import Hub from './pages/Hub'
import { useAuth } from './context/AuthContext'
import Dashboard from './pages/Dashboard'
import Inventory from './pages/Inventory'
import FrontDesk from './pages/FrontDesk'
import Guests from './pages/Guests'
import Food from './pages/Food'
import Subscribers from './pages/Subscribers'
import Staff from './pages/Staff'
import PrivacyPolicy from './pages/PrivacyPolicy'
import TermsOfService from './pages/TermsOfService'

// Roles allowed in the operational (hotel-floor) tabs.
const OPS = ['admin', 'receptionist']

/**
 * "/" serves the landing page to visitors and forwards signed-in staff to the
 * Hub. `loading` matters here: while the stored token is being resolved there
 * is no user yet, and rendering the landing in that gap would flash marketing
 * copy at someone who is already signed in.
 */
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
        {/* /hub is the post-login home — a role-scoped icon grid
            (src/pages/Hub.jsx) that replaces a persistent tab bar; every
            module lives at its own path, and the header brand leads back
            here. */}
        <Route path="hub" element={<Hub />} />
        <Route path="dashboard" element={<Dashboard />} />
        <Route
          path="inventory"
          element={
            <ProtectedRoute roles={OPS}>
              <Inventory />
            </ProtectedRoute>
          }
        />
        <Route
          path="front-desk"
          element={
            <ProtectedRoute roles={OPS}>
              <FrontDesk />
            </ProtectedRoute>
          }
        />
        <Route
          path="guests"
          element={
            <ProtectedRoute roles={OPS}>
              <Guests />
            </ProtectedRoute>
          }
        />
        <Route
          path="food"
          element={
            <ProtectedRoute roles={OPS}>
              <Food />
            </ProtectedRoute>
          }
        />
        <Route
          path="subscribers"
          element={
            <ProtectedRoute roles={['owner']}>
              <Subscribers />
            </ProtectedRoute>
          }
        />
        <Route
          path="staff"
          element={
            <ProtectedRoute roles={['admin']}>
              <Staff />
            </ProtectedRoute>
          }
        />
      </Route>
    </Routes>
  )
}
