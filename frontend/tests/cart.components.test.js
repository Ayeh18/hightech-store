import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CartBuildLine from '@/components/cart/CartBuildLine.vue'
import CartProductLine from '@/components/cart/CartProductLine.vue'
import CartSummary from '@/components/cart/CartSummary.vue'
import QuantityStepper from '@/components/ui/QuantityStepper.vue'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const RouterLink = { props: ['to'], template: '<a :href="JSON.stringify(to)"><slot /></a>' }
const global = { stubs: { RouterLink } }

const product = (name = 'Test GPU') => ({ id: 1, slug: 'test-gpu', name, brand: { name: 'Brand' }, image: null })
const productLine = (overrides = {}) => ({
  id: 5,
  kind: 'product',
  name: 'Test GPU',
  quantity: 2,
  unit_price: money(150000),
  line_total: money(300000),
  product: product(),
  components: [],
  compatibility: null,
  issues: [],
  purchasable: true,
  ...overrides,
})
const buildLine = (overrides = {}) => ({
  id: 6,
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
      product: product('Ryzen 5'),
      on_sale: true,
      unit_price: money(80000),
      price_when_added: money(80000),
    },
    {
      slot: 'ram',
      slot_label: 'RAM',
      quantity: 2,
      product: product('DDR5 kit'),
      on_sale: true,
      unit_price: money(35000),
      price_when_added: money(30000),
    },
  ],
  compatibility: { status: 'compatible', issues: [] },
  issues: [],
  purchasable: true,
  ...overrides,
})

describe('QuantityStepper', () => {
  it('steps within its limits and emits the new value', async () => {
    const wrapper = mount(QuantityStepper, { props: { modelValue: 2, min: 1, max: 3 } })
    const [minus, plus] = wrapper.findAll('button')

    await plus.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([[3]])

    await wrapper.setProps({ modelValue: 3 })
    expect(wrapper.findAll('button')[1].attributes('disabled')).toBeDefined() // cannot go above max
    await wrapper.setProps({ modelValue: 1 })
    expect(wrapper.findAll('button')[0].attributes('disabled')).toBeDefined() // cannot go below min
    expect(minus.exists()).toBe(true)
  })

  it('is fully disabled while busy', () => {
    const wrapper = mount(QuantityStepper, { props: { modelValue: 2, disabled: true } })
    expect(wrapper.findAll('button').every((b) => b.attributes('disabled') !== undefined)).toBe(true)
  })
})

describe('CartProductLine', () => {
  it('shows the product, unit price and line total, and reports quantity changes and removal', async () => {
    const wrapper = mount(CartProductLine, { props: { line: productLine() }, global })

    expect(wrapper.text()).toContain('Test GPU')
    expect(wrapper.text()).toContain('RM 1500.00 each')
    expect(wrapper.find('[data-testid=line-total]').text()).toBe('RM 3000.00')

    await wrapper
      .findAll('button')
      .find((b) => b.attributes('aria-label')?.startsWith('Increase'))
      .trigger('click')
    expect(wrapper.emitted('quantity')).toEqual([[3]])

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Remove')
      .trigger('click')
    expect(wrapper.emitted('remove')).toHaveLength(1)
  })

  it('shows server problems in red and a withdrawn product as unavailable', () => {
    const wrapper = mount(CartProductLine, {
      props: {
        line: productLine({
          unit_price: null,
          line_total: money(0),
          issues: [{ code: 'product_unavailable', severity: 'error', message: 'Test GPU is no longer available.' }],
        }),
      },
      global,
    })

    expect(wrapper.text()).toContain('Test GPU is no longer available.')
    expect(wrapper.text()).toContain('No longer available')
    expect(wrapper.find('[data-testid=line-total]').text()).toBe('—')
    expect(wrapper.find('article').classes().join(' ')).toContain('border-danger')
  })

  it('disables the controls while a change is in flight', () => {
    const wrapper = mount(CartProductLine, { props: { line: productLine(), busy: true }, global })
    expect(wrapper.findAll('button').every((b) => b.attributes('disabled') !== undefined)).toBe(true)
  })
})

