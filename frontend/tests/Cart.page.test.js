import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))

import { api } from '@/lib/api'
import Cart from '@/pages/Cart.vue'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const product = (id, name) => ({ id, slug: `p-${id}`, name, brand: { name: 'Brand' }, image: null })
const line = (id, name, qty, unit, extra = {}) => ({
  id,
  kind: 'product',
  name,
  quantity: qty,
  unit_price: money(unit),
  line_total: money(unit * qty),
  product: product(id, name),
  components: [],
  compatibility: null,
  issues: [],
  purchasable: true,
  ...extra,
})
const cartData = (items, extra = {}) => ({
  items,
  subtotal: money(items.reduce((sum, i) => sum + i.line_total.amount, 0)),
  item_count: items.reduce((sum, i) => sum + i.quantity, 0),
  checkout_ready: true,
  has_price_changes: false,
  ...extra,
})
const reply = (data) => ({ data: { data } })

async function open() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/cart', name: 'cart', component: Cart },
      { path: '/products', name: 'products', component: { template: '<div />' } },
      { path: '/products/:slug', name: 'product', component: { template: '<div />' } },
      { path: '/builder', name: 'builder', component: { template: '<div />' } },
    ],
  })
  router.push('/cart')
  await router.isReady()
  const wrapper = mount(Cart, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

describe('Cart page', () => {
  let mounted
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mounted = null
  })
  afterEach(() => mounted?.unmount())

  it('loads the cart from the server and shows lines and the total', async () => {
    api.get.mockResolvedValue(reply(cartData([line(1, 'Test GPU', 2, 150000), line(2, 'Test SSD', 1, 25000)])))
    mounted = await open()

    expect(api.get).toHaveBeenCalledWith('/api/cart')
    expect(mounted.text()).toContain('Test GPU')
    expect(mounted.text()).toContain('Test SSD')
    expect(mounted.find('[data-testid=subtotal]').text()).toBe('RM 3250.00')
    expect(mounted.find('[data-testid=item-count]').text()).toBe('3')
  })

  it('shows a friendly empty state with ways to start shopping', async () => {
    api.get.mockResolvedValue(reply(cartData([])))
    mounted = await open()

    expect(mounted.text()).toContain('Your cart is empty')
    expect(mounted.text()).toContain('Browse parts')
    expect(mounted.text()).toContain('Build a PC')
  })

  it('shows an error with a retry when the cart cannot be loaded', async () => {
    api.get.mockRejectedValueOnce({ message: 'Unable to reach the server.' })
    mounted = await open()
    expect(mounted.text()).toContain("We couldn't load your cart")

    api.get.mockResolvedValueOnce(reply(cartData([line(1, 'Test GPU', 1, 100)])))
    await mounted
      .findAll('button')
      .find((b) => b.text() === 'Try again')
      .trigger('click')
    await flushPromises()

    expect(mounted.text()).toContain('Test GPU')
  })

  it("changes a quantity through the server and shows the server's new totals", async () => {
    api.get.mockResolvedValue(reply(cartData([line(1, 'Test GPU', 1, 150000)])))
    mounted = await open()
    // The server answers with the recalculated cart: a price drop happened in the meantime.
    api.put.mockResolvedValue(reply(cartData([line(1, 'Test GPU', 2, 140000)])))

    await mounted
      .findAll('button')
      .find((b) => b.attributes('aria-label')?.startsWith('Increase'))
      .trigger('click')
    await flushPromises()

    expect(api.put).toHaveBeenCalledWith('/api/cart/items/1', { quantity: 2 })
    expect(mounted.find('[data-testid=subtotal]').text()).toBe('RM 2800.00') // the server's number, not 2 x 1500
  })

  it("shows the server's reason when a quantity change is refused", async () => {
    api.get.mockResolvedValue(reply(cartData([line(1, 'Test GPU', 1, 150000)])))
    mounted = await open()
    api.put.mockRejectedValue({
      message: 'invalid',
      errors: { quantity: ['Only 1 more of Test GPU can be added (2 in stock).'] },
    })

    await mounted
      .findAll('button')
      .find((b) => b.attributes('aria-label')?.startsWith('Increase'))
      .trigger('click')
    await flushPromises()

    expect(mounted.text()).toContain('Only 1 more of Test GPU can be added (2 in stock).')
    expect(mounted.find('[data-testid=subtotal]').text()).toBe('RM 1500.00') // unchanged
  })

  it('removes a line and clears the cart', async () => {
    api.get.mockResolvedValue(reply(cartData([line(1, 'Test GPU', 1, 100), line(2, 'Test SSD', 1, 200)])))
    mounted = await open()

    api.delete.mockResolvedValueOnce(reply(cartData([line(2, 'Test SSD', 1, 200)])))
    await mounted
      .findAll('button')
      .find((b) => b.attributes('aria-label') === 'Remove Test GPU from cart')
      .trigger('click')
    await flushPromises()
    expect(api.delete).toHaveBeenCalledWith('/api/cart/items/1')
    expect(mounted.text()).not.toContain('Test GPU')

    api.delete.mockResolvedValueOnce(reply(cartData([])))
    await mounted
      .findAll('button')
      .find((b) => b.text() === 'Clear cart')
      .trigger('click')
    await flushPromises()
    expect(mounted.text()).toContain('Your cart is empty')
  })

  it('shows price-change and blocking problems, and accepts new prices', async () => {
    api.get.mockResolvedValue(
      reply(
        cartData(
          [
            line(1, 'Test GPU', 1, 150000, {
              issues: [
                {
                  code: 'price_changed',
                  severity: 'warning',
                  message: 'Test GPU: the price went up from RM 1,000.00 to RM 1,500.00 since you added it.',
                },
              ],
            }),
          ],
          { has_price_changes: true },
        ),
      ),
    )
    mounted = await open()
    expect(mounted.text()).toContain('the price went up from RM 1,000.00 to RM 1,500.00')
    expect(mounted.text()).toContain('Some prices changed')

    api.post.mockResolvedValue(reply(cartData([line(1, 'Test GPU', 1, 150000)])))
    await mounted
      .findAll('button')
      .find((b) => b.text() === 'Accept new prices')
      .trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/cart/accept-prices')
    expect(mounted.text()).not.toContain('Some prices changed')
  })

  it('tells the customer to fix red items before checking out', async () => {
    api.get.mockResolvedValue(
      reply(
        cartData(
          [
            line(1, 'Test GPU', 1, 150000, {
              issues: [{ code: 'out_of_stock', severity: 'error', message: 'Test GPU is out of stock.' }],
            }),
          ],
          { checkout_ready: false },
        ),
      ),
    )
    mounted = await open()

    expect(mounted.text()).toContain('Test GPU is out of stock.')
    expect(mounted.text()).toContain('Fix the items marked in red')
  })

  it('renders a PC build line with its parts', async () => {
    api.get.mockResolvedValue(
      reply(
        cartData([
          {
            id: 9,
            kind: 'build',
            name: 'Gaming PC',
            quantity: 1,
            unit_price: money(270000),
            line_total: money(270000),
            product: null,
            components: [
              {
                slot: 'cpu',
                slot_label: 'CPU',
                quantity: 1,
                product: product(3, 'Ryzen 5'),
                on_sale: true,
                unit_price: money(80000),
                price_when_added: money(80000),
              },
            ],
            compatibility: { status: 'compatible', issues: [] },
            issues: [],
            purchasable: true,
          },
        ]),
      ),
    )
    mounted = await open()

    expect(mounted.text()).toContain('Complete PC build')
    expect(mounted.text()).toContain('Gaming PC')
    expect(mounted.text()).toContain('Ryzen 5')
  })
})
