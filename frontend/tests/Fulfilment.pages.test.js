import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))
vi.mock('@/lib/redirect', () => ({ redirectTo: vi.fn() }))

import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import OrderTimeline from '@/components/orders/OrderTimeline.vue'
import { api } from '@/lib/api'
import OrderDetail from '@/pages/OrderDetail.vue'
import { useOrderStore } from '@/stores/orders'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const status = (value, label) => ({ value, label })
const at = (day, hour = 9) => `2026-10-${String(day).padStart(2, '0')}T${String(hour).padStart(2, '0')}:00:00+00:00`

const refund = (value = 'REQUESTED', overrides = {}) => ({
  id: 'R1',
  status: status(
    value,
    {
      REQUESTED: 'Waiting for review',
      SUCCEEDED: 'Refunded',
      REJECTED: 'Declined',
      PROCESSING: 'Refunding',
      FAILED: 'Refund problem',
    }[value],
  ),
  reason: status('customer_request', 'Customer request'),
  amount: money(101500),
  note: 'It does not fit my case',
  review_note: null,
  requested_at: at(12),
  refunded_at: null,
  ...overrides,
})

const order = (overrides = {}) => ({
  id: 'O1',
  order_number: 'ORD-2026-00001',
  status: status('PAID', 'Paid'),
  payment_status: status('PAID', 'Paid'),
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
  can_cancel: false,
  can_pay: false,
  payment: null,
  refund_pending: false,
  can_request_refund: true,
  shipment: null,
  refunds: [],
  timeline: [
    { status: status('PENDING_PAYMENT', 'Waiting for payment'), at: at(12, 8) },
    { status: status('PAID', 'Paid'), at: at(12, 9) },
  ],
  expires_at: null,
  cancelled_at: null,
  paid_at: at(12, 9),
  placed_at: at(12, 8),
  ...overrides,
})

const shipped = (overrides = {}) =>
  order({
    status: status('SHIPPED', 'Shipped'),
    shipment: { carrier: 'J&T Express', tracking_number: 'JT123456789MY', shipped_at: at(14), delivered_at: null },
    timeline: [
      { status: status('PENDING_PAYMENT', 'Waiting for payment'), at: at(12, 8) },
      { status: status('PAID', 'Paid'), at: at(12, 9) },
      { status: status('PROCESSING', 'Being prepared'), at: at(13) },
      { status: status('PACKED', 'Packed'), at: at(13, 15) },
      { status: status('SHIPPED', 'Shipped'), at: at(14) },
    ],
    ...overrides,
  })

let wrapper
async function mountOrder() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/orders/:id', name: 'page', component: OrderDetail },
      { path: '/orders', name: 'orders', component: { template: '<div />' } },
    ],
  })
  router.push('/orders/O1')
  await router.isReady()
  wrapper = mount(OrderDetail, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
}
const button = (label) => wrapper.findAll('button').find((b) => b.text() === label)
const dialog = () => document.body.querySelector('[role=dialog]')

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})
afterEach(() => {
  wrapper?.unmount()
  document.body.innerHTML = ''
})

describe('order progress', () => {
  it('lists every step in order and marks the latest as current', async () => {
    api.get.mockResolvedValue({ data: { data: shipped() } })
    await mountOrder()

    const steps = wrapper.findAll('[data-testid=timeline] li').map((li) => li.text())

    expect(steps).toHaveLength(5)
    expect(steps[0]).toContain('Waiting for payment')
    expect(steps[4]).toContain('Shipped')
    expect(steps[4]).toContain('(current)')
    expect(steps[3]).not.toContain('(current)')
  })

  it('says where the order has got to, in plain words', async () => {
    const cases = [
      ['PAID', "We'll start preparing your order"],
      ['PROCESSING', "We're preparing your order"],
      ['PACKED', 'packed and ready to go'],
      ['SHIPPED', 'on its way'],
      ['DELIVERED', 'was delivered'],
    ]

    for (const [value, text] of cases) {
      api.get.mockResolvedValue({ data: { data: order({ status: status(value, value) }) } })
      await mountOrder()
      expect(wrapper.text()).toContain(text)
      wrapper.unmount()
    }
  })

  it('shows the carrier and tracking number once it has shipped', async () => {
    api.get.mockResolvedValue({ data: { data: shipped() } })
    await mountOrder()

    const card = wrapper.find('[data-testid=shipment]')
    expect(card.text()).toContain('J&T Express')
    expect(card.find('[data-testid=tracking-number]').text()).toBe('JT123456789MY')
    expect(card.text()).toContain('Shipped 14 October 2026')
    expect(card.text()).not.toContain('delivered')
  })

  it('adds the delivery date when it arrives', async () => {
    api.get.mockResolvedValue({
      data: {
        data: shipped({
          status: status('DELIVERED', 'Delivered'),
          shipment: { carrier: 'Pos Laju', tracking_number: 'EP1', shipped_at: at(14), delivered_at: at(16) },
        }),
      },
    })
    await mountOrder()

    expect(wrapper.find('[data-testid=shipment]').text()).toContain('delivered 16 October 2026')
  })

  it('shows no shipment card before it ships', async () => {
    api.get.mockResolvedValue({ data: { data: order() } })
    await mountOrder()

    expect(wrapper.find('[data-testid=shipment]').exists()).toBe(false)
  })
})

