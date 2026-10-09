import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))

import { api } from '@/lib/api'
import Checkout from '@/pages/Checkout.vue'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const cartLine = (id, name, qty, unit) => ({
  id,
  kind: 'product',
  name,
  quantity: qty,
  unit_price: money(unit),
  line_total: money(unit * qty),
  components: [],
  issues: [],
})
const cart = (items) => ({
  data: {
    data: { items, subtotal: money(0), item_count: items.length, checkout_ready: true, has_price_changes: false },
  },
})
const address = (id, extra = {}) => ({
  id,
  label: 'Home',
  recipient_name: 'Aisyah',
  phone: '012',
  line1: `${id} Jalan A`,
  line2: null,
  city: 'PJ',
  state: 'Selangor',
  postcode: '47810',
  is_default: id === 1,
  ...extra,
})
const quote = (overrides = {}) => ({
  data: {
    data: {
      subtotal: money(200000),
      discount: money(0),
      shipping: money(1500),
      shipping_known: true,
      total: money(201500),
      free_shipping_over: money(300000),
      payment_window_minutes: 30,
      coupon: { code: null, applied: false, error: null },
      blockers: [],
      can_place: true,
      ...overrides,
    },
  },
})

let wrapper
async function open({ addresses = [address(1), address(2)], items = [cartLine(1, 'Test GPU', 2, 100000)] } = {}) {
  api.get.mockImplementation(async (url) => {
    if (url === '/api/cart') return cart(items)
    if (url === '/api/addresses') return { data: { data: addresses } }
    throw new Error(`unexpected GET ${url}`)
  })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/checkout', name: 'checkout', component: Checkout },
      { path: '/cart', name: 'cart', component: { template: '<div />' } },
      { path: '/products', name: 'products', component: { template: '<div />' } },
      { path: '/orders/:id', name: 'order', component: { template: '<div />' } },
    ],
  })
  router.push('/checkout')
  await router.isReady()
  wrapper = mount(Checkout, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return router
}

const button = (label) => wrapper.findAll('button').find((b) => b.text() === label)

