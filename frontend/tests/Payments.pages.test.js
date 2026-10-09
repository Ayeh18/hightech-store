import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))
vi.mock('@/lib/redirect', () => ({ redirectTo: vi.fn() }))

import { api } from '@/lib/api'
import { redirectTo } from '@/lib/redirect'
import FakeGateway from '@/pages/FakeGateway.vue'
import OrderDetail from '@/pages/OrderDetail.vue'
import { useOrderStore } from '@/stores/orders'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const status = (value, label) => ({ value, label })

const payment = (value = 'PENDING', overrides = {}) => ({
  id: 'P1',
  status: status(value, value),
  provider: 'fake',
  amount: money(101500),
  method: null,
  checkout_url: value === 'PENDING' ? 'http://localhost:5173/fake-gateway/fakepay_abc' : null,
  failure_reason: null,
  paid_at: null,
  created_at: '2026-10-12T08:01:00+00:00',
  ...overrides,
})

const order = (overrides = {}) => ({
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
  can_pay: true,
  payment: null,
  refund_pending: false,
  can_request_refund: false,
  shipment: null,
  refunds: [],
  timeline: [{ status: { value: 'PENDING_PAYMENT', label: 'Waiting for payment' }, at: '2026-10-12T08:00:00+00:00' }],
  expires_at: '2026-10-12T08:30:00+00:00',
  cancelled_at: null,
  paid_at: null,
  placed_at: '2026-10-12T08:00:00+00:00',
  ...overrides,
})

const paidOrder = (overrides = {}) =>
  order({
    status: status('PAID', 'Paid'),
    payment_status: status('PAID', 'Paid'),
    can_pay: false,
    can_cancel: false,
    expires_at: null,
    paid_at: '2026-10-12T08:05:00+00:00',
    payment: payment('PAID', { paid_at: '2026-10-12T08:05:00+00:00' }),
    ...overrides,
  })

let wrapper
async function mountPage(component, path, routePath) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: routePath, name: 'page', component },
      { path: '/orders', name: 'orders', component: { template: '<div />' } },
    ],
  })
  router.push(path)
  await router.isReady()
  wrapper = mount(component, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
}

const button = (label) => wrapper.findAll('button').find((b) => b.text() === label)

