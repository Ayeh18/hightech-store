import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import BrandsPanel from '@/components/admin/BrandsPanel.vue'
import CategoriesPanel from '@/components/admin/CategoriesPanel.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { api } from '@/lib/api'
import AdminHome from '@/pages/admin/AdminHome.vue'
import AuditLogs from '@/pages/admin/AuditLogs.vue'
import CompatibilityRules from '@/pages/admin/CompatibilityRules.vue'
import Coupons from '@/pages/admin/Coupons.vue'
import PaymentDetail from '@/pages/admin/PaymentDetail.vue'
import Payments from '@/pages/admin/Payments.vue'
import Reports from '@/pages/admin/Reports.vue'
import Users from '@/pages/admin/Users.vue'
import router from '@/router'
import {
  ALL_PERMISSIONS,
  STAFF_PERMISSIONS,
  at,
  button,
  cleanup,
  dialog,
  dialogButton,
  field,
  money,
  mountAdmin,
  signIn,
  status,
  submitDialog,
  typeInDialog,
} from './adminFixtures'

const page = (rows, meta = {}) => ({
  data: { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length, ...meta } },
})
let extra = []

beforeEach(() => {
  vi.clearAllMocks()
  signIn()
})
afterEach(() => {
  extra.forEach((w) => w.unmount())
  extra = []
  cleanup()
  vi.useRealTimers()
})

// ------------------------------------------------------------------------------------------------------ coupons
const coupon = (overrides = {}) => ({
  id: 1,
  code: 'WELCOME10',
  description: 'First order',
  type: 'percent',
  value: 1000,
  value_display: '10%',
  min_order_amount: money(100000),
  max_discount_amount: null,
  starts_at: null,
  ends_at: null,
  usage_limit: 100,
  per_user_limit: 1,
  is_active: true,
  used_count: 7,
  status: 'active',
  created_at: at(1),
  ...overrides,
})

