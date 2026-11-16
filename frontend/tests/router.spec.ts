import { describe, expect, it } from 'vitest'
import { resolveAccess, routes } from '../src/router'
import { NAV, navFor } from '../src/router/nav'

describe('resolveAccess (route guard)', () => {
  it('sends guests to login for protected routes', () => {
    expect(resolveAccess({}, null)).toBe('/login')
  })
  it('allows public routes for everyone', () => {
    expect(resolveAccess({ public: true }, null)).toBe(true)
    expect(resolveAccess({ public: true }, { role: 'student' })).toBe(true)
  })
  it('redirects signed-in users away from guest-only pages', () => {
    expect(resolveAccess({ public: true, guestOnly: true }, { role: 'teacher' })).toBe('/')
  })
  it('enforces role restrictions', () => {
    const meta = { roles: ['institute_admin' as const] }
    expect(resolveAccess(meta, { role: 'institute_admin' })).toBe(true)
    expect(resolveAccess(meta, { role: 'student' })).toBe('/')
  })
})

describe('navigation', () => {
  it('only shows items allowed for the role', () => {
    const student = navFor('student').map((n) => n.to)
    expect(student).toContain('/exams')
    expect(student).toContain('/fees')
    expect(student).not.toContain('/students')
    expect(student).not.toContain('/analytics')

    const parent = navFor('parent').map((n) => n.to)
    expect(parent).not.toContain('/discussions')

    expect(navFor('super_admin').map((n) => n.to)).toEqual(['/', '/institutes'])
    expect(navFor(null)).toEqual([])
  })

  it('every nav item points to a route whose roles include the nav roles', () => {
    for (const item of NAV) {
      const route = routes.find((r) => r.path === item.to)
      expect(route, item.to).toBeTruthy()
      const allowed = route!.meta?.roles
      if (allowed) for (const role of item.roles) expect(allowed, `${item.to} for ${role}`).toContain(role)
    }
  })
})