describe('paying for an order', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.useFakeTimers()
  })
  afterEach(() => {
    wrapper?.unmount()
    document.body.innerHTML = ''
    vi.useRealTimers()
  })

  it('offers "Pay" with the total, and sends the customer to the payment page', async () => {
    api.get.mockResolvedValue({ data: { data: order() } })
    api.post.mockResolvedValue({ data: { data: payment('PENDING') } })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    await button('Pay RM 1015.00').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/orders/O1/payments') // no amount: the server decides it
    expect(redirectTo).toHaveBeenCalledWith('http://localhost:5173/fake-gateway/fakepay_abc')
  })

  it('shows the reason and stays put when the payment cannot be started', async () => {
    api.get.mockResolvedValue({ data: { data: order() } })
    api.post.mockRejectedValue({
      message: 'Refused',
      errors: { order: ['The payment window for this order has passed.'] },
    })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    await button('Pay RM 1015.00').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('The payment window for this order has passed.')
    expect(redirectTo).not.toHaveBeenCalled()
    expect(button('Pay RM 1015.00').attributes('disabled')).toBeUndefined() // can try again
  })

  it('reloads the order when the server refuses (422), so the page shows what is true now', async () => {
    api.get.mockResolvedValueOnce({ data: { data: order() } })
    api.get.mockResolvedValueOnce({
      data: { data: order({ status: status('CANCELLED', 'Cancelled'), can_pay: false, can_cancel: false }) },
    })
    api.post.mockRejectedValue(
      Object.assign(new Error('x'), { response: { status: 422 }, errors: { order: ['This order cannot be paid.'] } }),
    )
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    await button('Pay RM 1015.00').trigger('click')
    await flushPromises()

    expect(api.get).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('This order was cancelled')
    expect(button('Pay RM 1015.00')).toBeUndefined()
  })

  it('says "Continue to payment" when a payment page is already waiting', async () => {
    api.get.mockResolvedValue({ data: { data: order({ payment: payment('PENDING') }) } })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    expect(button('Continue to payment')).toBeDefined()
    expect(wrapper.text()).toContain('Waiting for payment')
  })

  it('explains a failed payment and offers another try while the items are still reserved', async () => {
    api.get.mockResolvedValue({
      data: {
        data: order({
          payment_status: status('FAILED', 'Payment failed'),
          payment: payment('FAILED', { failure_reason: 'Declined (simulated)' }),
        }),
      },
    })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    expect(wrapper.text()).toContain("Your payment didn't go through")
    expect(wrapper.text()).toContain('Declined (simulated)')
    expect(wrapper.text()).toContain('still reserved until')
    expect(button('Try paying again')).toBeDefined()
  })

  it('thanks the customer and hides the pay button once the order is paid', async () => {
    api.get.mockResolvedValue({ data: { data: paidOrder() } })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    expect(wrapper.text()).toContain('Payment received on')
    expect(wrapper.text()).toContain("We'll start preparing your order")
    expect(wrapper.findAll('button').some((b) => b.text().startsWith('Pay'))).toBe(false)
  })

  it('promises a refund when the payment arrived after the order was cancelled', async () => {
    api.get.mockResolvedValue({
      data: {
        data: order({
          status: status('CANCELLED', 'Cancelled'),
          can_pay: false,
          can_cancel: false,
          refund_pending: true,
          cancelled_at: '2026-10-12T08:31:00+00:00',
          payment: payment('PAID'),
        }),
      },
    })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    expect(wrapper.text()).toContain('We received your payment after this order was cancelled')
    expect(wrapper.text()).toContain('refunded')
    expect(wrapper.text()).not.toContain('Nothing was charged')
    expect(wrapper.text()).toContain('Payment: Refund pending') // not "Payment: Cancelled": the money did arrive
  })

  it('still says nothing was charged for an ordinary cancelled order', async () => {
    api.get.mockResolvedValue({
      data: {
        data: order({
          status: status('CANCELLED', 'Cancelled'),
          can_pay: false,
          can_cancel: false,
          cancelled_at: '2026-10-12T08:31:00+00:00',
        }),
      },
    })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    expect(wrapper.text()).toContain('Nothing was charged')
  })
})

