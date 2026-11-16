import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, readToken, writeToken } from '../api/client'
import type { Role, User } from '../types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const token = ref<string | null>(readToken())
  const loaded = ref(false)

  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const role = computed<Role | null>(() => user.value?.role ?? null)

  function hasRole(...roles: Role[]) {
    return !!user.value && roles.includes(user.value.role)
  }

  function setSession(t: string, u: User) {
    token.value = t
    user.value = u
    writeToken(t)
  }

  async function login(email: string, password: string) {
    const { data } = await api.post('/auth/login', { email, password })
    setSession(data.token, data.user)
    return data.user as User
  }

  async function registerInstitute(payload: Record<string, unknown>) {
    const { data } = await api.post('/auth/register-institute', payload)
    setSession(data.token, data.user)
    return data.user as User
  }

  async function fetchMe() {
    if (!token.value) { loaded.value = true; return null }
    try {
      const { data } = await api.get('/auth/me')
      user.value = data.user
    } catch {
      clear()
    } finally {
      loaded.value = true
    }
    return user.value
  }

  function clear() {
    token.value = null
    user.value = null
    writeToken(null)
  }

  async function logout() {
    try { await api.post('/auth/logout') } catch { /* ignore */ }
    clear()
  }

  return { user, token, loaded, isAuthenticated, role, hasRole, login, registerInstitute, fetchMe, logout, clear, setSession }
})