describe('coupons', () => {
  it('lists each coupon with its discount, limits, use and status', async () => {
    api.get.mockResolvedValue(
      page([
        coupon(),
        coupon({
          id: 2,
          code: 'SAVE50',
          type: 'fixed',
          value: 5000,
          value_display: 'RM 50.00',
          min_order_amount: money(0),
          usage_limit: null,
          status: 'expired',
          used_count: 3,
        }),
      ]),
    )
    const { wrapper } = await mountAdmin(Coupons, '/admin/coupons')

    const rows = wrapper.findAll('[data-testid=coupons-table] tbody tr')
    expect(rows[0].text()).toContain('WELCOME10')
    expect(rows[0].text()).toContain('10%')
    expect(rows[0].text()).toContain('RM 1,000.00')
    expect(rows[0].text()).toContain('7 / 100')
    expect(rows[0].text()).toContain('Active')
    expect(rows[1].text()).toContain('RM 50.00')
    expect(rows[1].text()).toContain('3 / ∞')
    expect(rows[1].text()).toContain('Expired')
    expect(rows[1].text()).toContain('None') // no minimum order
  })

  it('creates a percentage coupon, sending basis points and sen and exact Malaysian moments', async () => {
    api.get.mockResolvedValue(page([]))
    api.post.mockResolvedValue({ data: { data: coupon() } })
    const { wrapper } = await mountAdmin(Coupons, '/admin/coupons')

    await button(wrapper, 'New coupon').trigger('click')
    await flushPromises()
    await typeInDialog('Code', ' launch25 ')
    await typeInDialog('Type', 'percent')
    await typeInDialog('Percentage off', '12.5')
    await typeInDialog('Minimum order', '1,000')
    await typeInDialog('Never take off more than', '200')
    await typeInDialog('Starts', '2026-11-01T00:00')
    await typeInDialog('Ends', '2026-11-30T23:59')
    await typeInDialog('Total uses allowed', '500')
    await submitDialog()

    expect(api.post).toHaveBeenCalledWith('/api/admin/coupons', {
      code: 'launch25',
      description: null,
      type: 'percent',
      value: 1250,
      min_order_amount: 100000,
      max_discount_amount: 20000,
      starts_at: '2026-10-31T16:00:00.000Z',
      ends_at: '2026-11-30T15:59:00.000Z',
      usage_limit: 500,
      per_user_limit: 1,
      is_active: true,
    })
    expect(dialog()).toBeNull()
    expect(wrapper.text()).toContain('LAUNCH25 created.')
  })

  it('creates a fixed-amount coupon, with no "never more than" cap', async () => {
    api.get.mockResolvedValue(page([]))
    api.post.mockResolvedValue({ data: { data: coupon() } })
    const { wrapper } = await mountAdmin(Coupons, '/admin/coupons')

    await button(wrapper, 'New coupon').trigger('click')
    await flushPromises()
    await typeInDialog('Code', 'SAVE50')
    await typeInDialog('Type', 'fixed')
    expect(
      [...dialog().querySelectorAll('label')].some((l) => l.textContent.startsWith('Never take off more than')),
    ).toBe(false)
    await typeInDialog('Amount off', '50')
    await submitDialog()

    expect(api.post.mock.calls[0][1]).toMatchObject({
      type: 'fixed',
      value: 5000,
      max_discount_amount: null,
      min_order_amount: 0,
    })
  })

  it.each([
    ['percent', '0', 'Enter a percentage from 0.01 to 100'],
    ['percent', '150', 'Enter a percentage from 0.01 to 100'],
    ['percent', 'ten', 'Enter a percentage from 0.01 to 100'],
    ['fixed', '12.345', 'Enter an amount in ringgit'],
    ['fixed', '', 'Enter an amount in ringgit'],
  ])('does not send a %s value of "%s"', async (type, value, message) => {
    api.get.mockResolvedValue(page([]))
    const { wrapper } = await mountAdmin(Coupons, '/admin/coupons')

    await button(wrapper, 'New coupon').trigger('click')
    await flushPromises()
    await typeInDialog('Code', 'TESTING')
    await typeInDialog('Type', type)
    await typeInDialog(type === 'percent' ? 'Percentage off' : 'Amount off', value)
    await submitDialog()

    expect(api.post).not.toHaveBeenCalled()
    expect(dialog().textContent).toContain(message)
  })

  it('shows the server refusing a code that already exists, next to the box', async () => {
    api.get.mockResolvedValue(page([]))
    api.post.mockRejectedValue({ message: 'x', errors: { code: ['The code has already been taken.'] } })
    const { wrapper } = await mountAdmin(Coupons, '/admin/coupons')

    await button(wrapper, 'New coupon').trigger('click')
    await flushPromises()
    await typeInDialog('Code', 'WELCOME10')
    await typeInDialog('Percentage off', '10')
    await submitDialog()

    expect(dialog().textContent).toContain('The code has already been taken.')
  })

  it('opens an existing coupon with its values as a person would type them', async () => {
    api.get.mockResolvedValue(
      page([
        coupon({
          value: 1250,
          max_discount_amount: money(20000),
          starts_at: '2026-10-31T16:00:00.000Z',
          ends_at: '2026-11-30T15:59:00.000Z',
        }),
      ]),
    )
    api.put.mockResolvedValue({ data: { data: coupon() } })
    const { wrapper } = await mountAdmin(Coupons, '/admin/coupons')

    await wrapper.find('button[aria-label="Edit WELCOME10"]').trigger('click')
    await flushPromises()
    const value = (label) =>
      field(
        {
          findAll: (s) =>
            [...dialog().querySelectorAll(s)].map((el) => ({
              text: () => el.textContent.trim(),
              attributes: (n) => el.getAttribute(n),
            })),
          find: (s) => ({ element: dialog().querySelector(s) }),
        },
        label,
      ).element.value

    expect(value('Percentage off')).toBe('12.5')
    expect(value('Minimum order')).toBe('1000.00')
    expect(value('Never take off more than')).toBe('200.00')
    expect(value('Starts')).toBe('2026-11-01T00:00')
    expect(value('Ends')).toBe('2026-11-30T23:59')
    await submitDialog()
    expect(api.put).toHaveBeenCalledWith(
      '/api/admin/coupons/1',
      expect.objectContaining({ value: 1250, max_discount_amount: 20000, ends_at: '2026-11-30T15:59:00.000Z' }),
    )
  })

  it('deletes a coupon after confirmation, and shows why when it is refused', async () => {
    api.get.mockResolvedValue(page([coupon()]))
    api.delete.mockRejectedValueOnce({
      message: 'x',
      errors: { coupon: ['This coupon has been used on orders, so it cannot be deleted. Switch it off instead.'] },
    })
    const { wrapper } = await mountAdmin(Coupons, '/admin/coupons')

    await wrapper.find('button[aria-label="Delete WELCOME10"]').trigger('click')
    await flushPromises()
    dialogButton('Delete').click()
    await flushPromises()
    expect(dialog().textContent).toContain('Switch it off instead')

    api.delete.mockResolvedValueOnce({})
    dialogButton('Delete').click()
    await flushPromises()
    expect(api.delete).toHaveBeenLastCalledWith('/api/admin/coupons/1')
    expect(wrapper.text()).toContain('WELCOME10 deleted.')
  })
})

// ------------------------------------------------------------------------------------------------ compatibility
const rule = (overrides = {}) => ({
  key: 'psu_has_headroom',
  name: 'PSU has headroom',
  description: 'The power supply should have spare capacity.',
  is_enabled: true,
  severity: 'warning',
  default_severity: 'warning',
  params: { headroom_percent: 20 },
  default_params: { headroom_percent: 20 },
  slots: ['psu'],
  ...overrides,
})