describe('coming back from the payment page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.useFakeTimers()
  })
  afterEach(() => {
    wrapper?.unmount()
    document.body.innerHTML = ''
    vi.useRealTimers()
  })

  const returning = order({ payment: payment('PENDING') })

  it('waits for the server to confirm, checking every two seconds, then shows the payment', async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: returning } })
      .mockResolvedValueOnce({ data: { data: returning } })
      .mockResolvedValue({ data: { data: paidOrder() } })
    await mountPage(OrderDetail, '/orders/O1?payment=return', '/orders/:id')

    expect(wrapper.text()).toContain('Confirming your payment')
    expect(button('Pay RM 1015.00') ?? button('Continue to payment')).toBeDefined()
    expect((button('Continue to payment') ?? button('Pay RM 1015.00')).attributes('disabled')).toBeDefined() // cannot pay twice

    await vi.advanceTimersByTimeAsync(2000)
    expect(api.get).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Confirming your payment')

    await vi.advanceTimersByTimeAsync(2000)
    expect(wrapper.text()).toContain('Payment received')
    expect(wrapper.text()).not.toContain('Confirming your payment')

    // Settled: it stops asking.
    await vi.advanceTimersByTimeAsync(20000)
    expect(api.get).toHaveBeenCalledTimes(3)
  })

  it('tells the orders list to refresh when the order changes', async () => {
    api.get.mockResolvedValueOnce({ data: { data: returning } }).mockResolvedValue({ data: { data: paidOrder() } })
    await mountPage(OrderDetail, '/orders/O1?payment=return', '/orders/:id')
    const store = useOrderStore()
    store.loaded = true

    await vi.advanceTimersByTimeAsync(2000)

    expect(store.loaded).toBe(false)
  })

  it('gives up politely after about 40 seconds and says not to pay again', async () => {
    api.get.mockResolvedValue({ data: { data: returning } })
    await mountPage(OrderDetail, '/orders/O1?payment=return', '/orders/:id')

    await vi.advanceTimersByTimeAsync(2000 * 20 + 500)

    expect(wrapper.text()).toContain("We haven't had an answer from the payment company yet")
    expect(wrapper.text()).toContain("please don't pay again")
    const calls = api.get.mock.calls.length
    await vi.advanceTimersByTimeAsync(20000)
    expect(api.get.mock.calls.length).toBe(calls)
  })

  it('keeps going if one check fails', async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: returning } })
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValue({ data: { data: paidOrder() } })
    await mountPage(OrderDetail, '/orders/O1?payment=return', '/orders/:id')

    await vi.advanceTimersByTimeAsync(4100)

    expect(wrapper.text()).toContain('Payment received')
  })

  it('waits while the bank is processing even without coming back from the page', async () => {
    api.get.mockResolvedValue({
      data: {
        data: order({
          status: status('PAYMENT_PROCESSING', 'Payment processing'),
          payment_status: status('PROCESSING', 'Processing'),
          can_pay: false,
          payment: payment('PROCESSING'),
        }),
      },
    })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')

    expect(wrapper.text()).toContain('Confirming your payment')
    await vi.advanceTimersByTimeAsync(2000)
    expect(api.get).toHaveBeenCalledTimes(2)
  })

  it('does not poll on a normal visit, or after the page is closed', async () => {
    api.get.mockResolvedValue({ data: { data: order({ payment: payment('PENDING') }) } })
    await mountPage(OrderDetail, '/orders/O1', '/orders/:id')
    await vi.advanceTimersByTimeAsync(10000)
    expect(api.get).toHaveBeenCalledTimes(1)

    wrapper.unmount()
    api.get.mockClear()
    api.get.mockResolvedValue({ data: { data: returning } })
    await mountPage(OrderDetail, '/orders/O1?payment=return', '/orders/:id')
    api.get.mockClear()
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(10000)
    expect(api.get).not.toHaveBeenCalled()
  })

  const cancelledOrder = (overrides = {}) =>
    order({
      status: status('CANCELLED', 'Cancelled'),
      payment_status: status('CANCELLED', 'Cancelled'),
      can_pay: false,
      can_cancel: false,
      cancelled_at: '2026-10-12T08:31:00+00:00',
      payment: payment('CANCELLED'),
      ...overrides,
    })

  it('keeps waiting for a payment the payment page called successful, even though the order already looks cancelled', async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: cancelledOrder() } })
      .mockResolvedValue({ data: { data: cancelledOrder({ refund_pending: true, payment: payment('PAID') }) } })
    await mountPage(OrderDetail, '/orders/O1?payment=return&result=succeeded', '/orders/:id')

    // It does not claim "nothing was charged" while the payment may still be arriving.
    expect(wrapper.text()).toContain('Confirming your payment')
    expect(wrapper.text()).not.toContain('Nothing was charged')

    await vi.advanceTimersByTimeAsync(2000)

    expect(wrapper.text()).toContain('We received your payment after this order was cancelled')
    expect(wrapper.text()).not.toContain('Confirming your payment')
    await vi.advanceTimersByTimeAsync(10000)
    expect(api.get).toHaveBeenCalledTimes(2) // settled: stopped asking
  })

  it('does not wait when the payment page said the customer cancelled', async () => {
    api.get.mockResolvedValue({ data: { data: cancelledOrder() } })
    await mountPage(OrderDetail, '/orders/O1?payment=return&result=cancelled', '/orders/:id')

    expect(wrapper.text()).not.toContain('Confirming your payment')
    expect(wrapper.text()).toContain('Nothing was charged')
    await vi.advanceTimersByTimeAsync(10000)
    expect(api.get).toHaveBeenCalledTimes(1)
  })

  it('does not wait for money the server has already recorded', async () => {
    api.get.mockResolvedValue({ data: { data: paidOrder() } })
    await mountPage(OrderDetail, '/orders/O1?payment=return&result=succeeded', '/orders/:id')

    expect(wrapper.text()).toContain('Payment received')
    expect(wrapper.text()).not.toContain('Confirming your payment')
    await vi.advanceTimersByTimeAsync(10000)
    expect(api.get).toHaveBeenCalledTimes(1)
  })

  it('shows a declined payment as soon as the server reports it', async () => {
    api.get.mockResolvedValueOnce({ data: { data: returning } }).mockResolvedValue({
      data: {
        data: order({
          payment_status: status('FAILED', 'Payment failed'),
          payment: payment('FAILED', { failure_reason: 'Declined (simulated)' }),
        }),
      },
    })
    await mountPage(OrderDetail, '/orders/O1?payment=return', '/orders/:id')

    await vi.advanceTimersByTimeAsync(2000)

    expect(wrapper.text()).toContain("Your payment didn't go through")
    expect(wrapper.text()).not.toContain('Confirming your payment')
    expect(button('Try paying again')).toBeDefined()
  })
})

