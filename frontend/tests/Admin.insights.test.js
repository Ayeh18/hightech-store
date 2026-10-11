import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import { api } from '@/lib/api'
import { percent, periodProblem } from '@/lib/format'
import Insights from '@/pages/admin/Insights.vue'
import { cleanup, field, money, mountAdmin, signIn } from './adminFixtures'

// What GET /api/admin/reports/insights sends, with every list filled in.
const INSIGHTS = {
  period: { from: '2026-09-21', to: '2026-10-20' },
  customers: { buyers: 12, new: 9, returning: 3, returning_rate: 25, orders: 16, orders_per_buyer: 1.33 },
  checkout: {
    placed: 20,
    paid: 14,
    awaiting_payment: 2,
    cancelled_unpaid: 4,
    paid_rate: 70,
    with_coupon: 3,
    discount_given: money(45000),
  },
  payments: {
    attempts: 22,
    paid: 14,
    failed: 5,
    cancelled: 1,
    open: 2,
    success_rate: 66.7,
    failure_reasons: [
      { reason: 'Card declined', attempts: 4 },
      { reason: 'No reason given', attempts: 1 },
    ],
    methods: [{ method: 'fakepay', payments: 14, amount: money(5200000) }],
  },
  pcs: {
    sold: 5,
    revenue: money(2100000),
    average_price: money(420000),
    single_items: { units: 31, revenue: money(900000) },
    revenue_share: 70,
    popular_parts: [
      { slot: 'cpu', slot_label: 'CPU', id: 1, name: 'Ryzen 7 7700', pcs: 3, share: 60 },
      { slot: 'cpu', slot_label: 'CPU', id: 2, name: 'Core i5-14400F', pcs: 2, share: 40 },
      { slot: 'gpu', slot_label: 'GPU', id: 3, name: 'Radeon RX 9060 XT', pcs: 4, share: 80 },
    ],
    pairings: [
      { cpu: 'Ryzen 7 7700', gpu: 'Radeon RX 9060 XT', pcs: 3 },
      { cpu: 'Core i5-14400F', gpu: null, pcs: 1 },
    ],
  },
  suggestions: {
    asked: 40,
    accepted: 12,
    acceptance_rate: 30,
    average_budget: money(430000),
    by_purpose: [
      { purpose: 'gaming', label: 'Gaming', asked: 30, accepted: 10 },
      { purpose: 'creation', label: 'Video editing and 3D', asked: 6, accepted: 2 },
      { purpose: 'work', label: 'Office, study and browsing', asked: 4, accepted: 0 },
    ],
    by_budget: [
      { label: 'Under RM 3,000', asked: 8 },
      { label: 'RM 3,000 to under RM 5,000', asked: 20 },
      { label: 'RM 5,000 to under RM 8,000', asked: 9 },
      { label: 'RM 8,000 and above', asked: 3 },
    ],
    ai: {
      asked: 10,
      used: 7,
      fell_back: 3,
      reasons: [
        { kind: 'service_error', label: 'The AI service answered with an error', times: 2 },
        { kind: 'over_budget', label: 'Its build cost more than the budget', times: 1 },
      ],
    },
  },
}

// A period in which nothing happened: zeros, and no percentage could be worked out.
const QUIET = {
  period: INSIGHTS.period,
  customers: { buyers: 0, new: 0, returning: 0, returning_rate: null, orders: 0, orders_per_buyer: null },
  checkout: {
    placed: 0,
    paid: 0,
    awaiting_payment: 0,
    cancelled_unpaid: 0,
    paid_rate: null,
    with_coupon: 0,
    discount_given: money(0),
  },
  payments: {
    attempts: 0,
    paid: 0,
    failed: 0,
    cancelled: 0,
    open: 0,
    success_rate: null,
    failure_reasons: [],
    methods: [],
  },
  pcs: {
    sold: 0,
    revenue: money(0),
    average_price: money(0),
    single_items: { units: 0, revenue: money(0) },
    revenue_share: null,
    popular_parts: [],
    pairings: [],
  },
  suggestions: {
    asked: 0,
    accepted: 0,
    acceptance_rate: null,
    average_budget: money(0),
    by_purpose: INSIGHTS.suggestions.by_purpose.map((p) => ({ ...p, asked: 0, accepted: 0 })),
    by_budget: INSIGHTS.suggestions.by_budget.map((b) => ({ ...b, asked: 0 })),
    ai: { asked: 0, used: 0, fell_back: 0, reasons: [] },
  },
}