describe('compatibility rules', () => {
  it('lists each rule with its explanation and current settings', async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          rule(),
          rule({
            key: 'cpu_socket',
            name: 'CPU socket matches',
            description: 'Same socket.',
            severity: 'error',
            params: {},
            default_params: {},
          }),
        ],
      },
    })
    const { wrapper } = await mountAdmin(CompatibilityRules, '/admin/compatibility')

    expect(api.get).toHaveBeenCalledWith('/api/compatibility-rules')
    expect(wrapper.find('[data-testid=rule-psu_has_headroom]').text()).toContain('spare capacity')
    expect(wrapper.find('[data-testid=rule-psu_has_headroom] input[type=text]').element.value).toBe('20')
    expect(wrapper.find('[data-testid=rule-cpu_socket] select').element.value).toBe('error')
  })

  it('saves only when something changed, sending numbers as numbers', async () => {
    api.get.mockResolvedValue({ data: { data: [rule()] } })
    api.put.mockResolvedValue({
      data: { data: rule({ is_enabled: false, severity: 'error', params: { headroom_percent: 30 } }) },
    })
    const { wrapper } = await mountAdmin(CompatibilityRules, '/admin/compatibility')
    const save = () => wrapper.findAll('button').find((b) => b.text() === 'Save')

    expect(save().attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid=rule-psu_has_headroom] input[type=checkbox]').setValue(false)
    await wrapper.find('[data-testid=rule-psu_has_headroom] select').setValue('error')
    await wrapper.find('[data-testid=rule-psu_has_headroom] input[type=text]').setValue('30')
    expect(save().attributes('disabled')).toBeUndefined()
    await save().trigger('click')
    await flushPromises()

    expect(api.put).toHaveBeenCalledWith('/api/compatibility-rules/psu_has_headroom', {
      is_enabled: false,
      severity: 'error',
      params: { headroom_percent: 30 },
    })
    expect(wrapper.text()).toContain('Saved.')
    expect(save().attributes('disabled')).toBeDefined() // matches the server again
  })

  it("shows the server's problem with a setting next to it", async () => {
    api.get.mockResolvedValue({ data: { data: [rule()] } })
    api.put.mockRejectedValue({
      message: 'x',
      errors: { 'params.headroom_percent': ['The headroom percent must be between 0 and 100.'] },
    })
    const { wrapper } = await mountAdmin(CompatibilityRules, '/admin/compatibility')

    await wrapper.find('[data-testid=rule-psu_has_headroom] input[type=text]').setValue('500')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Save')
      .trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('must be between 0 and 100')
  })

  it('goes back to the defaults when asked, ready to be saved', async () => {
    api.get.mockResolvedValue({ data: { data: [rule({ severity: 'error', params: { headroom_percent: 5 } })] } })
    const { wrapper } = await mountAdmin(CompatibilityRules, '/admin/compatibility')

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Back to the defaults')
      .trigger('click')

    expect(wrapper.find('[data-testid=rule-psu_has_headroom] input[type=text]').element.value).toBe('20')
    expect(wrapper.find('[data-testid=rule-psu_has_headroom] select').element.value).toBe('warning')
  })
})

// ------------------------------------------------------------------------------------------------------ users
const user = (id, overrides = {}) => ({
  id,
  name: `User ${id}`,
  email: `u${id}@x.test`,
  phone: null,
  roles: ['customer'],
  is_active: true,
  orders_count: 2,
  last_login_at: at(20),
  created_at: at(1),
  ...overrides,
})

