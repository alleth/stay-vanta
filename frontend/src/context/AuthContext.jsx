import { createContext, useContext, useEffect, useState } from 'react'
import client, { getToken, setToken } from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  // Only "loading" when there's a token to resolve on boot.
  const [loading, setLoading] = useState(() => !!getToken())

  // On boot, if a token exists, resolve the current user.
  useEffect(() => {
    if (!getToken()) return
    client
      .get('/auth/me')
      .then((res) => setUser(res.data.user))
      .catch(() => setToken(null))
      .finally(() => setLoading(false))
  }, [])

  async function login(email, password) {
    const res = await client.post('/auth/login', { email, password })
    setToken(res.data.token)
    setUser(res.data.user)
    return res.data.user
  }

  function logout() {
    setToken(null)
    setUser(null)
  }

  const value = { user, loading, login, logout, role: user?.role ?? null }
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
