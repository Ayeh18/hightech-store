import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import { api } from '@/lib/api'
import OrderDetail from '@/pages/admin/OrderDetail.vue'
import Orders from '@/pages/admin/Orders.vue'
import Refunds from '@/pages/admin/Refunds.vue'
import {
  STAFF_PERMISSIONS,
  at,
  button,
  cleanup,
  dialog,
  dialogButton,
  money,
  mountAdmin,
  signIn,
  status,
  submitDialog,
  typeInDialog,
} from './adminFixtures'

const summary = (n, overrides = {}) => ({
  id: `O${n}`,
  order_number: `ORD-2026-0000${n}`,
  customer: { name: `Customer ${n}`, email: `c${n}@x.test` },
  status: status('PAID', 'Paid'),
  payment_status: status('PAID', 'Paid'),
  total: money(101500),
  items_count: 2,
  placed_at: at(20),
  paid_at: at(20),
  ...overrides,
})

const list = (rows, meta = {}) => ({
  data: { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length, ...meta } },
})

const payment = (overrides = {}) => ({
  id: 'P1',
  status: status('PAID', 'Paid'),
  provider: 'fake',
  provider_reference: 'fakepay_abc',
  amount: money(101500),
  refunded_amount: money(0),
  refundable: money(101500),
  method: 'fakepay',
  requires_refund: false,
  failure_reason: null,
  paid_at: at(20),
  created_at: at(20),
  ...overrides,
})

const refund = (value = 'REQUESTED', overrides = {}) => ({
  id: 'R1',
  status: status(
    value,
    { REQUESTED: 'Waiting for review', SUCCEEDED: 'Refunded', REJECTED: 'Declined', FAILED: 'Refund problem' }[value],
  ),
  reason: status('customer_request', 'Customer request'),
  amount: money(101500),
  note: 'Wrong colour',
  review_note: null,
  requested_at: at(21),
  refunded_at: null,
  failure_reason: null,
  restock: false,
  payment_id: 'P1',
  ...overrides,
})

const detail = (overrides = {}) => ({
  id: 'O1',
  order_number: 'ORD-2026-00001',
  status: status('PAID', 'Paid'),
  payment_status: status('PAID', 'Paid'),
  customer: { id: 5, name: 'Aisyah Rahman', email: 'aisyah@x.test' },
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
  payments: [payment()],
  refunds: [],
  shipment: null,
  refund_pending: false,
  can_pay: false,
  timeline: [
    { status: status('PENDING_PAYMENT', 'Waiting for payment'), at: at(20, 8) },
    { status: status('PAID', 'Paid'), at: at(20, 9) },
  ],
  placed_at: at(20, 8),
  paid_at: at(20, 9),
  ...overrides,
})

beforeEach(() => {
  vi.clearAllMocks()
  signIn()
})
afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

