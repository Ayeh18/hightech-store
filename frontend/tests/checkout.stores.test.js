import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))

import { api } from '@/lib/api'
import { useAddressStore } from '@/stores/addresses'
import { useCheckoutStore } from '@/stores/checkout'
import { useOrderStore } from '@/stores/orders'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const quote = (overrides = {}) => ({
  data: {
    data: {
      subtotal: money(100000),
      discount: money(0),
      shipping: money(1500),
      total: money(101500),
      shipping_known: true,
      coupon: { code: null, applied: false, error: null },
      blockers: [],
      can_place: true,
      free_shipping_over: money(300000),
      ...overrides,
    },
  },
})

describe('checkout store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('asks the server for the totals instead of calculating them', async () => {
    api.post.mockResolvedValue(quote())
    const checkout = useCheckoutStore()
    checkout.addressId = 7

    await checkout.refreshQuote()

    expect(api.post).toHaveBeenCalledWith('/api/checkout/preview', { address_id: 7, coupon_code: null })
    expect(checkout.quote.total.formatted).toBe('RM 1015.00')
    expect(checkout.canPlace).toBe(true)
  })

  it("sends the coupon code, and shows the server's verdict on it", async () => {
    const checkout = useCheckoutStore()
    checkout.addressId = 7

    api.post.mockResolvedValue(
      quote({ coupon: { code: null, applied: false, error: 'That coupon code is not valid.' }, can_place: false }),
    )
    await checkout.applyCoupon('  bogus ')
    expect(api.post).toHaveBeenLastCalledWith('/api/checkout/preview', { address_id: 7, coupon_code: 'bogus' })
    expect(checkout.couponError).toBe('That coupon code is not valid.')
    expect(checkout.canPlace).toBe(false)

    api.post.mockResolvedValue(
      quote({ discount: money(10000), coupon: { code: 'WELCOME10', applied: true, error: null } }),
    )
    await checkout.applyCoupon('welcome10')
    expect(checkout.couponError).toBe('')
    expect(checkout.quote.coupon.applied).toBe(true)
  })

  it('ignores a slow answer that was overtaken by a newer question', async () => {
    const checkout = useCheckoutStore()
    let slow
    api.post
      .mockImplementationOnce(() => new Promise((resolve) => (slow = resolve)))
      .mockResolvedValueOnce(quote({ total: money(222) }))

    const first = checkout.selectAddress(1)
    await checkout.selectAddress(2)
    slow(quote({ total: money(999999) }))
    await first

    expect(checkout.quote.total.amount).toBe(222)
  })

  it('places the order with an Idempotency-Key header and never sends a price', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 'ORD1', order_number: 'ORD-2026-00001' } } })
    const checkout = useCheckoutStore()
    checkout.addressId = 7
    checkout.couponCode = 'WELCOME10'
    checkout.notes = ' Leave at guardhouse '

    const order = await checkout.place()

    const [url, body, config] = api.post.mock.calls[0]
    expect(url).toBe('/api/checkout')
    expect(body).toEqual({ address_id: 7, coupon_code: 'WELCOME10', notes: 'Leave at guardhouse' })
    expect(Object.keys(body)).not.toContain('total')
    expect(config.headers['Idempotency-Key']).toMatch(/^[A-Za-z0-9_-]{8,64}$/)
    expect(order.id).toBe('ORD1')
  })

  it('reuses the same key when a failed attempt is retried, and uses a fresh one after an order is placed', async () => {
    const checkout = useCheckoutStore()
    checkout.addressId = 7
    api.post.mockRejectedValueOnce({ message: 'Network Error' }).mockResolvedValue({ data: { data: { id: 'ORD1' } } })

    await expect(checkout.place()).rejects.toBeDefined()
    await checkout.place() // the retry
    await checkout.place() // a new checkout

    const keys = api.post.mock.calls.map((call) => call[2].headers['Idempotency-Key'])
    expect(keys[0]).toBe(keys[1]) // retry of the same attempt: same key, so the server can recognise it
    expect(keys[2]).not.toBe(keys[1]) // the order existed, so the next checkout is a new attempt
  })

  it('keeps the form after a refusal and surfaces a coupon problem on the coupon', async () => {
    const checkout = useCheckoutStore()
    checkout.addressId = 7
    checkout.couponCode = 'ONCE'
    api.post.mockRejectedValue({ message: 'invalid', errors: { coupon_code: ['You have already used ONCE.'] } })

    await expect(checkout.place()).rejects.toBeDefined()

    expect(checkout.couponError).toBe('You have already used ONCE.')
    expect(checkout.couponCode).toBe('ONCE')
    expect(checkout.placing).toBe(false)
  })

  it('clears the form after an order is placed', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 'ORD1' } } })
    const checkout = useCheckoutStore()
    checkout.addressId = 7
    checkout.couponCode = 'X'
    checkout.notes = 'n'

    await checkout.place()

    expect(checkout.couponCode).toBe('')
    expect(checkout.notes).toBe('')
    expect(checkout.quote).toBeNull()
    expect(checkout.addressId).toBe(7) // the chosen address is kept for next time
  })
})

