import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import { api } from '@/lib/api'
import Dashboard from '@/pages/admin/Dashboard.vue'
import { STAFF_PERMISSIONS, at, cleanup, money, mountAdmin, signIn, status } from './adminFixtures'

const day = (n, net, placed) => ({
  date: `2026-10-${String(n).padStart(2, '0')}`,
  orders_placed: placed,
  orders_paid: placed,
  gross: money(net),
  refunded: money(0),
  net: money(net),
})

const dashboard = (overrides = {}) => ({
  period: { days: 30, from: '2026-09-21', to: '2026-10-20' },
  cards: {
    total_sales: money(1234500),
    gross_sales: money(1300000),
    refunded: money(65500),
    orders: 42,
    orders_paid: 30,
    average_order: money(43333),
    pending_payments: { count: 3, amount: money(300000) },
    products: { active: 171, total: 180 },
    low_stock: 4,
    customers: { total: 25, new: 6 },
  },
  sales_by_day: [day(18, 100000, 2), day(19, 0, 0), day(20, 250000, 5)],
  top_products: [
    { id: 1, name: 'Ryzen 7 9800X3D', sku: 'CPU-1', units: 12, revenue: money(2400000) },
    { id: 2, name: 'DDR5 32GB', sku: 'RAM-1', units: 30, revenue: money(900000) },
  ],
  revenue_by_category: [{ id: 1, name: 'Processors', units: 12, revenue: money(2400000) }],
  recent_orders: [
    {
      id: 'O1',
      order_number: 'ORD-2026-00001',
      customer: { name: 'Aisyah', email: 'a@x.test' },
      status: status('PAID', 'Paid'),
      payment_status: status('PAID', 'Paid'),
      total: money(101500),
      items_count: 1,
      placed_at: at(20),
    },
  ],
  low_stock_products: [
    { id: 7, name: 'RTX 5090', sku: 'GPU-9', stock: 2, reserved: 2, available: 0, low_stock_threshold: 3 },
    { id: 8, name: 'B650 board', sku: 'MB-2', stock: 4, reserved: 1, available: 3, low_stock_threshold: 5 },
  ],
  recent_payments: [
    {
      id: 'P1',
      status: status('PAID', 'Paid'),
      amount: money(101500),
      created_at: at(20),
      failure_reason: null,
      order: { id: 'O1', order_number: 'ORD-2026-00001', customer: { name: 'Aisyah', email: 'a@x.test' } },
    },
  ],
  failed_payments: [
    {
      id: 'P2',
      status: status('FAILED', 'Failed'),
      amount: money(50000),
      created_at: at(19),
      failure_reason: 'Card declined',
      order: { id: 'O2', order_number: 'ORD-2026-00002', customer: { name: 'Budi', email: 'b@x.test' } },
    },
  ],
  ...overrides,
})

beforeEach(() => {
  vi.clearAllMocks()
  signIn()
  api.get.mockResolvedValue({ data: { data: dashboard() } })
})
afterEach(cleanup)

