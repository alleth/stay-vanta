import { Routes, Route } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import Login from './pages/Login'
import Dashboard from './pages/Dashboard'
import Inventory from './pages/Inventory'
import FrontDesk from './pages/FrontDesk'
import Guests from './pages/Guests'
import Food from './pages/Food'
import Properties from './pages/Properties'
import Reports from './pages/Reports'
import Staff from './pages/Staff'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />

      <Route
        element={
          <ProtectedRoute>
            <Layout />
          </ProtectedRoute>
        }
      >
        <Route index element={<Dashboard />} />
        <Route path="inventory" element={<Inventory />} />
        <Route path="front-desk" element={<FrontDesk />} />
        <Route path="guests" element={<Guests />} />
        <Route path="food" element={<Food />} />
        <Route
          path="properties"
          element={
            <ProtectedRoute roles={['owner']}>
              <Properties />
            </ProtectedRoute>
          }
        />
        <Route
          path="reports"
          element={
            <ProtectedRoute roles={['owner', 'admin']}>
              <Reports />
            </ProtectedRoute>
          }
        />
        <Route
          path="staff"
          element={
            <ProtectedRoute roles={['owner', 'admin']}>
              <Staff />
            </ProtectedRoute>
          }
        />
      </Route>
    </Routes>
  )
}
