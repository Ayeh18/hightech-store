import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CompatibilityStatus from '@/components/pc-builder/CompatibilityStatus.vue'
import ComponentCard from '@/components/pc-builder/ComponentCard.vue'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const slotLabels = { cpu: 'CPU', motherboard: 'Motherboard', psu: 'Power supply' }
const issue = (severity, message, slots, rule = 'rule') => ({ rule, severity, message, slots })

const status = (props) => mount(CompatibilityStatus, { props: { slotLabels, partCount: 3, ...props } })

describe('CompatibilityStatus', () => {
  it('invites the customer to add parts when the build is empty', () => {
    const wrapper = status({ partCount: 0, status: null })
    expect(wrapper.text()).toContain('Add parts')
    expect(wrapper.text()).not.toContain('compatible')
  })

  it('does not claim compatibility with a single part, there is nothing to compare', () => {
    expect(status({ partCount: 1, status: 'compatible' }).text()).toContain('No conflicts so far')
  })

  it('shows the green all-clear for a compatible build', () => {
    const wrapper = status({ status: 'compatible' })
    expect(wrapper.text()).toContain('All components are compatible')
    expect(wrapper.text()).toContain('✓')
  })

  it('lists each problem with the parts involved when incompatible', () => {
    const wrapper = status({
      status: 'incompatible',
      issues: [issue('error', 'CPU socket AM5 is not compatible with motherboard socket AM4.', ['cpu', 'motherboard'])],
    })

    expect(wrapper.text()).toContain('Compatibility issues found')
    expect(wrapper.text()).toContain('1 problem to fix')
    expect(wrapper.text()).toContain('CPU socket AM5 is not compatible with motherboard socket AM4.')
    expect(wrapper.text()).toContain('CPU · Motherboard')
    expect(wrapper.text()).toContain('✗')
  })

  it('shows warnings without calling the build broken', () => {
    const wrapper = status({ status: 'warning', issues: [issue('warning', 'Only 450 W.', ['psu'])] })

    expect(wrapper.text()).toContain('Compatible, with warnings')
    expect(wrapper.text()).toContain('⚠')
    expect(wrapper.text()).not.toContain('to fix')
  })

  it('shows the estimated power and recommended PSU when known', () => {
    const wrapper = status({
      status: 'compatible',
      metrics: { estimated_power_watts: 420, recommended_psu_watts: 550 },
    })

    expect(wrapper.text()).toContain('420 W')
    expect(wrapper.text()).toContain('550 W+')
  })

  it('marks an out-of-date verdict as updating', () => {
    expect(status({ status: 'incompatible', stale: true, issues: [issue('error', 'x', ['cpu'])] }).text()).toContain(
      'Updating…',
    )
    expect(status({ status: 'compatible', stale: false }).text()).not.toContain('Updating…')
  })
})

describe('ComponentCard compatibility', () => {
  const product = (compatibility, overrides = {}) => ({
    id: 1,
    name: 'Board X',
    brand: { name: 'Brand' },
    price: money(10000),
    effective_price: money(10000),
    highlights: [],
    in_stock: true,
    low_stock: false,
    image: null,
    compatibility,
    ...overrides,
  })

  it('confirms a compatible part', () => {
    const wrapper = mount(ComponentCard, { props: { product: product({ status: 'compatible', issues: [] }) } })

    expect(wrapper.text()).toContain('Compatible with your build')
    expect(wrapper.find('button').text()).toBe('Select')
  })

  it('shows why a part does not fit, and still lets the customer pick it knowingly', async () => {
    const wrapper = mount(ComponentCard, {
      props: {
        product: product({
          status: 'incompatible',
          issues: [
            issue('error', 'CPU socket AM5 is not compatible with motherboard socket AM4.', ['cpu', 'motherboard']),
          ],
        }),
      },
    })

    expect(wrapper.text()).toContain('CPU socket AM5 is not compatible with motherboard socket AM4.')
    expect(wrapper.text()).not.toContain('Compatible with your build')
    expect(wrapper.find('button').text()).toBe('Select anyway')

    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('select')).toHaveLength(1)
  })

  it('shows warnings under the part', () => {
    const wrapper = mount(ComponentCard, {
      props: {
        product: product({
          status: 'warning',
          issues: [issue('warning', 'Rated for 150 W but the CPU is 170 W.', ['cooler'])],
        }),
      },
    })

    expect(wrapper.text()).toContain('Rated for 150 W')
    expect(wrapper.find('button').text()).toBe('Select')
  })

  it('works for parts without compatibility data (empty build) and for out-of-stock parts', () => {
    const plain = mount(ComponentCard, { props: { product: product(undefined) } })
    expect(plain.text()).not.toContain('Compatible with your build')

    const soldOut = mount(ComponentCard, {
      props: { product: product({ status: 'compatible', issues: [] }, { in_stock: false }) },
    })
    expect(soldOut.find('button').text()).toBe('Out of stock')
    expect(soldOut.find('button').attributes('disabled')).toBeDefined()
  })
})