describe('users', () => {
  it('lists people with roles, orders and sign-in, marking the signed-in admin', async () => {
    api.get.mockResolvedValue(
      page([
        user(1, { roles: ['admin'], name: 'Admin Tester' }),
        user(2),
        user(3, { is_active: false, last_login_at: null }),
      ]),
    )
    const { wrapper } = await mountAdmin(Users, '/admin/users')

    const rows = wrapper.findAll('[data-testid=users-table] tbody tr')
    expect(rows[0].text()).toContain('(you)')
    expect(rows[1].text()).toContain('customer')
    expect(rows[2].text()).toContain('Deactivated')
    expect(rows[2].text()).toContain('Never')
  })

  it('does not let admins change their own roles or deactivate themselves', async () => {
    api.get.mockResolvedValue(page([user(1, { roles: ['admin'] }), user(2)]))
    const { wrapper } = await mountAdmin(Users, '/admin/users')

    const mine = wrapper.findAll('[data-testid=users-table] tbody tr')[0]
    expect(mine.findAll('button').every((b) => b.attributes('disabled') !== undefined)).toBe(true)
    const theirs = wrapper.findAll('[data-testid=users-table] tbody tr')[1]
    expect(theirs.findAll('button').every((b) => b.attributes('disabled') === undefined)).toBe(true)
  })

  it('changes roles from a checklist that starts with what the person has now', async () => {
    api.get.mockResolvedValue(page([user(2, { roles: ['customer'] })]))
    api.put.mockResolvedValue({ data: { data: user(2, { roles: ['customer', 'staff'] }) } })
    const { wrapper } = await mountAdmin(Users, '/admin/users')

    await wrapper.find('button[aria-label="Change roles of User 2"]').trigger('click')
    await flushPromises()
    const boxes = [...dialog().querySelectorAll('input[type=checkbox]')]
    expect(boxes.map((b) => b.checked)).toEqual([true, false, false])
    boxes[1].click()
    await flushPromises()
    await submitDialog()

    expect(api.put).toHaveBeenCalledWith('/api/admin/users/2/roles', { roles: ['customer', 'staff'] })
    expect(wrapper.text()).toContain("User 2's roles were updated.")
  })

  it('cannot save without any role', async () => {
    api.get.mockResolvedValue(page([user(2)]))
    const { wrapper } = await mountAdmin(Users, '/admin/users')

    await wrapper.find('button[aria-label="Change roles of User 2"]').trigger('click')
    await flushPromises()
    ;[...dialog().querySelectorAll('input[type=checkbox]')][0].click()
    await flushPromises()

    expect(dialogButton('Save roles').disabled).toBe(true)
  })

  it("shows the server's reason, such as protecting the last admin", async () => {
    api.get.mockResolvedValue(page([user(2, { roles: ['admin'] })]))
    api.put.mockRejectedValue({
      message: 'x',
      errors: { roles: ['This is the last active admin, so the admin role cannot be removed.'] },
    })
    const { wrapper } = await mountAdmin(Users, '/admin/users')

    await wrapper.find('button[aria-label="Change roles of User 2"]').trigger('click')
    await flushPromises()
    await submitDialog()

    expect(dialog().textContent).toContain('last active admin')
  })

  it('deactivates after a warning that they are signed out at once, and can bring them back', async () => {
    api.get.mockResolvedValue(page([user(2), user(3, { is_active: false })]))
    api.put.mockResolvedValue({ data: { data: user(2, { is_active: false }) } })
    const { wrapper } = await mountAdmin(Users, '/admin/users')

    await wrapper.find('button[aria-label="Deactivate User 2"]').trigger('click')
    await flushPromises()
    expect(dialog().textContent).toContain('signed out straight away')
    dialogButton('Deactivate').click()
    await flushPromises()
    expect(api.put).toHaveBeenLastCalledWith('/api/admin/users/2/status', { is_active: false })
    expect(wrapper.text()).toContain('User 2 was deactivated and signed out.')

    await wrapper.find('button[aria-label="Activate User 3"]').trigger('click')
    await flushPromises()
    dialogButton('Activate').click()
    await flushPromises()
    expect(api.put).toHaveBeenLastCalledWith('/api/admin/users/3/status', { is_active: true })
  })
})

// ----------------------------------------------------------------------------------------------------- reports
const REPORT = {
  period: { from: '2026-09-21', to: '2026-10-20' },
  days: [
    {
      date: '2026-10-19',
      orders_placed: 2,
      orders_paid: 1,
      gross: money(101500),
      refunded: money(0),
      net: money(101500),
    },
    {
      date: '2026-10-20',
      orders_placed: 1,
      orders_paid: 1,
      gross: money(200000),
      refunded: money(5000),
      net: money(195000),
    },
  ],
  totals: {
    orders_placed: 3,
    orders_paid: 2,
    gross: money(301500),
    refunded: money(5000),
    net: money(296500),
    average_order: money(150750),
  },
  products: [{ id: 1, name: 'Ryzen 7', sku: 'CPU-1', units: 2, revenue: money(400000) }],
  revenue_by_category: [{ id: 1, name: 'Processors', units: 2, revenue: money(400000) }],
}

