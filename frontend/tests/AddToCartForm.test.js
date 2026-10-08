import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const push = vi.fn()
vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="JSON.stringify(to)"><slot /></a>' },
  useRouter: () => ({ push }),
  useRoute: () => ({ fullPath: '/products/test-gpu' }),
}))
vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import AddToCartForm from '@/components/cart/AddToCartForm.vue'
import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const product = (overrides = {}) => ({ id: 42, name: 'Test GPU', in_stock: true, ...overrides })
const cartReply = {
  data: { data: { items: [], subtotal: { amount: 0 }, item_count: 2, checkout_ready: true, has_price_changes: false } },
}

describe('AddToCartForm', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('sends a guest to the login page and brings them back to this product afterwards', async () => {
    const wrapper = mount(AddToCartForm, { props: { product: product() } })
    expect(wrapper.text()).toContain('Log in to add to cart')

    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('Log in to add'))
      .trigger('click')

    expect(push).toHaveBeenCalledWith({ name: 'login', query: { redirect: '/products/test-gpu' } })
    expect(api.post).not.toHaveBeenCalled()
  })

  it('adds the chosen quantity for a signed-in customer and confirms it', async () => {
    useAuthStore().user = { id: 1, name: 'Tester', roles: [], permissions: [] }
    api.post.mockResolvedValue(cartReply)
    const wrapper = mount(AddToCartForm, { props: { product: product() } })

    await wrapper
      .findAll('button')
      .find((b) => b.attributes('aria-label')?.startsWith('Increase'))
      .trigger('click')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Add to cart')
      .trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/cart/products', { product_id: 42, quantity: 2 })
    expect(wrapper.text()).toContain('Added to your cart.')
    expect(wrapper.text()).toContain('View cart')
  })

  it("shows the server's reason when it refuses (for example, not enough stock)", async () => {
    useAuthStore().user = { id: 1, name: 'Tester', roles: [], permissions: [] }
    api.post.mockRejectedValue({
      message: 'invalid',
      errors: { quantity: ['Only 1 more of Test GPU can be added (3 in stock).'] },
    })
    const wrapper = mount(AddToCartForm, { props: { product: product() } })

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Add to cart')
      .trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Only 1 more of Test GPU can be added (3 in stock).')
    expect(wrapper.text()).not.toContain('Added to your cart.')
  })

  it('cannot be used for an out-of-stock product', () => {
    useAuthStore().user = { id: 1, name: 'Tester', roles: [], permissions: [] }
    const wrapper = mount(AddToCartForm, { props: { product: product({ in_stock: false }) } })

    const button = wrapper.findAll('button').find((b) => b.text() === 'Out of stock')
    expect(button.attributes('disabled')).toBeDefined()
  })
})
