import { Routes, Route } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import Login from './pages/Login'
import Hub from './pages/Hub'
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

export default function App() {
  return (
    <Routes>
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
        {/* "/" is the post-login Hub — a role-scoped icon grid (src/pages/Hub.jsx)
            that replaces a persistent tab bar; every module lives at its own
            path and the header's Home link is the only way back here. */}
        <Route index element={<Hub />} />
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