describe('Checkout page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    api.post.mockImplementation(async (url) => {
      if (url === '/api/checkout/preview') return quote()
      throw new Error(`unexpected POST ${url}`)
    })
  })
  afterEach(() => {
    wrapper?.unmount()
    document.body.innerHTML = ''
  })

  it("loads the cart and addresses, picks the default address, and shows the server's totals", async () => {
    await open()

    expect(api.post).toHaveBeenCalledWith('/api/checkout/preview', { address_id: 1, coupon_code: null })
    expect(wrapper.text()).toContain('Test GPU')
    expect(wrapper.find('[data-testid=total]').text()).toBe('RM 2015.00')
    expect(wrapper.find('[data-testid=shipping]').text()).toBe('RM 15.00')
    expect(button('Place order').attributes('disabled')).toBeUndefined()
  })

  it('asks for an address first when there are none, and cannot place an order until one exists', async () => {
    api.post.mockResolvedValue(
      quote({
        shipping: null,
        shipping_known: false,
        total: money(200000),
        can_place: false,
        blockers: ['Choose a delivery address.'],
      }),
    )
    await open({ addresses: [] })

    expect(wrapper.text()).toContain('You have no saved addresses yet')
    expect(wrapper.text()).toContain('Choose a delivery address.')
    expect(wrapper.find('[data-testid=shipping]').text()).toBe('Choose an address')
    expect(button('Place order').attributes('disabled')).toBeDefined()
  })

  it('shows an empty state when there is nothing to check out', async () => {
    await open({ items: [] })

    expect(wrapper.text()).toContain('Your cart is empty')
    expect(wrapper.text()).not.toContain('Place order')
  })

  it('prices the order again when another address is chosen (shipping depends on the state)', async () => {
    await open()
    api.post.mockResolvedValue(quote({ shipping: money(3500), total: money(203500) }))

    await wrapper.findAll('[role=radio]')[1].trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenLastCalledWith('/api/checkout/preview', { address_id: 2, coupon_code: null })
    expect(wrapper.find('[data-testid=total]').text()).toBe('RM 2035.00')
  })

  it('applies a coupon and shows the discount the server calculated', async () => {
    await open()
    api.post.mockResolvedValue(
      quote({
        discount: money(20000),
        total: money(181500),
        coupon: { code: 'WELCOME10', applied: true, error: null },
      }),
    )

    await wrapper.find('#coupon-code').setValue('welcome10')
    await wrapper.find('#coupon-code').element.form.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(api.post).toHaveBeenLastCalledWith('/api/checkout/preview', { address_id: 1, coupon_code: 'welcome10' })
    expect(wrapper.text()).toContain('WELCOME10 applied')
    expect(wrapper.find('[data-testid=discount]').text()).toBe('−RM 200.00')
    expect(wrapper.find('[data-testid=total]').text()).toBe('RM 1815.00')
  })

  it('shows why a coupon was refused and keeps the order without it', async () => {
    await open()
    api.post.mockResolvedValue(
      quote({
        coupon: { code: null, applied: false, error: 'Spend at least RM 5,000.00 to use BIG.' },
        can_place: false,
      }),
    )

    await wrapper.find('#coupon-code').setValue('big')
    await wrapper.find('#coupon-code').element.form.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(wrapper.text()).toContain('Spend at least RM 5,000.00 to use BIG.')
    expect(button('Place order').attributes('disabled')).toBeDefined()
  })

  it('hints how close the customer is to free shipping', async () => {
    await open() // subtotal RM 2,000, threshold RM 3,000

    expect(wrapper.text()).toContain('Add RM 1,000.00 more to get free shipping.')
  })

  it('places the order and goes to it, sending a retry-safe key and no prices', async () => {
    const router = await open()
    api.post.mockImplementation(async (url) => {
      if (url === '/api/checkout') return { data: { data: { id: 'ORDER1', order_number: 'ORD-2026-00001' } } }
      return quote()
    })

    await button('Place order').trigger('click')
    await flushPromises()

    const call = api.post.mock.calls.find((c) => c[0] === '/api/checkout')
    expect(call[1]).toEqual({ address_id: 1, coupon_code: null, notes: null })
    expect(call[2].headers['Idempotency-Key']).toBeTruthy()
    expect(router.currentRoute.value.name).toBe('order')
    expect(router.currentRoute.value.params.id).toBe('ORDER1')
  })

  it('stays on checkout and explains when the server refuses the order', async () => {
    const router = await open()
    api.post.mockImplementation(async (url) => {
      if (url === '/api/checkout') throw { message: 'invalid', errors: { cart: ['Test GPU is out of stock.'] } }
      return quote()
    })

    await button('Place order').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('checkout')
    expect(wrapper.text()).toContain("We couldn't place your order")
    expect(wrapper.text()).toContain('Test GPU is out of stock.')
    // The page re-reads the cart and quote, so the customer sees the current situation.
    expect(api.get.mock.calls.filter((c) => c[0] === '/api/cart').length).toBeGreaterThan(1)
  })

  it('lets the customer add an address without leaving checkout, then delivers there', async () => {
    await open()
    api.post.mockImplementation(async (url) =>
      url === '/api/addresses' ? { data: { data: address(9, { is_default: false }) } } : quote(),
    )
    api.get.mockImplementation(async (url) =>
      url === '/api/addresses'
        ? { data: { data: [address(1), address(9, { is_default: false })] } }
        : cart([cartLine(1, 'Test GPU', 2, 100000)]),
    )

    await button('Add a new address').trigger('click')
    expect(wrapper.text()).toContain('Save and deliver here')
    const form = wrapper.find('form[novalidate]:not([role=search])')
    for (const [selector, value] of [
      ['input[autocomplete=name]', 'Aisyah'],
      ['input[autocomplete=tel]', '0123456789'],
      ['input[autocomplete=address-line1]', '9 Jalan B'],
      ['input[autocomplete=address-level2]', 'PJ'],
      ['input[autocomplete=postal-code]', '47810'],
    ]) {
      await form.find(selector).setValue(value)
    }
    await form.find('select').setValue('Selangor')
    await form.trigger('submit')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith(
      '/api/addresses',
      expect.objectContaining({ recipient_name: 'Aisyah', postcode: '47810' }),
    )
    expect(api.post).toHaveBeenLastCalledWith('/api/checkout/preview', { address_id: 9, coupon_code: null })
  })
})
