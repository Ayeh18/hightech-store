import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ensureCsrfCookie, setUnauthorizedHandler } from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  // false until the first /me check finishes, so route guards can wait for it.
  const initialized = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const roles = computed(() => user.value?.roles ?? [])
  const permissions = computed(() => user.value?.permissions ?? [])

  // UI convenience only. The API re-checks permissions on every request.
  const hasRole = (role) => roles.value.includes(role)
  const can = (permission) => permissions.value.includes(permission)

  /** Restore the session after a page refresh. Safe to call many times. */
  async function init() {
    if (initialized.value) return
    try {
      const { data } = await api.get('/api/auth/me')
      user.value = data.data
    } catch {
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  async function login(credentials) {
    await ensureCsrfCookie()
    const { data } = await api.post('/api/auth/login', credentials)
    user.value = data.data
  }

  async function register(payload) {
    await ensureCsrfCookie()
    const { data } = await api.post('/api/auth/register', payload)
    user.value = data.data
  }

  async function logout() {
    try {
      await api.post('/api/auth/logout')
    } finally {
      user.value = null
    }
  }

  setUnauthorizedHandler(() => {
    user.value = null
  })

  return { user, initialized, isAuthenticated, roles, permissions, hasRole, can, init, login, register, logout }
})
