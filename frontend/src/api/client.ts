import axios, { AxiosError } from 'axios'

export const TOKEN_KEY = 'tuitionpro_token'

export function readToken(): string | null {
  try { return localStorage.getItem(TOKEN_KEY) } catch { return null }
}
export function writeToken(token: string | null) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch { /* storage unavailable */ }
}

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = readToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(fn: () => void) { onUnauthorized = fn }

api.interceptors.response.use(
  (r) => r,
  (error: AxiosError) => {
    if (error.response?.status === 401 && onUnauthorized) onUnauthorized()
    return Promise.reject(error)
  },
)

/** Extract a human readable message from a Laravel error response. */
export function errorMessage(e: unknown): string {
  const err = e as AxiosError<any>
  const data = err?.response?.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0] as string[]
    if (first?.length) return first[0]
  }
  return data?.message ?? err?.message ?? 'Something went wrong'
}