describe('the orders list', () => {
  it('shows every order with its customer, both statuses and the total', async () => {
    api.get.mockResolvedValue(
      list([
        summary(1),
        summary(2, {
          status: status('PENDING_PAYMENT', 'Waiting for payment'),
          payment_status: status('PENDING', 'Awaiting payment'),
        }),
      ]),
    )
    const { wrapper } = await mountAdmin(Orders, '/admin/orders')

    const rows = wrapper.findAll('[data-testid=orders-table] tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('ORD-2026-00001')
    expect(rows[0].text()).toContain('Customer 1')
    expect(rows[0].text()).toContain('c1@x.test')
    expect(rows[0].text()).toContain('RM 1,015.00')
    expect(rows[0].find('a').attributes('href')).toBe('/admin/orders/O1')
    expect(rows[1].text()).toContain('Waiting for payment')
    expect(wrapper.text()).toContain('2 orders')
  })

  it('can be opened already filtered, from a link such as "pending payments"', async () => {
    api.get.mockResolvedValue(list([]))
    await mountAdmin(Orders, '/admin/orders?status=PENDING_PAYMENT', '/admin/orders')

    expect(api.get).toHaveBeenCalledWith('/api/admin/orders', { params: { status: 'PENDING_PAYMENT', page: 1 } })
  })

  it('searches once typing pauses, not on every key, and starts again from page 1', async () => {
    vi.useFakeTimers()
    api.get.mockResolvedValue(list([summary(1)], { current_page: 3, last_page: 5, total: 90 }))
    const { wrapper } = await mountAdmin(Orders, '/admin/orders')
    api.get.mockClear()

    const box = wrapper.find('input')
    await box.setValue('s')
    await box.setValue('si')
    await box.setValue('siti')
    expect(api.get).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(300)

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(api.get).toHaveBeenCalledWith('/api/admin/orders', { params: { q: 'siti', page: 1 } })
  })

  it('filters by status', async () => {
    vi.useFakeTimers()
    api.get.mockResolvedValue(list([summary(1)]))
    const { wrapper } = await mountAdmin(Orders, '/admin/orders')
    api.get.mockClear()

    await wrapper.findAll('select')[0].setValue('SHIPPED')
    await vi.advanceTimersByTimeAsync(300)

    expect(api.get).toHaveBeenCalledWith('/api/admin/orders', { params: { status: 'SHIPPED', page: 1 } })
  })

  it('moves between pages, keeping the filters', async () => {
    api.get.mockResolvedValue(list([summary(1)], { current_page: 1, last_page: 3, total: 50 }))
    const { wrapper } = await mountAdmin(Orders, '/admin/orders?status=PAID', '/admin/orders')
    api.get.mockClear()

    await wrapper
      .findAll('nav[aria-label=Pagination] button')
      .find((b) => b.text() === '2')
      .trigger('click')
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/api/admin/orders', { params: { status: 'PAID', page: 2 } })
  })

  it('explains an empty result and offers to clear the filters', async () => {
    api.get.mockResolvedValue(list([]))
    const { wrapper } = await mountAdmin(Orders, '/admin/orders?q=nobody', '/admin/orders')

    expect(wrapper.text()).toContain('No orders match')
    expect(button(wrapper, 'Clear filters')).toBeDefined()
  })

  it('shows the error when it cannot load', async () => {
    api.get.mockRejectedValue({ message: 'The server had a problem.' })
    const { wrapper } = await mountAdmin(Orders, '/admin/orders')

    expect(wrapper.text()).toContain('The server had a problem.')
  })
})

describe('one order', () => {
  it('shows what staff need: items, customer, payments, totals and the timeline', async () => {
    api.get.mockResolvedValue({ data: { data: detail() } })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    expect(api.get).toHaveBeenCalledWith('/api/admin/orders/O1')
    expect(wrapper.text()).toContain('ORD-2026-00001')
    expect(wrapper.text()).toContain('Test GPU')
    expect(wrapper.text()).toContain('aisyah@x.test')
    expect(wrapper.find('[data-testid=payment-row]').text()).toContain('RM 1,015.00')
    expect(wrapper.find('[data-testid=timeline]').text()).toContain('Paid')
  })

  it('offers exactly the next fulfilment step, and sends it', async () => {
    const steps = [
      ['PAID', 'Start preparing', 'status', { status: 'PROCESSING' }],
      ['PROCESSING', 'Mark as packed', 'status', { status: 'PACKED' }],
      ['SHIPPED', 'Mark as delivered', 'shipment/delivered', {}],
    ]

    for (const [current, label, path, body] of steps) {
      api.get.mockResolvedValue({ data: { data: detail({ status: status(current, current) }) } })
      api.post.mockResolvedValue({ data: { data: detail({ status: status('NEXT', 'Next') }) } })
      const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

      expect(button(wrapper, label)).toBeDefined()
      await button(wrapper, label).trigger('click')
      await flushPromises()

      expect(api.post).toHaveBeenLastCalledWith(`/api/admin/orders/O1/${path}`, body)
      expect(wrapper.text()).toContain('Next') // the page shows the order the server sent back
      cleanup()
    }
  })

  it('says there is nothing to do once an order is finished', async () => {
    api.get.mockResolvedValue({ data: { data: detail({ status: status('DELIVERED', 'Delivered') }) } })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    expect(wrapper.find('[data-testid=actions]').text()).toContain('Nothing to do')
    expect(button(wrapper, 'Mark as delivered')).toBeUndefined()
  })

  it('ships with a carrier and tracking number, which must be filled in', async () => {
    api.get.mockResolvedValue({ data: { data: detail({ status: status('PACKED', 'Packed') }) } })
    api.post.mockResolvedValue({
      data: {
        data: detail({
          status: status('SHIPPED', 'Shipped'),
          shipment: { carrier: 'Pos Laju', tracking_number: 'EP123', shipped_at: at(22), delivered_at: null },
        }),
      },
    })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    await button(wrapper, 'Ship…').trigger('click')
    await flushPromises()
    expect(dialogButton('Mark as shipped').disabled).toBe(true)

    await typeInDialog('Carrier', 'Pos Laju')
    await typeInDialog('Tracking number', ' EP123 ')
    expect(dialogButton('Mark as shipped').disabled).toBe(false)
    await submitDialog()

    expect(api.post).toHaveBeenCalledWith('/api/admin/orders/O1/shipment', {
      carrier: 'Pos Laju',
      tracking_number: 'EP123',
    })
    expect(dialog()).toBeNull()
    expect(wrapper.find('[data-testid=shipment]').text()).toContain('EP123')
  })

  it('takes a typed carrier name when "Other" is chosen', async () => {
    api.get.mockResolvedValue({ data: { data: detail({ status: status('PACKED', 'Packed') }) } })
    api.post.mockResolvedValue({ data: { data: detail({ status: status('SHIPPED', 'Shipped') }) } })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    await button(wrapper, 'Ship…').trigger('click')
    await flushPromises()
    await typeInDialog('Carrier', 'Other')
    await typeInDialog('Carrier name', 'Local courier')
    await typeInDialog('Tracking number', 'ABC1')
    await submitDialog()

    expect(api.post).toHaveBeenCalledWith('/api/admin/orders/O1/shipment', {
      carrier: 'Local courier',
      tracking_number: 'ABC1',
    })
  })

  it("shows the server's reason when a step is refused", async () => {
    api.get.mockResolvedValue({ data: { data: detail() } })
    api.post.mockRejectedValue({
      message: 'x',
      errors: { order: ['An order that is "Refund requested" cannot move it to Being prepared.'] },
    })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    await button(wrapper, 'Start preparing').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('cannot move it to Being prepared')
  })

  it('cancels and refunds after a confirmation that says how much', async () => {
    api.get.mockResolvedValue({ data: { data: detail() } })
    api.post.mockResolvedValue({
      data: { data: refund('SUCCEEDED', { reason: status('order_cancelled', 'Order cancelled') }) },
    })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    await button(wrapper, 'Cancel and refund…').trigger('click')
    await flushPromises()
    expect(dialog().textContent).toContain('RM 1,015.00')
    await typeInDialog('Note', 'Out of stock at the supplier')
    await submitDialog()

    expect(api.post).toHaveBeenCalledWith('/api/admin/orders/O1/cancel-and-refund', {
      note: 'Out of stock at the supplier',
    })
    expect(api.get).toHaveBeenCalledTimes(2) // the order is read again to show what it is now
    expect(dialog()).toBeNull()
  })

  it('reports a refund the payment company refused, instead of pretending it worked', async () => {
    api.get.mockResolvedValue({ data: { data: detail() } })
    api.post.mockResolvedValue({ data: { data: refund('FAILED', { failure_reason: 'FakePay: the bank said no.' }) } })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    await button(wrapper, 'Cancel and refund…').trigger('click')
    await flushPromises()
    await submitDialog()

    expect(wrapper.text()).toContain('did not complete the refund: FakePay: the bank said no.')
  })

  it('only offers cancelling while the order has not shipped', async () => {
    for (const [current, expected] of [
      ['PAID', true],
      ['PROCESSING', true],
      ['PACKED', true],
      ['SHIPPED', false],
      ['DELIVERED', false],
    ]) {
      api.get.mockResolvedValue({ data: { data: detail({ status: status(current, current) }) } })
      const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

      expect(button(wrapper, 'Cancel and refund…') !== undefined).toBe(expected)
      cleanup()
    }
  })

  it('refunds an amount typed in ringgit, sent as sen', async () => {
    api.get.mockResolvedValue({ data: { data: detail() } })
    api.post.mockResolvedValue({ data: { data: refund('SUCCEEDED', { amount: money(2550) }) } })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    await button(wrapper, 'Refund an amount…').trigger('click')
    await flushPromises()
    await typeInDialog('Amount', '25.50')
    await typeInDialog('Reason', 'Late delivery')
    await submitDialog()

    expect(api.post).toHaveBeenCalledWith('/api/admin/payments/P1/refunds', {
      amount: 2550,
      reason: 'goodwill',
      note: 'Late delivery',
    })
  })

  it('does not send an amount that is not money', async () => {
    api.get.mockResolvedValue({ data: { data: detail() } })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    await button(wrapper, 'Refund an amount…').trigger('click')
    await flushPromises()
    for (const bad of ['abc', '12.345', '-5', '0']) {
      await typeInDialog('Amount', bad)
      await submitDialog()
      expect(dialog().textContent).toContain('Enter an amount in ringgit')
    }

    expect(api.post).not.toHaveBeenCalled()
  })

  it('hides every money action from staff who may not refund', async () => {
    signIn(STAFF_PERMISSIONS, ['staff'])
    api.get.mockResolvedValue({ data: { data: detail({ refunds: [refund('REQUESTED')] }) } })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    expect(button(wrapper, 'Start preparing')).toBeDefined()
    expect(button(wrapper, 'Cancel and refund…')).toBeUndefined()
    expect(button(wrapper, 'Refund an amount…')).toBeUndefined()
    expect(button(wrapper, 'Approve…')).toBeUndefined()
    expect(wrapper.text()).toContain('Wrong colour') // they can still read the request
  })

  it("does not offer a smaller refund while the customer's request is waiting", async () => {
    api.get.mockResolvedValue({
      data: {
        data: detail({ status: status('REFUND_REQUESTED', 'Refund requested'), refunds: [refund('REQUESTED')] }),
      },
    })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    expect(button(wrapper, 'Refund an amount…')).toBeUndefined()
    expect(button(wrapper, 'Approve…')).toBeDefined()
  })

  it('warns about money that arrived too late, and sends it back in full', async () => {
    api.get.mockResolvedValue({
      data: {
        data: detail({
          status: status('CANCELLED', 'Cancelled'),
          refund_pending: true,
          payments: [payment({ requires_refund: true })],
        }),
      },
    })
    api.post.mockResolvedValue({
      data: { data: refund('SUCCEEDED', { reason: status('late_payment', 'Late payment') }) },
    })
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')

    expect(wrapper.text()).toContain('arrived after this order was cancelled')
    await button(wrapper, 'Refund RM 1,015.00 in full').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/admin/payments/P1/refunds', { amount: 101500, reason: 'late_payment' })
  })

  it('says so when the order does not exist', async () => {
    api.get.mockRejectedValue(Object.assign(new Error('x'), { response: { status: 404 } }))
    const { wrapper } = await mountAdmin(OrderDetail, '/admin/orders/NOPE', '/admin/orders/:id')

    expect(wrapper.text()).toContain('Order not found')
  })
})

describe('deciding a refund request', () => {
  const open = async (overrides = {}) => {
    api.get.mockResolvedValue({
      data: {
        data: detail({
          status: status('REFUND_REQUESTED', 'Refund requested'),
          refunds: [refund('REQUESTED', overrides)],
        }),
      },
    })
    return mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')
  }

  it("shows the request with the customer's own words", async () => {
    const { wrapper } = await open()

    expect(wrapper.find('[data-testid=admin-refund]').text()).toContain('Customer: “Wrong colour”')
    expect(wrapper.find('[data-testid=admin-refund]').text()).toContain('RM 1,015.00')
  })

  it('approves, letting the system decide about the goods unless told otherwise', async () => {
    api.post.mockResolvedValue({ data: { data: refund('SUCCEEDED') } })
    const { wrapper } = await open()

    await button(wrapper, 'Approve…').trigger('click')
    await flushPromises()
    expect(dialog().textContent).toContain('RM 1,015.00')
    await dialogButton('Approve and refund').click()
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/admin/refunds/R1/approve', {})
    expect(dialog()).toBeNull()
  })

  it('approves with a clear yes or no about putting the goods back, and a note', async () => {
    api.post.mockResolvedValue({ data: { data: refund('SUCCEEDED') } })
    const { wrapper } = await open()

    await button(wrapper, 'Approve…').trigger('click')
    await flushPromises()
    await typeInDialog('Do the goods go back on the shelf?', 'false')
    await typeInDialog('Note', 'Customer keeps the box')
    await submitDialog()

    expect(api.post).toHaveBeenCalledWith('/api/admin/refunds/R1/approve', {
      restock: false,
      note: 'Customer keeps the box',
    })
  })

  it('declines only with a reason the customer will read', async () => {
    api.post.mockResolvedValue({ data: { data: refund('REJECTED', { review_note: 'It was used' }) } })
    const { wrapper } = await open()

    await button(wrapper, 'Decline…').trigger('click')
    await flushPromises()
    expect(dialogButton('Decline').disabled).toBe(true)
    await typeInDialog('Reason', 'It was used')
    expect(dialogButton('Decline').disabled).toBe(false)
    await submitDialog()

    expect(api.post).toHaveBeenCalledWith('/api/admin/refunds/R1/reject', { note: 'It was used' })
  })

  it('shows an approved refund the payment company refused, with a way to retry', async () => {
    api.post.mockResolvedValueOnce({
      data: { data: refund('FAILED', { failure_reason: 'FakePay: the bank said no.' }) },
    })
    const { wrapper } = await open()

    await button(wrapper, 'Approve…').trigger('click')
    await flushPromises()
    await submitDialog()
    expect(wrapper.text()).toContain('The payment company did not complete it: FakePay: the bank said no.')

    api.get.mockResolvedValue({
      data: { data: detail({ refunds: [refund('FAILED', { failure_reason: 'FakePay: the bank said no.' })] }) },
    })
    api.post.mockResolvedValueOnce({ data: { data: refund('SUCCEEDED') } })
    const { wrapper: again } = await mountAdmin(OrderDetail, '/admin/orders/O1', '/admin/orders/:id')
    expect(again.find('[data-testid=refund-failure]').text()).toContain('the bank said no')
    await button(again, 'Retry the refund').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenLastCalledWith('/api/admin/refunds/R1/retry', {})
  })

  it("keeps the dialog open and shows the server's reason when approving is refused", async () => {
    api.post.mockRejectedValue({
      message: 'x',
      errors: { refund: ['This refund request has already been dealt with.'] },
    })
    const { wrapper } = await open()

    await button(wrapper, 'Approve…').trigger('click')
    await flushPromises()
    await submitDialog()

    expect(dialog().textContent).toContain('already been dealt with')
  })
})

describe('the refund queue', () => {
  const late = {
    id: 'P9',
    status: status('PAID', 'Paid'),
    amount: money(80000),
    refundable: money(80000),
    paid_at: at(19),
    order: { id: 'O9', order_number: 'ORD-2026-00009', customer: { name: 'Budi', email: 'b@x.test' } },
  }
  const queued = {
    ...refund('REQUESTED'),
    order: {
      id: 'O1',
      order_number: 'ORD-2026-00001',
      status: status('REFUND_REQUESTED', 'Refund requested'),
      customer: { name: 'Aisyah', email: 'a@x.test' },
    },
  }

  beforeEach(() => {
    api.get.mockImplementation(async (url) => {
      if (url === '/api/admin/payments/needing-refund') return { data: { data: [late] } }
      return list([queued])
    })
  })

  it('opens on the requests waiting for a decision, each with its order and customer', async () => {
    const { wrapper } = await mountAdmin(Refunds, '/admin/refunds')

    expect(api.get).toHaveBeenCalledWith('/api/admin/refunds', { params: { status: 'REQUESTED', page: 1 } })
    const row = wrapper.find('[data-testid=refund-list] [data-testid=admin-refund]')
    expect(row.text()).toContain('ORD-2026-00001')
    expect(row.text()).toContain('Aisyah (a@x.test)')
    expect(row.text()).toContain('Wrong colour')
    expect(row.find('a').attributes('href')).toBe('/admin/orders/O1')
  })

  it('switches to another view of the queue', async () => {
    vi.useFakeTimers()
    const { wrapper } = await mountAdmin(Refunds, '/admin/refunds')
    api.get.mockClear()

    await wrapper.find('select').setValue('FAILED')
    await vi.advanceTimersByTimeAsync(300)

    expect(api.get).toHaveBeenCalledWith('/api/admin/refunds', { params: { status: 'FAILED', page: 1 } })
  })

  it('lists payments that arrived too late and sends them back in full', async () => {
    api.post.mockResolvedValue({ data: { data: refund('SUCCEEDED') } })
    const { wrapper } = await mountAdmin(Refunds, '/admin/refunds')

    const row = wrapper.find('[data-testid=late-list] li')
    expect(row.text()).toContain('ORD-2026-00009')
    expect(row.text()).toContain('RM 800.00')
    await button(wrapper, 'Refund in full').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/admin/payments/P9/refunds', { amount: 80000, reason: 'late_payment' })
    expect(api.get).toHaveBeenCalledWith('/api/admin/payments/needing-refund') // refreshed afterwards
  })

  it('says so when nothing is waiting', async () => {
    api.get.mockImplementation(async (url) =>
      url === '/api/admin/payments/needing-refund' ? { data: { data: [] } } : list([]),
    )
    const { wrapper } = await mountAdmin(Refunds, '/admin/refunds')

    expect(wrapper.text()).toContain('Nothing here')
    expect(wrapper.find('[data-testid=no-late]').text()).toBe('Nothing to send back.')
  })
})