describe('sales reports', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-20T06:00:00Z'))
    api.get.mockResolvedValue({ data: { data: REPORT } })
  })

  it("opens on the last 30 days, in the shop's dates", async () => {
    await mountAdmin(Reports, '/admin/reports')

    expect(api.get).toHaveBeenCalledWith('/api/admin/reports/sales', {
      params: { from: '2026-09-21', to: '2026-10-20' },
    })
  })

  it('shows the totals, a row for each day, and what sold', async () => {
    const { wrapper } = await mountAdmin(Reports, '/admin/reports')

    const cards = wrapper.findAll('[data-testid=stat-card]').map((c) => c.text())
    expect(cards[0]).toContain('RM 2,965.00')
    expect(cards[3]).toContain('2')
    expect(cards[4]).toContain('RM 1,507.50')
    const days = wrapper.findAll('[data-testid=days-table] tbody tr')
    expect(days).toHaveLength(2)
    expect(days[1].text()).toContain('20 Oct')
    expect(days[1].text()).toContain('RM 50.00')
    expect(wrapper.find('[data-testid=days-table] tfoot').text()).toContain('RM 2,965.00')
    expect(wrapper.find('[data-testid=products-sold]').text()).toContain('Ryzen 7')
    expect(wrapper.find('[data-testid=categories-sold]').text()).toContain('Processors')
  })

  it('offers the same period as a spreadsheet download', async () => {
    const { wrapper } = await mountAdmin(Reports, '/admin/reports')

    expect(wrapper.find('[data-testid=csv-link]').attributes('href')).toBe(
      '/api/admin/reports/sales/export?from=2026-09-21&to=2026-10-20',
    )
    expect(wrapper.find('[data-testid=csv-link]').attributes('download')).toBeDefined()
  })

  it('does not ask for a period that ends before it starts', async () => {
    const { wrapper } = await mountAdmin(Reports, '/admin/reports')
    api.get.mockClear()

    await field(wrapper, 'From').setValue('2026-10-20')
    await field(wrapper, 'To').setValue('2026-10-01')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.get).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('The start date must not be after the end date.')
  })

  it('jumps to the last 7 or 90 days', async () => {
    const { wrapper } = await mountAdmin(Reports, '/admin/reports')
    api.get.mockClear()

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Last 7 days')
      .trigger('click')
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/api/admin/reports/sales', {
      params: { from: '2026-10-14', to: '2026-10-20' },
    })
  })
})

// ------------------------------------------------------------------------------------------------- audit log
const entry = (id, overrides = {}) => ({
  id,
  action: 'order.status_changed',
  actor: { id: 2, name: 'Staff Sam', email: 'sam@x.test' },
  subject: { type: 'Order', id: '01ABC' },
  old_values: { status: 'PAID' },
  new_values: { status: 'PROCESSING' },
  ip_address: '10.0.0.1',
  at: at(20),
  ...overrides,
})

describe('the audit log', () => {
  beforeEach(() => {
    api.get.mockImplementation(async (url) =>
      url === '/api/admin/audit-logs/actions'
        ? { data: { data: ['order.paid', 'product.updated'] } }
        : page([
            entry(1),
            entry(2, {
              action: 'order.paid',
              actor: null,
              old_values: null,
              new_values: null,
              subject: { type: 'Order', id: '01DEF' },
            }),
          ]),
    )
  })

  it('says who did what to what, newest first, with the system named as such', async () => {
    const { wrapper } = await mountAdmin(AuditLogs, '/admin/audit')

    const rows = wrapper.findAll('[data-testid=audit-table] tbody tr')
    expect(rows[0].text()).toContain('Staff Sam')
    expect(rows[0].text()).toContain('Order status changed')
    expect(rows[1].text()).toContain('System')
    expect(rows[1].text()).toContain('Order paid')
    expect(rows[0].find('a').attributes('href')).toBe('/admin/orders/01ABC')
  })

  it('shows what changed, from and to', async () => {
    const { wrapper } = await mountAdmin(AuditLogs, '/admin/audit')

    const details = wrapper.findAll('[data-testid=audit-table] details')
    expect(details).toHaveLength(1) // only entries that recorded values
    expect(details[0].text()).toContain('PAID')
    expect(details[0].text()).toContain('PROCESSING')
  })

  it('fills the action filter from what has really been recorded, and filters', async () => {
    vi.useFakeTimers()
    const { wrapper } = await mountAdmin(AuditLogs, '/admin/audit')
    api.get.mockClear()

    expect(
      wrapper
        .findAll('select')[0]
        .findAll('option')
        .map((o) => o.text()),
    ).toEqual(['Anything', 'Order paid', 'Product updated'])
    await wrapper.findAll('select')[0].setValue('order.paid')
    await vi.advanceTimersByTimeAsync(300)

    expect(api.get).toHaveBeenCalledWith('/api/admin/audit-logs', { params: { action: 'order.paid', page: 1 } })
  })

  it('explains an empty result', async () => {
    api.get.mockImplementation(async (url) =>
      url === '/api/admin/audit-logs/actions' ? { data: { data: [] } } : page([]),
    )
    const { wrapper } = await mountAdmin(AuditLogs, '/admin/audit')

    expect(wrapper.text()).toContain('Nothing recorded')
  })
})

// ---------------------------------------------------------------------------------------------------- payments
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
  order: { id: 'O1', order_number: 'ORD-2026-00001', customer: { name: 'Aisyah', email: 'a@x.test' } },
  ...overrides,
})

