import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))

import { api } from '@/lib/api'
import Addresses from '@/pages/Addresses.vue'
import OrderDetail from '@/pages/OrderDetail.vue'
import Orders from '@/pages/Orders.vue'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const status = (value, label) => ({ value, label })

const summary = (n, overrides = {}) => ({
  id: `O${n}`,
  order_number: `ORD-2026-0000${n}`,
  status: status('PENDING_PAYMENT', 'Waiting for payment'),
  payment_status: status('PENDING', 'Awaiting payment'),
  total: money(101500),
  preview: ['Test GPU', 'Test SSD'],
  more: 0,
  placed_at: '2026-10-12T08:00:00+00:00',
  ...overrides,
})

const fullOrder = (overrides = {}) => ({
  id: 'O1',
  order_number: 'ORD-2026-00001',
  status: status('PENDING_PAYMENT', 'Waiting for payment'),
  payment_status: status('PENDING', 'Awaiting payment'),
  subtotal: money(100000),
  discount_total: money(0),
  shipping_total: money(1500),
  total: money(101500),
  coupon_code: null,
  shipping_address: {
    name: 'Aisyah',
    phone: '012',
    line1: '12 Jalan A',
    line2: null,
    city: 'PJ',
    state: 'Selangor',
    postcode: '47810',
    country: 'MY',
  },
  notes: null,
  lines: [
    {
      kind: 'product',
      name: 'Test GPU',
      sku: 'GPU-1',
      quantity: 1,
      unit_price: money(100000),
      line_total: money(100000),
    },
  ],
  can_cancel: true,
  expires_at: '2026-10-12T08:30:00+00:00',
  cancelled_at: null,
  placed_at: '2026-10-12T08:00:00+00:00',
  ...overrides,
})

let wrapper
async function mountPage(component, path, routePath) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: routePath, name: 'page', component },
      { path: '/orders', name: 'orders', component: { template: '<div />' } },
      { path: '/orders/:id', name: 'order', component: { template: '<div />' } },
      { path: '/products', name: 'products', component: { template: '<div />' } },
    ],
  })
  router.push(path)
  await router.isReady()
  wrapper = mount(component, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return router
}

const button = (label) => wrapper.findAll('button').find((b) => b.text() === label)
const dialogButton = (label) =>
  [...document.body.querySelectorAll('[role=dialog] button')].find((b) => b.textContent.trim() === label)

describe('Orders page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => {
    wrapper?.unmount()
    document.body.innerHTML = ''
  })

  it('lists orders with both statuses, a preview of what was ordered, and the total', async () => {
    api.get.mockResolvedValue({
      data: {
        data: [summary(1), summary(2, { status: status('CANCELLED', 'Cancelled'), more: 2 })],
        meta: { current_page: 1, last_page: 1, total: 2 },
      },
    })
    await mountPage(Orders, '/orders', '/orders')

    expect(wrapper.text()).toContain('ORD-2026-00001')
    expect(wrapper.text()).toContain('Waiting for payment')
    expect(wrapper.text()).toContain('Awaiting payment')
    expect(wrapper.text()).toContain('Test GPU · Test SSD and 2 more')
    expect(wrapper.text()).toContain('RM 1015.00')
    expect(wrapper.text()).toContain('Cancelled')
  })

  it('shows an empty state with a way to start shopping', async () => {
    api.get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } })
    await mountPage(Orders, '/orders', '/orders')

    expect(wrapper.text()).toContain('No orders yet')
    expect(wrapper.text()).toContain('Start shopping')
  })

  it('shows an error with a retry', async () => {
    api.get.mockRejectedValueOnce({ message: 'Unable to reach the server.' })
    await mountPage(Orders, '/orders', '/orders')
    expect(wrapper.text()).toContain("We couldn't load your orders")

    api.get.mockResolvedValueOnce({ data: { data: [summary(1)], meta: { current_page: 1, last_page: 1, total: 1 } } })
    await button('Try again').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('ORD-2026-00001')
  })

  it('loads another page when paging', async () => {
    api.get.mockResolvedValue({ data: { data: [summary(1)], meta: { current_page: 1, last_page: 3, total: 25 } } })
    await mountPage(Orders, '/orders', '/orders')

    await wrapper
      .findAll('button')
      .find((b) => b.text() === '2')
      .trigger('click')

    expect(api.get).toHaveBeenLastCalledWith('/api/orders', { params: { page: 2 } })
  })
})

