import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import { api } from '@/lib/api'
import App from '@/App.vue'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

const cartReply = {
  data: {
    data: {
      items: [{ id: 1 }],
      subtotal: { amount: 100, formatted: 'RM 1.00' },
      item_count: 4,
      checkout_ready: true,
      has_price_changes: false,
    },
  },
}

async function boot() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', name: 'home', component: { template: '<div>home</div>' } }],
  })
  router.push('/')
  await router.isReady()
  const wrapper = mount(App, {
    global: {
      plugins: [router],
      stubs: { AppLayout: { template: '<div><slot /></div>' }, AuthLayout: { template: '<div><slot /></div>' } },
    },
  })
  await flushPromises()
  return wrapper
}

describe('the cart follows the login', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    api.get.mockResolvedValue(cartReply)
  })

  it('does not ask for a cart while nobody is signed in', async () => {
    await boot()

    expect(api.get).not.toHaveBeenCalledWith('/api/cart')
  })

  it('loads the cart when a customer signs in and forgets it when they sign out', async () => {
    await boot()
    const auth = useAuthStore()
    const cart = useCartStore()

    auth.user = { id: 1, name: 'Tester', roles: [], permissions: [] }
    await flushPromises()
    expect(api.get).toHaveBeenCalledWith('/api/cart')
    expect(cart.itemCount).toBe(4)

    auth.user = null
    await flushPromises()
    expect(cart.itemCount).toBe(0) // the next person on this browser never sees it
    expect(cart.loaded).toBe(false)
  })
})
