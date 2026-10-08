import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))

import { api } from '@/lib/api'
import { useCartStore } from '@/stores/cart'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const cartOf = (count, overrides = {}) => ({
  data: {
    data: {
      items: [{ id: 1 }],
      subtotal: money(10000),
      item_count: count,
      checkout_ready: true,
      has_price_changes: false,
      ...overrides,
    },
  },
})

describe('cart store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('starts empty and exposes the server cart once loaded', async () => {
    const cart = useCartStore()
    expect(cart.itemCount).toBe(0)
    expect(cart.checkoutReady).toBe(false)

    api.get.mockResolvedValue(cartOf(3, { has_price_changes: true }))
    await cart.fetch()

    expect(api.get).toHaveBeenCalledWith('/api/cart')
    expect(cart.itemCount).toBe(3)
    expect(cart.subtotal.formatted).toBe('RM 100.00')
    expect(cart.checkoutReady).toBe(true)
    expect(cart.hasPriceChanges).toBe(true)
    expect(cart.loaded).toBe(true)
  })

  it('shows a readable error when the cart cannot be loaded', async () => {
    api.get.mockRejectedValue({ message: 'Unable to reach the server.' })
    const cart = useCartStore()

    await cart.fetch()

    expect(cart.error).toBe('Unable to reach the server.')
    expect(cart.loading).toBe(false)
    expect(cart.loaded).toBe(false)
  })

  it('adds a product and adopts the cart the server returns', async () => {
    api.post.mockResolvedValue(cartOf(2))
    const cart = useCartStore()

    await cart.addProduct(7, 2)

    expect(api.post).toHaveBeenCalledWith('/api/cart/products', { product_id: 7, quantity: 2 })
    expect(cart.itemCount).toBe(2)
  })

  it('adds a saved build by id, or the builder parts with a name, never sending prices', async () => {
    api.post.mockResolvedValue(cartOf(1))
    const cart = useCartStore()

    await cart.addBuild({ buildId: 'ABC' })
    expect(api.post).toHaveBeenLastCalledWith('/api/cart/builds', { quantity: 1, build_id: 'ABC' })

    const items = [{ slot: 'cpu', product_id: 1, quantity: 1 }]
    await cart.addBuild({ items, name: 'My PC' })
    expect(api.post).toHaveBeenLastCalledWith('/api/cart/builds', { quantity: 1, items, name: 'My PC' })
  })

  it('changes a quantity, removes a line, accepts prices and clears', async () => {
    api.put.mockResolvedValue(cartOf(4))
    api.delete.mockResolvedValue(cartOf(1))
    api.post.mockResolvedValue(cartOf(1, { has_price_changes: false }))
    const cart = useCartStore()

    await cart.updateQuantity(9, 4)
    expect(api.put).toHaveBeenCalledWith('/api/cart/items/9', { quantity: 4 })
    expect(cart.itemCount).toBe(4)

    await cart.remove(9)
    expect(api.delete).toHaveBeenLastCalledWith('/api/cart/items/9')

    await cart.acceptPrices()
    expect(api.post).toHaveBeenLastCalledWith('/api/cart/accept-prices')

    await cart.clear()
    expect(api.delete).toHaveBeenLastCalledWith('/api/cart')
  })

  it("lets the caller show the server's reason when a change is refused, and keeps the old cart", async () => {
    api.get.mockResolvedValue(cartOf(2))
    const cart = useCartStore()
    await cart.fetch()

    api.post.mockRejectedValue({
      message: 'The given data was invalid.',
      errors: { quantity: ['Only 1 more can be added.'] },
    })

    await expect(cart.addProduct(7, 5)).rejects.toMatchObject({ errors: { quantity: ['Only 1 more can be added.'] } })
    expect(cart.itemCount).toBe(2) // unchanged
    expect(cart.busy).toBe(false)
  })

  it('is busy while a change is in flight', async () => {
    let finish
    api.put.mockImplementation(() => new Promise((resolve) => (finish = resolve)))
    const cart = useCartStore()

    const pending = cart.updateQuantity(1, 2)
    expect(cart.busy).toBe(true)
    finish(cartOf(2))
    await pending

    expect(cart.busy).toBe(false)
  })

  it('forgets everything on reset, so the next person never sees this cart', async () => {
    api.get.mockResolvedValue(cartOf(5))
    const cart = useCartStore()
    await cart.fetch()

    cart.reset()

    expect(cart.itemCount).toBe(0)
    expect(cart.items).toEqual([])
    expect(cart.loaded).toBe(false)
  })
})