describe('address store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads the address book and reports the default', async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          { id: 1, is_default: false },
          { id: 2, is_default: true },
        ],
      },
    })
    const store = useAddressStore()

    await store.fetch()

    expect(store.defaultAddress.id).toBe(2)
  })

  it('falls back to the first address when none is marked default', async () => {
    api.get.mockResolvedValue({ data: { data: [{ id: 5, is_default: false }] } })
    const store = useAddressStore()
    await store.fetch()

    expect(store.defaultAddress.id).toBe(5)
  })

  it("reloads after every change so the default is always the server's answer", async () => {
    api.post.mockResolvedValue({ data: { data: { id: 3 } } })
    api.put.mockResolvedValue({ data: { data: { id: 3 } } })
    api.delete.mockResolvedValue({})
    api.get.mockResolvedValue({ data: { data: [] } })
    const store = useAddressStore()

    const created = await store.create({ city: 'KL' })
    await store.update(3, { is_default: true })
    await store.remove(3)

    expect(created.id).toBe(3)
    expect(api.get).toHaveBeenCalledTimes(3)
  })

  it('forgets everything on reset', async () => {
    api.get.mockResolvedValue({ data: { data: [{ id: 1 }] } })
    const store = useAddressStore()
    await store.fetch()

    store.reset()

    expect(store.addresses).toEqual([])
    expect(store.loaded).toBe(false)
  })
})

describe('order store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads a page of orders with its pagination', async () => {
    api.get.mockResolvedValue({ data: { data: [{ id: 'A' }], meta: { current_page: 2, last_page: 3, total: 25 } } })
    const store = useOrderStore()

    await store.fetchOrders(2)

    expect(api.get).toHaveBeenCalledWith('/api/orders', { params: { page: 2 } })
    expect(store.orders).toEqual([{ id: 'A' }])
    expect(store.meta.last_page).toBe(3)
  })

  it('reports a readable error', async () => {
    api.get.mockRejectedValue({ message: 'Unable to reach the server.' })
    const store = useOrderStore()

    await store.fetchOrders()

    expect(store.error).toBe('Unable to reach the server.')
  })

  it('reads one order and cancels it through the server', async () => {
    api.get.mockResolvedValue({ data: { data: { id: 'A', order_number: 'ORD-1' } } })
    api.post.mockResolvedValue({ data: { data: { id: 'A', status: { value: 'CANCELLED' } } } })
    const store = useOrderStore()

    expect((await store.fetchOrder('A')).order_number).toBe('ORD-1')
    expect((await store.cancel('A')).status.value).toBe('CANCELLED')
    expect(api.post).toHaveBeenCalledWith('/api/orders/A/cancel')
  })
})
