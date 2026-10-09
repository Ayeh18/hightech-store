import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import { api } from '@/lib/api'
import Reconciliation from '@/pages/admin/Reconciliation.vue'
import { button, cleanup, field, money, mountAdmin, signIn, status } from './adminFixtures'

const row = (n, overrides = {}) => ({
  payment_id: `P${n}`,
  provider: 'fake',
  provider_reference: `fakepay_${n}`,
  order: {
    id: `O${n}`,
    order_number: `ORD-2026-0000${n}`,
    total: money(101500),
    customer: { name: `Customer ${n}`, email: `c${n}@x.test` },
  },
  internal: { status: status('PAID', 'Paid'), amount: money(101500), refunded: money(0) },
  gateway: { state: 'succeeded', label: 'paid', amount: money(101500), refunded: money(0) },
  result: 'MATCHED',
  issues: [],
  can_sync: false,
  ...overrides,
})

const brief = row(1, {
  internal: { status: status('PAID', 'Paid'), amount: money(450200), refunded: money(0) },
  gateway: { state: 'succeeded', label: 'paid', amount: money(460200), refunded: money(0) },
  result: 'ERROR',
  issues: [
    {
      code: 'amount_differs',
      message: 'The payment company received RM 4,602.00, but this payment is for RM 4,502.00.',
      can_sync: false,
    },
  ],
})
const missed = row(2, {
  internal: { status: status('PENDING', 'Awaiting payment'), amount: money(101500), refunded: money(0) },
  result: 'ERROR',
  issues: [
    {
      code: 'gateway_says_paid',
      message:
        'The payment company says this was paid, but we have it as ‘Awaiting payment’. A payment notice was probably missed.',
      can_sync: true,
    },
  ],
  can_sync: true,
})

const report = (rows, summary = {}) => ({
  data: {
    data: {
      period: { from: '2026-10-03', to: '2026-10-09' },
      summary: {
        checked: rows.length,
        matched: rows.filter((r) => r.result === 'MATCHED').length,
        errors: rows.filter((r) => r.result === 'ERROR').length,
        gateway_received: money(560200),
        internal_recorded: money(550200),
        difference: money(10000),
        ...summary,
      },
      rows,
    },
  },
})

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-09T06:00:00Z'))
  signIn()
  api.get.mockResolvedValue(report([brief, missed, row(3)]))
})
afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

describe('payment reconciliation', () => {
  it('checks the last 7 days when it opens', async () => {
    await mountAdmin(Reconciliation, '/admin/reconciliation')

    expect(api.get).toHaveBeenCalledWith('/api/admin/reconciliation', {
      params: { from: '2026-10-03', to: '2026-10-09' },
    })
  })

  it("shows the payment company's books and ours side by side, as in the brief", async () => {
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    const first = wrapper.find('[data-testid=row-ERROR]')
    expect(first.find('[data-testid=gateway-amount]').text()).toBe('RM 4,602.00')
    expect(first.find('[data-testid=internal-amount]').text()).toBe('RM 4,502.00')
    expect(first.text()).toContain('RECONCILIATION ERROR')
    expect(first.text()).toContain('The payment company received RM 4,602.00, but this payment is for RM 4,502.00.')
  })

  it('says MATCHED for payments that agree', async () => {
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    const matched = wrapper.find('[data-testid=row-MATCHED]')
    expect(matched.text()).toContain('ORD-2026-00003')
    expect(matched.text()).toContain('MATCHED')
    expect(matched.text()).not.toContain('RECONCILIATION ERROR')
  })

  it('summarises how many agree and the gap between what the company received and what we recorded', async () => {
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    const cards = wrapper.findAll('[data-testid=stat-card]').map((c) => c.text())
    expect(cards[0]).toContain('3')
    expect(cards[1]).toContain('1')
    expect(cards[2]).toContain('2')
    expect(cards[3]).toContain('RM 5,602.00')
    expect(cards[4]).toContain('RM 5,502.00')
    expect(cards[4]).toContain('Difference RM 100.00')
  })

  it('can show only the errors', async () => {
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    await wrapper.find('input[type=checkbox]').setValue(true)

    expect(wrapper.findAll('[data-testid=row-MATCHED]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid=row-ERROR]')).toHaveLength(2)
  })

  it('says everything is fine when every payment agrees and only errors are wanted', async () => {
    api.get.mockResolvedValue(
      report([row(1), row(2)], {
        difference: money(0),
        gateway_received: money(203000),
        internal_recorded: money(203000),
      }),
    )
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    await wrapper.find('input[type=checkbox]').setValue(true)

    expect(wrapper.text()).toContain('No errors')
    expect(wrapper.findAll('[data-testid=stat-card]')[4].text()).toContain('Same as the payment company')
  })

  it('offers "ask again" only where a missed message explains it, and says a person is needed otherwise', async () => {
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    const errors = wrapper.findAll('[data-testid=row-ERROR]')
    expect(errors[0].text()).toContain('This needs a person') // the wrong amount
    expect(errors[0].findAll('button')).toHaveLength(0)
    expect(errors[1].text()).toContain('A payment notice was probably missed')
    expect(errors[1].findAll('button').map((b) => b.text())).toEqual(['Ask the payment company again'])
  })

  it('asks the payment company again, then checks again and says what happened', async () => {
    api.post.mockResolvedValue({ data: { data: row(2) } })
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')
    api.get.mockResolvedValue(report([brief, row(2), row(3)]))

    await button(wrapper, 'Ask the payment company again').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/admin/payments/P2/sync')
    expect(wrapper.text()).toContain('ORD-2026-00002 now matches the payment company.')
    expect(wrapper.findAll('[data-testid=row-ERROR]')).toHaveLength(1)
  })

  it('explains when something still differs after asking again', async () => {
    api.post.mockResolvedValue({ data: { data: { ...missed, order: missed.order } } })
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    await button(wrapper, 'Ask the payment company again').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('was updated, but something still differs')
  })

  it("shows the server's reason when asking again is refused", async () => {
    api.post.mockRejectedValue({
      message: 'x',
      errors: { payment: ['Asking the payment company again cannot fix this one. It needs a person to look into it.'] },
    })
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    await button(wrapper, 'Ask the payment company again').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('needs a person to look into it')
  })

  it('does not ask for a period that ends before it starts', async () => {
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')
    api.get.mockClear()

    await field(wrapper, 'From').setValue('2026-10-09')
    await field(wrapper, 'To').setValue('2026-10-01')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.get).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('The start date must not be after the end date.')
  })

  it("shows the server's refusal of a period with too many payments", async () => {
    api.get.mockRejectedValue({
      message: 'x',
      errors: {
        to: ['There are 900 payments in this period; at most 500 can be checked at once. Choose a shorter period.'],
      },
    })
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    expect(wrapper.text()).toContain('Choose a shorter period.')
  })

  it('says so when there is nothing to check', async () => {
    api.get.mockResolvedValue(
      report([], { gateway_received: money(0), internal_recorded: money(0), difference: money(0) }),
    )
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    expect(wrapper.text()).toContain('No payments in this period')
  })

  it("links each row to the payment's diary", async () => {
    const { wrapper } = await mountAdmin(Reconciliation, '/admin/reconciliation')

    expect(wrapper.find('[data-testid=row-MATCHED] a').attributes('href')).toBe('/admin/payments/P3')
  })
})
