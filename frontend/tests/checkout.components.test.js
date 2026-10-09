import { mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  parseApiError: (e) => ({ message: e.message, errors: e.errors ?? {} }),
}))

import AddressCard from '@/components/account/AddressCard.vue'
import AddressForm from '@/components/account/AddressForm.vue'
import CouponBox from '@/components/checkout/CouponBox.vue'
import OrderTotals from '@/components/checkout/OrderTotals.vue'
import OrderLines from '@/components/orders/OrderLines.vue'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })

describe('AddressForm', () => {
  const inputs = (wrapper) => wrapper.findAll('input')

  it('submits trimmed values, turning empty optional fields into null', async () => {
    const wrapper = mount(AddressForm, { global: { plugins: [createPinia()] } })
    const [name, phone, line1, line2, city, postcode, label] = inputs(wrapper).filter(
      (i) => i.attributes('type') !== 'checkbox',
    )
    await name.setValue('  Aisyah  ')
    await phone.setValue('012-3456789')
    await line1.setValue('12 Jalan Contoh')
    await line2.setValue('   ')
    await city.setValue('Petaling Jaya')
    await postcode.setValue('47810')
    await label.setValue('')
    await wrapper.find('select').setValue('Selangor')
    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('submit')[0][0]).toEqual({
      label: null,
      recipient_name: 'Aisyah',
      phone: '012-3456789',
      line1: '12 Jalan Contoh',
      line2: null,
      city: 'Petaling Jaya',
      state: 'Selangor',
      postcode: '47810',
      is_default: false,
    })
  })

  it('offers every Malaysian state and territory', () => {
    const wrapper = mount(AddressForm)
    const options = wrapper.findAll('option').map((o) => o.text())

    expect(options).toContain('Selangor')
    expect(options).toContain('Sabah')
    expect(options).toContain('Kuala Lumpur')
    expect(options.length).toBe(17) // 16 + the "Choose…" placeholder
  })

  it("shows the server's validation messages beside the fields", () => {
    const wrapper = mount(AddressForm, {
      props: { errors: { postcode: ['The postcode must be 5 digits.'], state: ['The selected state is invalid.'] } },
    })

    expect(wrapper.text()).toContain('The postcode must be 5 digits.')
    expect(wrapper.text()).toContain('The selected state is invalid.')
  })

  it('is filled in when editing, and only shows Cancel when it can be cancelled', async () => {
    const address = {
      id: 1,
      label: 'Home',
      recipient_name: 'Aisyah',
      phone: '0123456789',
      line1: 'A',
      line2: null,
      city: 'KL',
      state: 'Kuala Lumpur',
      postcode: '50000',
      is_default: true,
    }
    const wrapper = mount(AddressForm, { props: { address } })

    expect(wrapper.find('select').element.value).toBe('Kuala Lumpur')
    expect(wrapper.html()).toContain('50000')
    expect(wrapper.findAll('button').some((b) => b.text() === 'Cancel')).toBe(false)

    await wrapper.setProps({ cancellable: true })
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Cancel')
      .trigger('click')
    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })

  it('leaves the default checkbox out when it should not be asked', async () => {
    const wrapper = mount(AddressForm, { props: { showDefault: false } })
    await wrapper.find('form').trigger('submit')

    expect(wrapper.find('input[type=checkbox]').exists()).toBe(false)
    expect(wrapper.emitted('submit')[0][0]).not.toHaveProperty('is_default')
  })
})

describe('AddressCard', () => {
  it('shows the whole address with its label and default marker', () => {
    const wrapper = mount(AddressCard, {
      props: {
        address: {
          recipient_name: 'Aisyah',
          label: 'Home',
          is_default: true,
          phone: '012',
          line1: '12 Jalan A',
          line2: 'Unit 3',
          city: 'PJ',
          state: 'Selangor',
          postcode: '47810',
        },
      },
    })

    expect(wrapper.text()).toContain('Aisyah')
    expect(wrapper.text()).toContain('Home')
    expect(wrapper.text()).toContain('Default')
    expect(wrapper.text()).toContain('12 Jalan A, Unit 3')
    expect(wrapper.text()).toContain('47810 PJ, Selangor')
  })
})

