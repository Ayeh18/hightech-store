import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BuildSummary from '@/components/pc-builder/BuildSummary.vue'

const build = (overrides = {}) => ({
  rows: [],
  requiredFilled: 6,
  requiredSlots: new Array(6),
  missingRequired: [],
  isEmpty: false,
  totalFormatted: 'RM 2,700.00',
  verified: true,
  purchasable: true,
  blockingCount: 0,
  issues: [],
  pricesChanged: false,
  validateError: '',
  buildId: null,
  saving: false,
  ...overrides,
})
const cartButton = (wrapper) => wrapper.findAll('button').find((b) => /Add build to cart|Log in to buy/.test(b.text()))

describe('BuildSummary: add build to cart', () => {
  it('is enabled only when the server says the build can be bought', async () => {
    const wrapper = mount(BuildSummary, { props: { build: build(), isLoggedIn: true } })

    expect(cartButton(wrapper).attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-testid=cart-hint]').exists()).toBe(false)

    await cartButton(wrapper).trigger('click')
    expect(wrapper.emitted('add-to-cart')).toHaveLength(1)
  })

  it('explains why it is disabled: required parts missing', () => {
    const wrapper = mount(BuildSummary, {
      props: { build: build({ purchasable: false, missingRequired: ['Case'] }), isLoggedIn: true },
    })

    expect(cartButton(wrapper).attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid=cart-hint]').text()).toBe(
      'Choose the required parts to add this build to your cart.',
    )
  })

  it('explains why it is disabled: problems to fix', () => {
    const wrapper = mount(BuildSummary, {
      props: { build: build({ purchasable: false, blockingCount: 2 }), isLoggedIn: true },
    })

    expect(wrapper.find('[data-testid=cart-hint]').text()).toBe(
      'Fix the problems above to add this build to your cart.',
    )
  })

  it('says it is still confirming prices while the server has not answered', () => {
    const wrapper = mount(BuildSummary, {
      props: { build: build({ purchasable: false, verified: false }), isLoggedIn: true },
    })

    expect(wrapper.find('[data-testid=cart-hint]').text()).toBe('Confirming prices…')
  })

  it('asks guests to log in', () => {
    const wrapper = mount(BuildSummary, { props: { build: build(), isLoggedIn: false } })

    expect(cartButton(wrapper).text()).toBe('Log in to buy')
  })

  it('has no cart hint for an empty builder', () => {
    const wrapper = mount(BuildSummary, {
      props: {
        build: build({ isEmpty: true, purchasable: false, requiredFilled: 0, missingRequired: ['CPU'] }),
        isLoggedIn: true,
      },
    })

    expect(wrapper.find('[data-testid=cart-hint]').exists()).toBe(false)
  })
})
