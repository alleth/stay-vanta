import { createContext, useCallback, useContext, useEffect, useState } from 'react'

const ThemeContext = createContext(null)
const STORAGE_KEY = 'stayvanta_theme'

// A stored value means the user picked a side with the header toggle; no
// stored value means "whatever the OS says", and we keep following it live.
// The pre-paint script in index.html reads the same key — keep the two in
// sync, it's what stops a light flash before React mounts.
function storedTheme() {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    return v === 'light' || v === 'dark' ? v : null
  } catch {
    // Private mode / blocked storage: fall back to the OS every load.
    return null
  }
}

const systemTheme = () =>
  (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')

export function ThemeProvider({ children }) {
  const [chosen, setChosen] = useState(storedTheme)
  const [system, setSystem] = useState(systemTheme)
  const theme = chosen ?? system

  // Only relevant while the user hasn't chosen — but the listener is cheap
  // and unconditional, so `system` is always current if they reset later.
  useEffect(() => {
    const mq = window.matchMedia?.('(prefers-color-scheme: dark)')
    if (!mq) return undefined
    const onChange = (e) => setSystem(e.matches ? 'dark' : 'light')
    mq.addEventListener('change', onChange)
    return () => mq.removeEventListener('change', onChange)
  }, [])

  // The single write of the attribute every token override keys off.
  useEffect(() => {
    document.documentElement.dataset.theme = theme
  }, [theme])

  const setTheme = useCallback((next) => {
    setChosen(next)
    try {
      if (next) localStorage.setItem(STORAGE_KEY, next)
      else localStorage.removeItem(STORAGE_KEY)
    } catch {
      // Nothing to do — the choice still applies for this session.
    }
  }, [])

  const toggle = useCallback(
    () => setTheme(theme === 'dark' ? 'light' : 'dark'),
    [theme, setTheme],
  )

  const value = { theme, toggle, setTheme, followingSystem: chosen === null }
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useTheme() {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider')
  return ctx
}