describe('CouponBox', () => {
  it('applies a typed code, ignoring blank input', async () => {
    const wrapper = mount(CouponBox)
    const apply = wrapper.findAll('button').find((b) => b.text() === 'Apply')
    expect(apply.attributes('disabled')).toBeDefined()

    await wrapper.find('input').setValue('  welcome10 ')
    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('apply')).toEqual([['welcome10']])
  })

  it("shows the server's reason when the code is refused", () => {
    const wrapper = mount(CouponBox, { props: { error: 'This coupon has expired.' } })

    expect(wrapper.text()).toContain('This coupon has expired.')
    expect(wrapper.find('input').attributes('aria-invalid')).toBe('true')
  })

  it('shows an applied coupon with a way to remove it', async () => {
    const wrapper = mount(CouponBox, { props: { appliedCode: 'WELCOME10' } })

    expect(wrapper.text()).toContain('WELCOME10 applied')
    expect(wrapper.find('input').exists()).toBe(false)
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Remove')
      .trigger('click')
    expect(wrapper.emitted('remove')).toHaveLength(1)
  })
})

describe('OrderTotals', () => {
  it('lists subtotal, discount, shipping and total', () => {
    const wrapper = mount(OrderTotals, {
      props: {
        subtotal: money(200000),
        discount: money(20000),
        shipping: money(1500),
        total: money(181500),
        couponCode: 'WELCOME10',
      },
    })

    expect(wrapper.find('[data-testid=subtotal]').text()).toBe('RM 2000.00')
    expect(wrapper.find('[data-testid=discount]').text()).toBe('−RM 200.00')
    expect(wrapper.text()).toContain('(WELCOME10)')
    expect(wrapper.find('[data-testid=shipping]').text()).toBe('RM 15.00')
    expect(wrapper.find('[data-testid=total]').text()).toBe('RM 1815.00')
  })

  it('hides a zero discount, says "Free" for free shipping and asks for an address when shipping is unknown', () => {
    const free = mount(OrderTotals, {
      props: { subtotal: money(400000), discount: money(0), shipping: money(0), total: money(400000) },
    })
    expect(free.find('[data-testid=discount]').exists()).toBe(false)
    expect(free.find('[data-testid=shipping]').text()).toBe('Free')

    const unknown = mount(OrderTotals, { props: { subtotal: money(100), shipping: null, total: money(100) } })
    expect(unknown.find('[data-testid=shipping]').text()).toBe('Choose an address')
  })
})

describe('OrderStatusBadge', () => {
  it('colours by meaning and labels what it is', () => {
    const paid = mount(OrderStatusBadge, { props: { status: { value: 'PAID', label: 'Paid' }, kind: 'payment' } })
    expect(paid.text()).toContain('Payment: Paid')
    expect(paid.classes().join(' ')).toContain('text-success')

    const waiting = mount(OrderStatusBadge, {
      props: { status: { value: 'PENDING_PAYMENT', label: 'Waiting for payment' } },
    })
    expect(waiting.text()).toContain('Order: Waiting for payment')
    expect(waiting.classes().join(' ')).toContain('text-warning')

    const cancelled = mount(OrderStatusBadge, { props: { status: { value: 'CANCELLED', label: 'Cancelled' } } })
    expect(cancelled.classes().join(' ')).toContain('text-danger')
  })
})

describe('OrderLines', () => {
  it('shows products, and a PC with its parts grouped together', () => {
    const lines = [
      {
        kind: 'product',
        name: 'Test GPU',
        sku: 'GPU-1',
        quantity: 2,
        unit_price: money(150000),
        line_total: money(300000),
      },
      {
        kind: 'build',
        name: 'Gaming PC',
        copies: 2,
        line_total: money(540000),
        parts: [
          {
            slot: 'cpu',
            slot_label: 'CPU',
            name: 'Ryzen 5',
            sku: 'CPU-1',
            quantity: 2,
            unit_price: money(80000),
            line_total: money(160000),
          },
        ],
      },
    ]
    const wrapper = mount(OrderLines, { props: { lines } })

    expect(wrapper.text()).toContain('Test GPU')
    expect(wrapper.text()).toContain('SKU GPU-1 · 2 × RM 1500.00')
    expect(wrapper.text()).toContain('RM 3000.00')
    expect(wrapper.text()).toContain('Complete PC build × 2')
    expect(wrapper.text()).toContain('Gaming PC')
    expect(wrapper.text()).toContain('Ryzen 5')
    expect(wrapper.text()).toContain('RM 5400.00')
  })
})