describe('asking for a refund', () => {
  it('offers the button only when the server says it is possible', async () => {
    api.get.mockResolvedValue({ data: { data: order({ can_request_refund: false }) } })
    await mountOrder()
    expect(button('Request a refund')).toBeUndefined()

    wrapper.unmount()
    api.get.mockResolvedValue({ data: { data: order({ can_request_refund: true }) } })
    await mountOrder()
    expect(button('Request a refund')).toBeDefined()
  })

  it('sends the reason, then shows the request as waiting and hides the button', async () => {
    api.get.mockResolvedValue({ data: { data: order() } })
    api.post.mockResolvedValue({
      data: {
        data: order({
          status: status('REFUND_REQUESTED', 'Refund requested'),
          can_request_refund: false,
          refunds: [refund('REQUESTED', { note: 'Wrong model' })],
        }),
      },
    })
    await mountOrder()
    const store = useOrderStore()
    store.loaded = true

    await button('Request a refund').trigger('click')
    await flushPromises()
    const send = [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Send request')
    expect(send.disabled).toBe(true) // a reason is needed first

    const note = dialog().querySelector('#refund-note')
    note.value = '  Wrong model  '
    note.dispatchEvent(new Event('input'))
    await flushPromises()
    expect(send.disabled).toBe(false)
    dialog()
      .querySelector('form')
      .dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/orders/O1/refund-request', { note: 'Wrong model' })
    expect(dialog()).toBeNull()
    expect(wrapper.find('[data-testid=refund-waiting]').text()).toContain('RM 1015.00')
    expect(button('Request a refund')).toBeUndefined()
    expect(wrapper.text()).toContain('Refund requested')
    expect(store.loaded).toBe(false) // the orders list refreshes next time
  })

  it("keeps the dialog open and shows the server's reason when it is refused", async () => {
    api.get.mockResolvedValue({ data: { data: order() } })
    api.post.mockRejectedValue({ message: 'x', errors: { order: ['The refund period for this order has ended.'] } })
    await mountOrder()

    await button('Request a refund').trigger('click')
    await flushPromises()
    const note = dialog().querySelector('#refund-note')
    note.value = 'Too late?'
    note.dispatchEvent(new Event('input'))
    await flushPromises()
    dialog()
      .querySelector('form')
      .dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    expect(dialog().textContent).toContain('The refund period for this order has ended.')
    expect(note.value).toBe('Too late?')
  })
})

describe('what we tell the customer about their refund', () => {
  const withRefund = (r, overrides = {}) =>
    api.get.mockResolvedValue({ data: { data: order({ can_request_refund: false, refunds: [r], ...overrides }) } })

  it('says when the money has been sent back', async () => {
    withRefund(refund('SUCCEEDED', { refunded_at: at(15) }), {
      status: status('REFUNDED', 'Refunded'),
      payment_status: status('REFUNDED', 'Refunded'),
    })
    await mountOrder()

    const notice = wrapper.find('[data-testid=refund-done]')
    expect(notice.text()).toContain('RM 1015.00 refunded on 15 October 2026')
    expect(notice.text()).toContain('original payment method')
    expect(wrapper.find('[data-testid=refund-card]').text()).toContain('Refunded')
  })

  it('explains a declined request, with our reason, and offers another try when allowed', async () => {
    withRefund(refund('REJECTED', { review_note: 'The item was used' }), { can_request_refund: true })
    await mountOrder()

    const notice = wrapper.find('[data-testid=refund-rejected]')
    expect(notice.text()).toContain("couldn't approve")
    expect(notice.text()).toContain('The item was used')
    expect(notice.text()).toContain('you can ask again')
    expect(button('Request a refund')).toBeDefined()
  })

  it.each(['PROCESSING', 'FAILED'])(
    'says only that a "%s" refund is being processed, never the technical reason',
    async (value) => {
      withRefund(refund(value, { failure_reason: 'FakePay: the bank said no.' }))
      await mountOrder()

      expect(wrapper.find('[data-testid=refund-processing]').text()).toContain('being processed')
      expect(wrapper.text()).not.toContain('the bank said no')
      expect(wrapper.find('[data-testid=refund-card]').text()).toContain('Refunding')
      expect(wrapper.find('[data-testid=refund-card]').text()).not.toContain('Refund problem')
    },
  )

  it("quotes the customer's own words and ours on each refund", async () => {
    withRefund(refund('REJECTED', { note: 'Changed my mind', review_note: 'Past the return window' }))
    await mountOrder()

    const card = wrapper.find('[data-testid=refund-card]').text()
    expect(card).toContain('You said: “Changed my mind”')
    expect(card).toContain('We said: “Past the return window”')
  })

  it('shows no refunds section when there are none', async () => {
    api.get.mockResolvedValue({ data: { data: order() } })
    await mountOrder()

    expect(wrapper.find('[data-testid=refund-card]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Refunds')
  })
})

describe('the timeline component', () => {
  it('renders nothing odd for a single step', () => {
    const w = mount(OrderTimeline, { props: { entries: [{ status: status('PAID', 'Paid'), at: at(12) }] } })

    expect(w.findAll('li')).toHaveLength(1)
    expect(w.text()).toContain('(current)')
  })
})

describe('status colours', () => {
  const tone = (value, kind) =>
    mount(OrderStatusBadge, { props: { status: status(value, value), kind } })
      .classes()
      .join(' ')

  it('treats "processing" as progress for an order but as "still waiting" for a payment', () => {
    expect(tone('PROCESSING', 'order')).toContain('text-success')
    expect(tone('PROCESSING', 'payment')).toContain('text-warning')
  })

  it('colours finished, good, bad and waiting states differently', () => {
    expect(tone('SHIPPED', 'order')).toContain('text-success')
    expect(tone('CANCELLED', 'order')).toContain('text-danger')
    expect(tone('REFUND_REQUESTED', 'order')).toContain('text-warning')
    expect(tone('REFUNDED', 'order')).toContain('text-muted') // finished: neither good nor bad news
    expect(tone('REFUND_PENDING', 'payment')).toContain('text-warning')
  })
})