describe('payments', () => {
  it('lists every payment, flagging the ones that need a refund', async () => {
    api.get.mockResolvedValue(
      page([
        payment(),
        payment({
          id: 'P2',
          requires_refund: true,
          order: { id: 'O2', order_number: 'ORD-2026-00002', customer: { name: 'Budi', email: 'b@x.test' } },
        }),
      ]),
    )
    const { wrapper } = await mountAdmin(Payments, '/admin/payments')

    const rows = wrapper.findAll('[data-testid=payments-table] tbody tr')
    expect(rows[0].text()).toContain('ORD-2026-00001')
    expect(rows[0].text()).toContain('RM 1,015.00')
    expect(rows[1].text()).toContain('needs a refund')
    expect(rows[0].find('a').attributes('href')).toBe('/admin/payments/P1')
  })

  it('opens already filtered to failed payments, from the dashboard link', async () => {
    api.get.mockResolvedValue(page([]))
    await mountAdmin(Payments, '/admin/payments?status=FAILED', '/admin/payments')

    expect(api.get).toHaveBeenCalledWith('/api/admin/payments', { params: { status: 'FAILED', page: 1 } })
  })

  const detail = (overrides = {}) => ({
    ...payment(),
    transactions: [
      {
        id: 1,
        type: 'INITIATED',
        status_before: null,
        status_after: 'PENDING',
        amount: money(101500),
        provider_event_id: null,
        note: 'Payment page created at fake.',
        at: at(20, 8),
      },
      {
        id: 2,
        type: 'SUCCEEDED',
        status_before: 'PENDING',
        status_after: 'PAID',
        amount: money(101500),
        provider_event_id: 'evt_123',
        note: null,
        at: at(20, 9),
      },
    ],
    refunds: [],
    ...overrides,
  })

  it('shows the diary in words, with the status before and after each step', async () => {
    api.get.mockResolvedValue({ data: { data: detail() } })
    const { wrapper } = await mountAdmin(PaymentDetail, '/admin/payments/P1', '/admin/payments/:id')

    const rows = wrapper.findAll('[data-testid=diary] tbody tr')
    expect(rows[0].text()).toContain('Payment page created')
    expect(rows[0].text()).toContain('— → PENDING')
    expect(rows[1].text()).toContain('Paid')
    expect(rows[1].text()).toContain('evt_123')
    expect(rows[1].text()).toContain('PENDING → PAID')
    expect(wrapper.text()).toContain('fakepay_abc')
  })

  it('offers to send late money back in full', async () => {
    api.get.mockResolvedValue({ data: { data: detail({ requires_refund: true }) } })
    api.post.mockResolvedValue({ data: { data: { status: status('SUCCEEDED') } } })
    const { wrapper } = await mountAdmin(PaymentDetail, '/admin/payments/P1', '/admin/payments/:id')

    expect(wrapper.text()).toContain('This money needs to go back to the customer')
    await button(wrapper, 'Refund RM 1,015.00 in full').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/admin/payments/P1/refunds', { amount: 101500, reason: 'late_payment' })
  })
})

// ------------------------------------------------------------------------------------------------ catalog setup
describe('brands', () => {
  const brands = [
    { id: 1, slug: 'amd', name: 'AMD', products_count: 4 },
    { id: 2, slug: 'intel', name: 'Intel', products_count: 0 },
  ]

  it('lists, adds and renames', async () => {
    api.get.mockResolvedValue({ data: { data: brands } })
    api.post.mockResolvedValue({ data: { data: {} } })
    api.put.mockResolvedValue({ data: { data: {} } })
    const wrapper = mount(BrandsPanel, { attachTo: document.body })
    extra.push(wrapper)
    await flushPromises()

    expect(wrapper.find('[data-testid=brands-table]').text()).toContain('AMD')
    await button(wrapper, 'New brand').trigger('click')
    await flushPromises()
    await typeInDialog('Name', '  Corsair ')
    await submitDialog()
    expect(api.post).toHaveBeenCalledWith('/api/brands', { name: 'Corsair' })

    await wrapper.find('button[aria-label="Rename AMD"]').trigger('click')
    await flushPromises()
    await typeInDialog('Name', 'AMD Inc')
    await submitDialog()
    expect(api.put).toHaveBeenCalledWith('/api/brands/amd', { name: 'AMD Inc' })
  })

  it('shows why a brand with products cannot be deleted', async () => {
    api.get.mockResolvedValue({ data: { data: brands } })
    api.delete.mockRejectedValue({ message: 'x', errors: { brand: ['This brand still has 4 products.'] } })
    const wrapper = mount(BrandsPanel, { attachTo: document.body })
    extra.push(wrapper)
    await flushPromises()

    await wrapper.find('button[aria-label="Delete AMD"]').trigger('click')
    await flushPromises()
    dialogButton('Delete').click()
    await flushPromises()

    expect(api.delete).toHaveBeenCalledWith('/api/brands/amd')
    expect(dialog().textContent).toContain('still has 4 products')
  })
})

