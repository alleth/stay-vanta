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

export default client
