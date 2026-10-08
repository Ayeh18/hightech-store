import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  ensureCsrfCookie: vi.fn().mockResolvedValue({}),
  setUnauthorizedHandler: vi.fn(),
}))

import { api, ensureCsrfCookie, setUnauthorizedHandler } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const admin = { id: 1, name: 'Admin', email: 'admin@example.com', roles: ['admin'], permissions: ['admin.access'] }

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    localStorage.clear()
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

  describe('unexpected sign-outs', () => {
    it('tells the customer when a session they had is gone after a page refresh', async () => {
      api.get.mockResolvedValueOnce({ data: { data: admin } })
      await useAuthStore().init() // signed in: leaves a hint in this browser

      setActivePinia(createPinia()) // a fresh page load
      api.get.mockRejectedValueOnce({ response: { status: 401 } }) // e.g. the account no longer exists
      const auth = useAuthStore()
      await auth.init()

      expect(auth.isAuthenticated).toBe(false)
      expect(auth.sessionEnded).toBe(true)
    })

    it('stays quiet for a visitor who was never signed in', async () => {
      api.get.mockRejectedValueOnce({ response: { status: 401 } })
      const auth = useAuthStore()
      await auth.init()

      expect(auth.sessionEnded).toBe(false)
    })

    it('announces it when the server rejects a request while signed in', async () => {
      api.post.mockResolvedValueOnce({ data: { data: admin } })
      const auth = useAuthStore()
      await auth.login({})

      setUnauthorizedHandler.mock.calls.at(-1)[0]() // what the API client does on a 401

      expect(auth.isAuthenticated).toBe(false)
      expect(auth.sessionEnded).toBe(true)

      auth.dismissSessionNotice()
      expect(auth.sessionEnded).toBe(false)
    })

    it('does not call a deliberate logout an unexpected one, and clears the notice on the next login', async () => {
      api.post.mockResolvedValue({ data: { data: admin } })
      const auth = useAuthStore()
      await auth.login({})
      await auth.logout()
      expect(auth.sessionEnded).toBe(false)

      setUnauthorizedHandler.mock.calls.at(-1)[0]() // a stray 401 after logging out must not raise the banner
      expect(auth.sessionEnded).toBe(false)

      await auth.login({})
      expect(auth.sessionEnded).toBe(false)
      expect(auth.isAuthenticated).toBe(true)
    })

    it('keeps working when browser storage is blocked', async () => {
      const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new Error('blocked')
      })
      api.get.mockRejectedValueOnce({ response: { status: 401 } })
      const auth = useAuthStore()

      await expect(auth.init()).resolves.toBeUndefined()
      expect(auth.sessionEnded).toBe(false)
      spy.mockRestore()
    })
  })
})
