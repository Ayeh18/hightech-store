import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  ensureCsrfCookie: vi.fn().mockResolvedValue({}),
  setUnauthorizedHandler: vi.fn(),
}))

import { api, ensureCsrfCookie } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const admin = { id: 1, name: 'Admin', email: 'admin@example.com', roles: ['admin'], permissions: ['admin.access'] }

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('starts logged out', () => {
    const auth = useAuthStore()
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.can('admin.access')).toBe(false)
  })

  it('logs in after fetching the CSRF cookie and exposes roles and permissions', async () => {
    api.post.mockResolvedValue({ data: { data: admin } })
    const auth = useAuthStore()

    await auth.login({ email: 'admin@example.com', password: 'Password123' })

    expect(ensureCsrfCookie).toHaveBeenCalledOnce()
    expect(api.post).toHaveBeenCalledWith('/api/auth/login', { email: 'admin@example.com', password: 'Password123' })
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.hasRole('admin')).toBe(true)
    expect(auth.can('admin.access')).toBe(true)
    expect(auth.can('users.manage')).toBe(false)
  })

  it('does not set a user when login fails', async () => {
    api.post.mockRejectedValue({ response: { status: 422, data: { errors: { email: ['bad'] } } } })
    const auth = useAuthStore()

    await expect(auth.login({ email: 'x', password: 'y' })).rejects.toBeDefined()
    expect(auth.isAuthenticated).toBe(false)
  })

  it('restores the session via /me once, and treats 401 as logged out', async () => {
    api.get.mockResolvedValueOnce({ data: { data: admin } })
    const auth = useAuthStore()
    await auth.init()
    await auth.init()

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(auth.user.email).toBe('admin@example.com')

    setActivePinia(createPinia())
    api.get.mockRejectedValueOnce({ response: { status: 401 } })
    const guest = useAuthStore()
    await guest.init()
    expect(guest.isAuthenticated).toBe(false)
    expect(guest.initialized).toBe(true)
  })

  it('clears the user on logout even if the request fails', async () => {
    api.post.mockResolvedValueOnce({ data: { data: admin } })
    const auth = useAuthStore()
    await auth.login({})

    api.post.mockRejectedValueOnce(new Error('network'))
    await expect(auth.logout()).rejects.toThrow()
    expect(auth.isAuthenticated).toBe(false)
  })
})