describe('Order detail page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => {
    wrapper?.unmount()
    document.body.innerHTML = ''
  })

  it('shows the whole order: statuses, lines, totals and the delivery address', async () => {
    api.get.mockResolvedValue({
      data: { data: fullOrder({ coupon_code: 'WELCOME10', discount_total: money(10000), total: money(91500) }) },
    })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    expect(wrapper.text()).toContain('ORD-2026-00001')
    expect(wrapper.text()).toContain('Waiting for payment')
    expect(wrapper.text()).toContain('Test GPU')
    expect(wrapper.text()).toContain('(WELCOME10)')
    expect(wrapper.find('[data-testid=total]').text()).toBe('RM 915.00')
    expect(wrapper.text()).toContain('47810 PJ, Selangor')
    expect(wrapper.text()).toContain('Your items are reserved until')
  })

  it('cancels an unpaid order after confirmation', async () => {
    api.get.mockResolvedValue({ data: { data: fullOrder() } })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')
    api.post.mockResolvedValue({
      data: {
        data: fullOrder({
          status: status('CANCELLED', 'Cancelled'),
          payment_status: status('CANCELLED', 'Cancelled'),
          can_cancel: false,
          expires_at: null,
          cancelled_at: '2026-10-12T08:10:00+00:00',
        }),
      },
    })

    await button('Cancel order').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[role=dialog]').textContent).toContain('ORD-2026-00001 will be cancelled')
    dialogButton('Cancel order').click()
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/orders/O1/cancel')
    expect(wrapper.text()).toContain('This order was cancelled')
    expect(wrapper.text()).toContain('Nothing was charged')
    expect(button('Cancel order')).toBeUndefined()
  })

  it('offers no cancel button once the order cannot be cancelled', async () => {
    api.get.mockResolvedValue({
      data: {
        data: fullOrder({
          status: status('PAID', 'Paid'),
          payment_status: status('PAID', 'Paid'),
          can_cancel: false,
          expires_at: null,
        }),
      },
    })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    expect(button('Cancel order')).toBeUndefined()
    expect(wrapper.text()).not.toContain('Your items are reserved')
  })

  it("shows the server's reason if cancelling is refused", async () => {
    api.get.mockResolvedValue({ data: { data: fullOrder() } })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')
    api.post.mockRejectedValue({ message: 'invalid', errors: { order: ['This order can no longer be cancelled.'] } })

    await button('Cancel order').trigger('click')
    await flushPromises()
    dialogButton('Cancel order').click()
    await flushPromises()

    expect(document.body.querySelector('[role=dialog]').textContent).toContain('This order can no longer be cancelled.')
  })

  it('says so when the order is not on this account', async () => {
    api.get.mockRejectedValue({ response: { status: 404 }, message: 'Resource not found.' })
    await mountPage(OrderDetail, '/orders/NOPE', '/orders/:id')

    expect(wrapper.text()).toContain('Order not found')
  })
})

describe('Addresses page', () => {
  const address = (id, extra = {}) => ({
    id,
    label: 'Home',
    recipient_name: `Person ${id}`,
    phone: '012',
    line1: `${id} Jalan A`,
    line2: null,
    city: 'PJ',
    state: 'Selangor',
    postcode: '47810',
    is_default: id === 1,
    ...extra,
  })

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    api.get.mockResolvedValue({ data: { data: [address(1), address(2)] } })
  })
  afterEach(() => {
    wrapper?.unmount()
    document.body.innerHTML = ''
  })

  it('lists the saved addresses with the default marked', async () => {
    await mountPage(Addresses, '/addresses', '/addresses')

    expect(wrapper.text()).toContain('Person 1')
    expect(wrapper.text()).toContain('Person 2')
    expect(wrapper.text()).toContain('Default')
    expect(wrapper.findAll('button').filter((b) => b.text() === 'Make default')).toHaveLength(1) // only the non-default one
  })

  it('makes another address the default', async () => {
    await mountPage(Addresses, '/addresses', '/addresses')
    api.put.mockResolvedValue({ data: { data: address(2) } })

    await button('Make default').trigger('click')
    await flushPromises()

    expect(api.put).toHaveBeenCalledWith('/api/addresses/2', { is_default: true })
  })

  it("shows the server's validation errors when saving fails, and keeps the dialog open", async () => {
    await mountPage(Addresses, '/addresses', '/addresses')
    api.post.mockRejectedValue({ message: 'invalid', errors: { postcode: ['The postcode must be 5 digits.'] } })

    await button('Add address').trigger('click')
    await flushPromises()
    const form = document.body.querySelector('[role=dialog] form')
    form.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(document.body.querySelector('[role=dialog]').textContent).toContain('The postcode must be 5 digits.')
  })

  it('deletes an address after confirmation', async () => {
    await mountPage(Addresses, '/addresses', '/addresses')
    api.delete.mockResolvedValue({})

    await wrapper
      .findAll('button')
      .filter((b) => b.text() === 'Delete')[1]
      .trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[role=dialog]').textContent).toContain('Your past orders are not affected.')
    dialogButton('Delete').click()
    await flushPromises()

    expect(api.delete).toHaveBeenCalledWith('/api/addresses/2')
  })

  it('shows an empty state with an add button', async () => {
    api.get.mockResolvedValue({ data: { data: [] } })
    await mountPage(Addresses, '/addresses', '/addresses')

    expect(wrapper.text()).toContain('No saved addresses')
  })
})