describe('CartBuildLine', () => {
  it('lists the parts of the PC and its compatibility', () => {
    const wrapper = mount(CartBuildLine, { props: { line: buildLine() }, global })

    expect(wrapper.text()).toContain('Complete PC build')
    expect(wrapper.text()).toContain('Gaming PC')
    expect(wrapper.text()).toContain('✓ Compatible')
    expect(wrapper.text()).toContain('2 parts in this PC')
    expect(wrapper.text()).toContain('Ryzen 5')
    expect(wrapper.text()).toContain('DDR5 kit')
    expect(wrapper.text()).toContain('×2')
  })

  it('strikes through the old price only for parts whose price changed', () => {
    const wrapper = mount(CartBuildLine, { props: { line: buildLine() }, global })
    const struck = wrapper.findAll('s').map((s) => s.text())

    expect(struck).toEqual(['RM 300.00']) // the RAM kit went from 300 to 350; the CPU did not change
  })

  it('shows an incompatible build clearly, with the reason', () => {
    const wrapper = mount(CartBuildLine, {
      props: {
        line: buildLine({
          compatibility: { status: 'incompatible', issues: [] },
          issues: [
            {
              code: 'incompatible',
              severity: 'error',
              message: 'Gaming PC: CPU socket AM5 is not compatible with motherboard socket AM4.',
            },
          ],
        }),
      },
      global,
    })

    expect(wrapper.text()).toContain('✗ Incompatible')
    expect(wrapper.text()).toContain('CPU socket AM5 is not compatible')
  })

  it('reports copies changes and removal, and marks a withdrawn part', async () => {
    const line = buildLine()
    line.components[0] = { ...line.components[0], on_sale: false, unit_price: null }
    const wrapper = mount(CartBuildLine, { props: { line }, global })

    expect(wrapper.text()).toContain('Unavailable')
    await wrapper
      .findAll('button')
      .find((b) => b.attributes('aria-label')?.startsWith('Increase'))
      .trigger('click')
    expect(wrapper.emitted('quantity')).toEqual([[2]])
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Remove')
      .trigger('click')
    expect(wrapper.emitted('remove')).toHaveLength(1)
  })
})

describe('CartSummary', () => {
  it('shows the subtotal and item count', () => {
    const wrapper = mount(CartSummary, { props: { subtotal: money(294690), itemCount: 3, checkoutReady: true } })

    expect(wrapper.find('[data-testid=subtotal]').text()).toBe('RM 2946.90')
    expect(wrapper.find('[data-testid=item-count]').text()).toBe('3')
    expect(wrapper.text()).toContain('Everything in your cart is available.')
  })

  it('offers to accept new prices when they changed', async () => {
    const wrapper = mount(CartSummary, {
      props: { subtotal: money(100), itemCount: 1, checkoutReady: true, hasPriceChanges: true },
    })

    expect(wrapper.text()).toContain('Some prices changed')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Accept new prices')
      .trigger('click')
    expect(wrapper.emitted('accept-prices')).toHaveLength(1)
  })

  it('says what to do when something blocks checkout, and stays quiet for an empty cart', () => {
    const blocked = mount(CartSummary, { props: { subtotal: money(100), itemCount: 1, checkoutReady: false } })
    expect(blocked.text()).toContain('Fix the items marked in red')

    const empty = mount(CartSummary, { props: { subtotal: null, itemCount: 0, checkoutReady: false } })
    expect(empty.text()).not.toContain('Fix the items')
    expect(empty.text()).toContain('RM 0.00')
    expect(
      empty
        .findAll('button')
        .find((b) => b.text() === 'Clear cart')
        .attributes('disabled'),
    ).toBeDefined()
  })
})
