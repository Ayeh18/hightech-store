import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ensureCsrfCookie, setUnauthorizedHandler } from '@/lib/api'

// A tiny browser-side hint ("this browser was signed in"), so that if the server later says the session is gone
// we can TELL the customer instead of silently showing "Log in". It holds no credentials and grants no access.
const HINT_KEY = 'hightech.auth.signedin'
const hint = {
  get: () => {
    try {
      return localStorage.getItem(HINT_KEY) === '1'
    } catch {
      return false
    }
  },
  set: (on) => {
    try {
      on ? localStorage.setItem(HINT_KEY, '1') : localStorage.removeItem(HINT_KEY)
    } catch {
      /* storage unavailable: the notice is only a nicety */
    }
  },
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  // false until the first /me check finishes, so route guards can wait for it.
  const initialized = ref(false)
  // true when the server ended a session the customer did not end themselves (expired, account removed, ...).
  const sessionEnded = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const roles = computed(() => user.value?.roles ?? [])
  const permissions = computed(() => user.value?.permissions ?? [])

  // UI convenience only. The API re-checks permissions on every request.
  const hasRole = (role) => roles.value.includes(role)
  const can = (permission) => permissions.value.includes(permission)

  function signedIn(data) {
    user.value = data
    sessionEnded.value = false
    hint.set(true)
  }

  /** The server no longer recognises us. Only worth announcing if we believed we were signed in. */
  function sessionLost() {
    if (user.value !== null || hint.get()) sessionEnded.value = true
    user.value = null
    hint.set(false)
  }

  /** Restore the session after a page refresh. Safe to call many times. */
  async function init() {
    if (initialized.value) return
    try {
      const { data } = await api.get('/api/auth/me')
      signedIn(data.data)
    } catch {
      sessionLost()
    } finally {
      initialized.value = true
    }
  }

  async function login(credentials) {
    await ensureCsrfCookie()
    const { data } = await api.post('/api/auth/login', credentials)
    signedIn(data.data)
  }

  async function register(payload) {
    await ensureCsrfCookie()
    const { data } = await api.post('/api/auth/register', payload)
    signedIn(data.data)
  }

  /** The customer chose to log out, so this is never reported as an unexpected sign-out. */
  async function logout() {
    try {
      await api.post('/api/auth/logout')
    } finally {
      user.value = null
      sessionEnded.value = false
      hint.set(false)
    }
  }

  const dismissSessionNotice = () => {
    sessionEnded.value = false
  }

  setUnauthorizedHandler(sessionLost)

  return {
    user,
    initialized,
    sessionEnded,
    isAuthenticated,
    roles,
    permissions,
    hasRole,
    can,
    init,
    login,
    register,
    logout,
    dismissSessionNotice,
  }
})