const cards = (wrapper, section) =>
  Object.fromEntries(
    wrapper
      .findAll(`[data-testid=${section}] [data-testid=stat-card]`)
      .map((card) => [card.find('p').text(), card.find('[data-testid=stat-value]').text()]),
  )
const text = (wrapper, testId) => wrapper.find(`[data-testid=${testId}]`).text()

beforeEach(() => {
  vi.clearAllMocks()
  signIn()
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-20T06:00:00Z'))
  api.get.mockResolvedValue({ data: { data: INSIGHTS } })
})
afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

describe('insights', () => {
  it("opens on the last 30 days, in the shop's dates", async () => {
    await mountAdmin(Insights, '/admin/insights')

    expect(api.get).toHaveBeenCalledWith('/api/admin/reports/insights', {
      params: { from: '2026-09-21', to: '2026-10-20' },
    })
  })

  it('shows who bought, and how many had bought before', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')

    expect(cards(wrapper, 'insight-customers')).toEqual({
      Buyers: '12',
      'New buyers': '9',
      'Returning buyers': '3',
      'Orders per buyer': '1.33',
    })
    expect(text(wrapper, 'insight-customers')).toContain('25% of buyers had bought before')
    expect(text(wrapper, 'insight-customers')).toContain('16 orders paid')
  })

  it('shows what became of the orders placed', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')

    expect(cards(wrapper, 'insight-checkout')).toEqual({
      'Orders placed': '20',
      Paid: '14',
      'Waiting for payment': '2',
      'Cancelled unpaid': '4',
      'Paid with a coupon': '3',
    })
    expect(text(wrapper, 'insight-checkout')).toContain('70% of orders placed')
    expect(text(wrapper, 'insight-checkout')).toContain('RM 450.00 taken off')
  })

  it('shows how payments went, why they failed and how people paid', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')

    expect(cards(wrapper, 'insight-payments')).toEqual({
      'Success rate': '66.7%',
      Attempts: '22',
      Paid: '14',
      Failed: '5',
      Cancelled: '1',
    })
    expect(text(wrapper, 'insight-payments')).toContain('2 not finished yet')
    expect(text(wrapper, 'failure-reasons')).toContain('Card declined')
    expect(text(wrapper, 'failure-reasons')).toContain('4 attempts')
    expect(text(wrapper, 'failure-reasons')).toContain('1 attempt')
    expect(text(wrapper, 'failure-reasons')).not.toContain('1 attempts')
    expect(text(wrapper, 'payment-methods')).toContain('fakepay')
    expect(text(wrapper, 'payment-methods')).toContain('RM 52,000.00')
  })

  it('sets whole PCs against single items, and lists what goes into the PCs', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')

    expect(cards(wrapper, 'insight-pcs')).toEqual({
      'PCs sold': '5',
      'PC sales': 'RM 21,000.00',
      'Average PC': 'RM 4,200.00',
      'Single items': 'RM 9,000.00',
    })
    expect(text(wrapper, 'insight-pcs')).toContain('70% of everything sold')
    expect(text(wrapper, 'insight-pcs')).toContain('31 items sold on their own')

    const rows = wrapper.findAll('[data-testid=popular-parts] tbody tr')
    expect(rows).toHaveLength(3)
    expect(rows[0].text()).toContain('CPU')
    expect(rows[0].text()).toContain('Ryzen 7 7700')
    expect(rows[0].text()).toContain('60%')
    expect(rows[2].text()).toContain('GPU')
    expect(rows[2].text()).toContain('Radeon RX 9060 XT')
  })

  it('names a slot once for people who look, and on every row for people who listen', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')
    const labels = wrapper.findAll('[data-testid=popular-parts] tbody td:first-child span')

    expect(labels.map((l) => l.text())).toEqual(['CPU', 'CPU', 'GPU'])
    expect(labels.map((l) => l.classes('sr-only'))).toEqual([false, true, false])
  })

  it('says when a PC runs without a graphics card', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')
    const pairs = wrapper.findAll('[data-testid=pairings] li').map((li) => li.text())

    expect(pairs[0]).toContain('Ryzen 7 7700')
    expect(pairs[0]).toContain('with Radeon RX 9060 XT')
    expect(pairs[0]).toContain('3 PCs')
    expect(pairs[1]).toContain('Core i5-14400F')
    expect(pairs[1]).toContain("on the processor's built-in graphics")
    expect(pairs[1]).toContain('1 PC')
  })

  it('shows how the build suggestions were used', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')

    expect(cards(wrapper, 'insight-suggestions')).toEqual({
      'Builds suggested': '40',
      'Loaded into the builder': '12',
      'Average budget': 'RM 4,300.00',
    })
    expect(text(wrapper, 'insight-suggestions')).toContain('30% of suggestions')
    expect(text(wrapper, 'suggestion-purposes')).toContain('Gaming')
    expect(text(wrapper, 'suggestion-purposes')).toContain('10 loaded into the builder')
    expect(wrapper.findAll('[data-testid=suggestion-budgets] li')).toHaveLength(4)
    expect(text(wrapper, 'suggestion-budgets')).toContain('RM 3,000 to under RM 5,000')
  })

  it('says how often the AI was used and why the rules took over', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')
    const ai = text(wrapper, 'suggestion-ai')

    expect(ai).toContain('Asked for 10 suggestions')
    expect(ai).toContain('its build was used 7 times')
    expect(ai).toContain("the store's rules took over 3 times")
    expect(ai).toContain('The AI service answered with an error')
    expect(ai).toContain('2 times')
    expect(ai).toContain('Its build cost more than the budget')
    expect(ai).toContain('1 time')
  })

  it('reads sensibly for a period in which nothing happened', async () => {
    api.get.mockResolvedValue({ data: { data: QUIET } })
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')

    expect(cards(wrapper, 'insight-customers')['Orders per buyer']).toBe('—')
    expect(cards(wrapper, 'insight-payments')['Success rate']).toBe('—')
    expect(cards(wrapper, 'insight-suggestions')['Average budget']).toBe('—')
    expect(text(wrapper, 'insight-customers')).toContain('— of buyers had bought before')
    expect(text(wrapper, 'failure-reasons')).toContain('No payment failed in this period.')
    expect(text(wrapper, 'payment-methods')).toContain('No payment arrived in this period.')
    expect(wrapper.find('[data-testid=popular-parts]').exists()).toBe(false)
    expect(text(wrapper, 'insight-pcs')).toContain('No PC was sold in this period.')
    expect(text(wrapper, 'suggestion-ai')).toContain('No suggestion in this period involved the AI assistant')
    expect(wrapper.text()).not.toContain('null')
    expect(wrapper.text()).not.toContain('NaN')
    // A purpose nobody chose is listed, with an empty bar.
    const bars = wrapper.findAll('[data-testid=suggestion-purposes] li > div[aria-hidden] > div')
    expect(bars).toHaveLength(3)
    expect(bars.map((b) => b.attributes('style'))).toEqual(['width: 0%;', 'width: 0%;', 'width: 0%;'])
  })

  it('does not ask for a period that ends before it starts', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')
    api.get.mockClear()

    await field(wrapper, 'From').setValue('2026-10-20')
    await field(wrapper, 'To').setValue('2026-10-01')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.get).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('The start date must not be after the end date.')
  })

  it('jumps to the last 7 days', async () => {
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')
    api.get.mockClear()

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Last 7 days')
      .trigger('click')
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/api/admin/reports/insights', {
      params: { from: '2026-10-14', to: '2026-10-20' },
    })
  })

  it("shows the server's reason when the insights cannot be loaded", async () => {
    api.get.mockRejectedValue({ message: 'Choose a period of at most 366 days.' })
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')

    expect(wrapper.find('[role=alert]').text()).toContain('Choose a period of at most 366 days.')
    expect(wrapper.find('[data-testid=insight-customers]').exists()).toBe(false)
  })

  it('keeps the newer answer when an older one arrives late', async () => {
    let answerFirst
    api.get.mockReturnValueOnce(new Promise((resolve) => (answerFirst = resolve)))
    const { wrapper } = await mountAdmin(Insights, '/admin/insights')

    api.get.mockResolvedValueOnce({ data: { data: QUIET } })
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Last 7 days')
      .trigger('click')
    await flushPromises()
    answerFirst({ data: { data: INSIGHTS } })
    await flushPromises()

    expect(cards(wrapper, 'insight-customers').Buyers).toBe('0')
  })
})

describe('the helpers', () => {
  it('writes a percentage, or a dash when there is none', () => {
    expect(percent(33.3)).toBe('33.3%')
    expect(percent(100)).toBe('100%')
    expect(percent(0)).toBe('0%')
    expect(percent(null)).toBe('—')
    expect(percent(undefined)).toBe('—')
  })

  it('says what is wrong with a period', () => {
    expect(periodProblem('2026-10-01', '2026-10-20')).toBe('')
    expect(periodProblem('2026-10-20', '2026-10-20')).toBe('')
    expect(periodProblem('', '2026-10-20')).toBe('Choose both dates.')
    expect(periodProblem('2026-10-20', '')).toBe('Choose both dates.')
    expect(periodProblem('2026-10-21', '2026-10-20')).toBe('The start date must not be after the end date.')
  })
})