describe('the dashboard', () => {
  it('shows every card with the numbers the server worked out', async () => {
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    const cards = wrapper.findAll('[data-testid=stat-card]').map((c) => c.text())
    expect(api.get).toHaveBeenCalledWith('/api/admin/dashboard', { params: { days: 30 } })
    expect(cards).toHaveLength(6)
    expect(cards[0]).toContain('Total sales')
    expect(cards[0]).toContain('RM 12,345.00')
    expect(cards[0]).toContain('RM 13,000.00 in, RM 655.00 refunded')
    expect(cards[1]).toContain('42')
    expect(cards[1]).toContain('30 paid · average RM 433.33')
    expect(cards[2]).toContain('Pending payments')
    expect(cards[2]).toContain('RM 3,000.00')
    expect(cards[3]).toContain('171')
    expect(cards[4]).toContain('Low stock')
    expect(cards[5]).toContain('25')
    expect(cards[5]).toContain('6 new')
  })

  it('draws a bar for every day, for sales and for orders', async () => {
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    const charts = wrapper.findAll('[data-testid=bar-chart]')
    expect(charts).toHaveLength(2)
    expect(charts[0].findAll('[data-testid=bar]')).toHaveLength(3)
    expect(charts[0].attributes('aria-label')).toContain('Total RM 12,345.00')
    expect(charts[1].attributes('aria-label')).toContain('Total 42')
    // The tallest day fills the chart; a day with nothing has no bar to speak of.
    const heights = charts[0].findAll('[data-testid=bar]').map((b) => b.attributes('style'))
    expect(heights[2]).toContain('100%')
    expect(heights[1]).toContain('0%')
  })

  it('ranks what sold best, with the money', async () => {
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    const ranked = wrapper.findAll('[data-testid=ranked-bars]')
    expect(ranked[0].text()).toContain('Ryzen 7 9800X3D')
    expect(ranked[0].text()).toContain('12 sold')
    expect(ranked[0].text()).toContain('RM 24,000.00')
    expect(ranked[1].text()).toContain('Processors')
  })

  it('lists recent orders as links to the order, and low stock with "Sold out"', async () => {
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    const orderLink = wrapper.find('[data-testid=recent-orders] a')
    expect(orderLink.text()).toBe('ORD-2026-00001')
    expect(orderLink.attributes('href')).toBe('/admin/orders/O1')
    const rows = wrapper.findAll('[data-testid=low-stock] tbody tr')
    expect(rows[0].text()).toContain('RTX 5090')
    expect(rows[0].text()).toContain('Sold out')
    expect(rows[1].text()).toContain('B650 board')
    expect(rows[1].text()).toContain('3')
  })

  it('lists payments and failed payments, with the reason', async () => {
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    expect(wrapper.find('[data-testid=recent-payments]').text()).toContain('RM 1,015.00')
    expect(wrapper.find('[data-testid=failed-payments]').text()).toContain('Card declined')
    expect(wrapper.find('[data-testid=failed-payments] a').attributes('href')).toBe('/admin/payments/P2')
  })

  it('leaves out the payment tables for people who may not view payments (the server sends null)', async () => {
    signIn(STAFF_PERMISSIONS, ['staff'])
    api.get.mockResolvedValue({ data: { data: dashboard({ recent_payments: null, failed_payments: null }) } })
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    expect(wrapper.find('[data-testid=recent-payments]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=failed-payments]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=recent-orders]').exists()).toBe(true)
  })

  it('says so when there is nothing to show yet', async () => {
    api.get.mockResolvedValue({
      data: {
        data: dashboard({ top_products: [], revenue_by_category: [], recent_orders: [], low_stock_products: [] }),
      },
    })
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    expect(wrapper.text()).toContain('No sales in this period yet.')
    expect(wrapper.text()).toContain('No orders yet.')
    expect(wrapper.text()).toContain('Everything is well stocked.')
  })

  it('reloads for another period when asked', async () => {
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    await wrapper
      .findAll('button')
      .find((b) => b.text() === '7 days')
      .trigger('click')
    await flushPromises()

    expect(api.get).toHaveBeenLastCalledWith('/api/admin/dashboard', { params: { days: 7 } })
    expect(
      wrapper
        .findAll('button')
        .find((b) => b.text() === '7 days')
        .attributes('aria-pressed'),
    ).toBe('true')
  })

  it('never lets a slow old answer replace a newer one', async () => {
    let slow
    api.get.mockImplementationOnce(() => new Promise((resolve) => (slow = resolve)))
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')
    api.get.mockResolvedValueOnce({
      data: { data: dashboard({ period: { days: 7 }, cards: { ...dashboard().cards, orders: 7 } }) },
    })

    await wrapper
      .findAll('button')
      .find((b) => b.text() === '7 days')
      .trigger('click')
    await flushPromises()
    slow({ data: { data: dashboard({ cards: { ...dashboard().cards, orders: 999 } }) } }) // the 30-day answer arrives late
    await flushPromises()

    expect(wrapper.findAll('[data-testid=stat-card]')[1].text()).toContain('7')
    expect(wrapper.text()).not.toContain('999')
  })

  it('offers a retry when it cannot load', async () => {
    api.get.mockRejectedValueOnce({ message: 'The server had a problem.' })
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    expect(wrapper.text()).toContain('The server had a problem.')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Try again')
      .trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-testid=stat-card]')).toHaveLength(6)
  })

  it('links the pending-payments card to the orders waiting for payment', async () => {
    const { wrapper } = await mountAdmin(Dashboard, '/admin/dashboard')

    expect(wrapper.findAll('[data-testid=stat-card]')[2].attributes('href')).toBe(
      '/admin/orders?status=PENDING_PAYMENT',
    )
  })
})