describe('categories and their specifications', () => {
  const categories = [
    {
      id: 1,
      slug: 'cpu',
      name: 'Processors',
      description: null,
      builder_slot: 'cpu',
      sort_order: 0,
      is_active: true,
      products_count: 5,
    },
  ]
  const defs = [
    {
      id: 9,
      key: 'socket',
      label: 'Socket',
      type: 'enum',
      unit: null,
      options: ['AM5', 'LGA1700'],
      is_required: true,
      is_filterable: true,
      show_on_card: false,
      sort_order: 0,
    },
  ]

  beforeEach(() => {
    api.get.mockImplementation(async (url) => {
      if (url === '/api/categories') return { data: { data: categories } }
      if (url === '/api/pc-builder/components')
        return {
          data: {
            data: [
              { slot: 'cpu', label: 'CPU' },
              { slot: 'ram', label: 'RAM' },
            ],
          },
        }
      if (url === '/api/categories/cpu') return { data: { data: { ...categories[0], spec_definitions: defs } } }
      throw new Error(`unexpected GET ${url}`)
    })
  })

  async function mountPanel() {
    const wrapper = mount(CategoriesPanel, { attachTo: document.body })
    extra.push(wrapper)
    await flushPromises()
    return wrapper
  }

  it('lists categories with the PC-builder slot named, not coded', async () => {
    const wrapper = await mountPanel()

    const row = wrapper.find('[data-testid=categories-table] tbody tr')
    expect(row.text()).toContain('Processors')
    expect(row.text()).toContain('CPU')
    expect(row.text()).toContain('5')
  })

  it('creates a category in a builder slot', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    const wrapper = await mountPanel()

    await button(wrapper, 'New category').trigger('click')
    await flushPromises()
    await typeInDialog('Name', 'Memory')
    await typeInDialog('Slot in the PC builder', 'ram')
    await submitDialog()

    expect(api.post).toHaveBeenCalledWith('/api/categories', {
      name: 'Memory',
      description: null,
      builder_slot: 'ram',
      sort_order: 0,
      is_active: true,
    })
  })

  it('lists the specifications of a category and adds one, with its choices one per line', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    const wrapper = await mountPanel()

    await wrapper.find('button[aria-label="Specifications of Processors"]').trigger('click')
    await flushPromises()
    expect(dialog().querySelector('[data-testid=spec-table]').textContent).toContain('Socket')
    expect(dialog().querySelector('[data-testid=spec-table]').textContent).toContain('AM5, LGA1700')

    ;[...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Add a specification').click()
    await flushPromises()
    await typeInDialog('Label', 'Memory type')
    await typeInDialog('Key', 'memory_type')
    await typeInDialog('Type', 'enum')
    await typeInDialog('The choices', 'DDR4\n DDR5 \n')
    await submitDialog()

    expect(api.post).toHaveBeenCalledWith('/api/categories/cpu/specs', {
      label: 'Memory type',
      unit: null,
      options: ['DDR4', 'DDR5'],
      is_required: false,
      is_filterable: false,
      show_on_card: false,
      sort_order: 0,
      key: 'memory_type',
      type: 'enum',
    })
  })

  it('cannot change the key or type of a specification that products already use', async () => {
    api.put.mockResolvedValue({ data: { data: {} } })
    const wrapper = await mountPanel()

    await wrapper.find('button[aria-label="Specifications of Processors"]').trigger('click')
    await flushPromises()
    dialog().querySelector('button[aria-label="Edit Socket"]').click()
    await flushPromises()

    const dialogWrapper = {
      findAll: (s) =>
        [...dialog().querySelectorAll(s)].map((el) => ({
          text: () => el.textContent.trim(),
          attributes: (n) => el.getAttribute(n),
        })),
      find: (s) => ({ element: dialog().querySelector(s) }),
    }
    expect(field(dialogWrapper, 'Key').element.disabled).toBe(true)
    expect(field(dialogWrapper, 'Type').element.disabled).toBe(true)
    await typeInDialog('Label', 'CPU socket')
    await submitDialog()
    expect(api.put).toHaveBeenCalledWith('/api/spec-definitions/9', expect.objectContaining({ label: 'CPU socket' }))
    expect(api.put.mock.calls[0][1]).not.toHaveProperty('key')
    expect(api.put.mock.calls[0][1]).not.toHaveProperty('type')
  })

  it('deletes a specification only after a warning', async () => {
    api.delete.mockResolvedValue({})
    const wrapper = await mountPanel()

    await wrapper.find('button[aria-label="Specifications of Processors"]').trigger('click')
    await flushPromises()
    dialog().querySelector('button[aria-label="Delete Socket"]').click()
    await flushPromises()
    expect(dialog().textContent).toContain('The values products hold for it are removed too')
    expect(api.delete).not.toHaveBeenCalled()
    ;[...dialog().querySelectorAll('button')]
      .filter((b) => b.textContent.trim() === 'Delete')
      .at(-1)
      .click()
    await flushPromises()

    expect(api.delete).toHaveBeenCalledWith('/api/spec-definitions/9')
  })
})