describe('the orders store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('starts a payment without sending any amount', async () => {
    api.post.mockResolvedValue({ data: { data: payment('PENDING') } })

    const result = await useOrderStore().startPayment('O1')

    expect(api.post).toHaveBeenCalledWith('/api/orders/O1/payments')
    expect(result.checkout_url).toContain('/fake-gateway/')
  })
})

describe('the pretend payment page', () => {
  const page = {
    reference: 'fakepay_abc',
    description: 'HighTech Store order ORD-2026-00001',
    amount: money(101500),
    status: 'pending',
    return_url: 'http://localhost:5173/orders/O1?payment=return',
  }

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => {
    wrapper?.unmount()
    document.body.innerHTML = ''
  })

  it('shows what is being paid and the three ways to end it', async () => {
    api.get.mockResolvedValue({ data: { data: page } })
    await mountPage(FakeGateway, '/fake-gateway/fakepay_abc', '/fake-gateway/:reference')

    expect(api.get).toHaveBeenCalledWith('/api/fake-gateway/payments/fakepay_abc')
    expect(wrapper.find('[data-testid=amount]').text()).toBe('RM 1015.00')
    expect(wrapper.text()).toContain('ORD-2026-00001')
    expect(wrapper.text()).toContain('Test mode')
    expect(button('Pay successfully')).toBeDefined()
    expect(button('Simulate a declined payment')).toBeDefined()
    expect(button('Cancel and go back')).toBeDefined()
  })

  it.each([
    ['Pay successfully', 'succeeded'],
    ['Simulate a declined payment', 'failed'],
    ['Cancel and go back', 'cancelled'],
  ])('"%s" sends "%s" and returns to the shop', async (label, outcome) => {
    api.get.mockResolvedValue({ data: { data: page } })
    api.post.mockResolvedValue({ data: { data: { status: outcome, return_url: page.return_url } } })
    await mountPage(FakeGateway, '/fake-gateway/fakepay_abc', '/fake-gateway/:reference')

    await button(label).trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/fake-gateway/payments/fakepay_abc/complete', { outcome })
    expect(redirectTo).toHaveBeenCalledWith(page.return_url)
    expect(wrapper.findAll('button').every((b) => b.attributes('disabled') !== undefined)).toBe(true) // no second click
  })

  it('shows the error and lets you choose again if completing fails', async () => {
    api.get.mockResolvedValue({ data: { data: page } })
    api.post.mockRejectedValue({ message: 'x', errors: { outcome: ['This payment has already been completed.'] } })
    await mountPage(FakeGateway, '/fake-gateway/fakepay_abc', '/fake-gateway/:reference')

    await button('Pay successfully').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('This payment has already been completed.')
    expect(redirectTo).not.toHaveBeenCalled()
    expect(button('Pay successfully').attributes('disabled')).toBeUndefined()
  })

  it('offers a way back instead of buttons when the payment was already completed', async () => {
    api.get.mockResolvedValue({ data: { data: { ...page, status: 'succeeded' } } })
    await mountPage(FakeGateway, '/fake-gateway/fakepay_abc', '/fake-gateway/:reference')

    expect(wrapper.find('[data-testid=already-done]').text()).toContain('already been completed')
    expect(wrapper.find('a').attributes('href')).toBe(page.return_url)
    expect(button('Pay successfully')).toBeUndefined()
  })

  it('says so when the page does not exist', async () => {
    api.get.mockRejectedValue(Object.assign(new Error('x'), { response: { status: 404 } }))
    await mountPage(FakeGateway, '/fake-gateway/nope', '/fake-gateway/:reference')

    expect(wrapper.text()).toContain("This payment page doesn't exist")
  })
})
