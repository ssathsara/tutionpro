import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '../src/api/client'
import { useAuthStore } from '../src/stores/auth'

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('stores the token and user after login', async () => {
    vi.spyOn(api, 'post').mockResolvedValue({ data: { token: 'abc', user: { id: 1, name: 'Nimal', role: 'institute_admin', email: 'a@b.lk', institute_id: 1 } } })
    const auth = useAuthStore()
    await auth.login('a@b.lk', 'password')
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.hasRole('institute_admin')).toBe(true)
    expect(auth.hasRole('student')).toBe(false)
    expect(localStorage.getItem('tuitionpro_token')).toBe('abc')
  })

  it('clears the session when /me fails', async () => {
    localStorage.setItem('tuitionpro_token', 'stale')
    setActivePinia(createPinia())
    vi.spyOn(api, 'get').mockRejectedValue(new Error('401'))
    const auth = useAuthStore()
    await auth.fetchMe()
    expect(auth.user).toBeNull()
    expect(auth.token).toBeNull()
    expect(auth.loaded).toBe(true)
    expect(localStorage.getItem('tuitionpro_token')).toBeNull()
  })

  it('logout clears state even if the API call fails', async () => {
    vi.spyOn(api, 'post').mockRejectedValue(new Error('network'))
    const auth = useAuthStore()
    auth.setSession('t', { id: 1, name: 'X', email: 'x@y.lk', role: 'student', institute_id: 1 })
    await auth.logout()
    expect(auth.isAuthenticated).toBe(false)
  })
})