// --------------------------------------------------------------------------- the menu and who may open what
describe('the admin menu', () => {
  async function mountLayout(permissions, roles) {
    signIn(permissions, roles)
    const stub = { template: '<div />' }
    const r = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: stub },
        { path: '/admin/:rest(.*)*', name: 'any', component: stub },
      ],
    })
    r.push('/admin/dashboard')
    await r.isReady()
    const wrapper = mount(AdminLayout, { global: { plugins: [r] }, slots: { default: '<p>page</p>' } })
    extra.push(wrapper)

    return wrapper
  }
  const links = (wrapper) => wrapper.findAll('nav a').map((a) => a.text())

  it('shows an admin everything', async () => {
    const menu = links(await mountLayout(ALL_PERMISSIONS, ['admin']))

    expect(menu).toEqual([
      'Dashboard',
      'Sales reports',
      'Orders',
      'Refunds',
      'Payments',
      'Products',
      'Brands & categories',
      'Compatibility rules',
      'Coupons',
      'Users',
      'Audit log',
      '← Back to the shop',
    ])
  })

  it('shows staff only what their permissions allow', async () => {
    const menu = links(await mountLayout(STAFF_PERMISSIONS, ['staff']))

    expect(menu).toEqual([
      'Dashboard',
      'Sales reports',
      'Orders',
      'Products',
      'Brands & categories',
      '← Back to the shop',
    ])
    expect(menu).not.toContain('Refunds')
    expect(menu).not.toContain('Users')
    expect(menu).not.toContain('Audit log')
  })

  it('has a menu button for small screens, which opens and closes the navigation', async () => {
    const wrapper = await mountLayout(ALL_PERMISSIONS, ['admin'])
    const toggle = wrapper.find('header button')

    expect(toggle.attributes('aria-expanded')).toBe('false')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(toggle.text()).toBe('Close')
  })

  it('sends "/admin" to the first page the person may use', async () => {
    signIn(['admin.access', 'orders.manage'], ['staff'])
    const stub = { template: '<div />' }
    const r = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: stub },
        { path: '/admin', name: 'admin', component: AdminHome },
        { path: '/admin/orders', name: 'admin-orders', component: stub },
      ],
    })
    r.push('/admin')
    await r.isReady()
    const wrapper = mount(AdminHome, { global: { plugins: [r] } })
    extra.push(wrapper)
    await flushPromises()

    expect(r.currentRoute.value.fullPath).toBe('/admin/orders')
  })

  it('sends someone with no admin pages back to the shop', async () => {
    signIn(['admin.access'], ['staff'])
    const stub = { template: '<div />' }
    const r = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: stub },
        { path: '/admin', name: 'admin', component: AdminHome },
      ],
    })
    r.push('/admin')
    await r.isReady()
    const wrapper = mount(AdminHome, { global: { plugins: [r] } })
    extra.push(wrapper)
    await flushPromises()

    expect(r.currentRoute.value.name).toBe('home')
  })
})

describe('the admin routes', () => {
  const adminRoutes = router.getRoutes().filter((r) => r.path.startsWith('/admin'))

  it('are all behind a login, a permission that exists, and the admin layout', () => {
    expect(adminRoutes.length).toBeGreaterThanOrEqual(16)

    for (const route of adminRoutes) {
      expect(route.meta.requiresAuth, route.path).toBe(true)
      expect(route.meta.layout, route.path).toBe('admin')
      expect(ALL_PERMISSIONS, route.path).toContain(route.meta.permission)
    }
  })

  it('are all named, so links to them cannot break silently', () => {
    expect(adminRoutes.every((r) => typeof r.name === 'string' && r.name.startsWith('admin'))).toBe(true)
  })

  it('match the menu: every menu entry opens a real page', async () => {
    const { ADMIN_NAV } = await import('@/lib/adminNav')
    const paths = adminRoutes.map((r) => r.path)

    for (const item of ADMIN_NAV.flatMap((g) => g.items)) {
      expect(paths, item.label).toContain(item.to)
      expect(adminRoutes.find((r) => r.path === item.to).meta.permission, item.label).toBe(item.permission)
    }
  })
})
