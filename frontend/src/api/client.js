import axios from 'axios'

// Single axios instance for the StayVanta API. The dev server proxies
// '/api' to the CakePHP backend (see vite.config.js). In production set
// VITE_API_BASE_URL to the deployed API origin.
const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api',
  headers: { Accept: 'application/json' },
})

const TOKEN_KEY = 'stayvanta_token'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

// Attach the bearer token to every request when present.
client.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// The permissions version every signed-in response carries (Phase 3c-2):
// AuthContext listens, and re-reads /auth/me when it changes or a request is
// refused, so screens offer what the server now allows. Enforcement is the
// server's; this only keeps the screens in step.
let versionListener = null

export function onPermissionsVersion(listener) {
  versionListener = listener
  return () => {
    if (versionListener === listener) versionListener = null
  }
}

function reportVersion(response, refused) {
  const version = response?.headers?.['x-permissions-version']
  if (version && versionListener) versionListener(version, refused)
}

// Keep each failed request's X-Request-Id on the error (see utils/apiError.js).
client.interceptors.response.use(
  (response) => {
    reportVersion(response, false)
    return response
  },
  (error) => {
    error.requestId = error?.response?.headers?.['x-request-id'] ?? null
    reportVersion(error?.response, error?.response?.status === 403)
    return Promise.reject(error)
  },
)

export default client
