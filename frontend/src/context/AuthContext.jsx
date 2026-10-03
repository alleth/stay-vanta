import { createContext, useContext, useEffect, useMemo, useState } from 'react'
import client, { getToken, setToken } from '../api/client'
import { ROLE_FALLBACK } from '../auth/permissions'

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

  const role = user?.role ?? null
  // What this person may do (docs/PERMISSIONS.md). Sent by the server; a
  // session from before it was sent falls back to the role's fixed list.
  const granted = useMemo(
    () => new Set(user?.permissions ?? ROLE_FALLBACK[user?.role] ?? []),
    [user],
  )
  const value = {
    user, loading, login, logout, role,
    can: (permission) => granted.has(permission),
    // Where they work, which a permission never implies: the platform (the
    // Platform Owner, bound to no property) or one property. Screens check
    // both: scope picks platform vs hotel screens, can() what's on them.
    scope: user ? (user.property_id == null ? 'platform' : 'property') : null,
  }
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
