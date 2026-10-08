import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PriceTag from '@/components/products/PriceTag.vue'
import ProductCard from '@/components/products/ProductCard.vue'
import StockBadge from '@/components/products/StockBadge.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const RouterLink = { props: ['to'], template: '<a :href="JSON.stringify(to)"><slot /></a>' }

const product = (overrides = {}) => ({
  id: 1,
  slug: 'amd-ryzen-5-7600',
  name: 'AMD Ryzen 5 7600',
  brand: { slug: 'amd', name: 'AMD' },
  price: money(69900),
  effective_price: money(69900),
  on_sale: false,
  image: null,
  highlights: [{ key: 'socket', label: 'Socket', display: 'AM5' }],
  in_stock: true,
  low_stock: false,
  ...overrides,
})

describe('PriceTag', () => {
  it('shows only the price when there is no discount', () => {
    const wrapper = mount(PriceTag, { props: { price: money(69900), effective: money(69900) } })
    expect(wrapper.text()).toContain('RM 699.00')
    expect(wrapper.find('s').exists()).toBe(false)
  })

  it('shows the old price struck through and the percentage off, computed from sen', () => {
    const wrapper = mount(PriceTag, { props: { price: money(69900), effective: money(64900) } })
    expect(wrapper.find('s').text()).toBe('RM 699.00')
    expect(wrapper.text()).toContain('RM 649.00')
    expect(wrapper.text()).toContain('-7%')
  })
})

describe('ProductCard', () => {
  it('links to the product page and shows brand, highlights, price and stock', () => {
    const wrapper = mount(ProductCard, { props: { product: product() }, global: { stubs: { RouterLink } } })

    expect(wrapper.find('a').attributes('href')).toContain('amd-ryzen-5-7600')
    expect(wrapper.text()).toContain('AMD Ryzen 5 7600')
    expect(wrapper.text()).toContain('AM5')
    expect(wrapper.text()).toContain('RM 699.00')
    expect(wrapper.text()).toContain('In stock')
    expect(wrapper.text()).not.toContain('Sale')
  })

  it('marks sale items and falls back to a placeholder when there is no photo', () => {
    const wrapper = mount(ProductCard, {
      props: { product: product({ on_sale: true, effective_price: money(64900) }) },
      global: { stubs: { RouterLink } },
    })

    expect(wrapper.text()).toContain('Sale')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('[role=img]').exists()).toBe(true)
  })

  it('renders the photo when one exists', () => {
    const wrapper = mount(ProductCard, {
      props: { product: product({ image: '/storage/products/1/a.webp' }) },
      global: { stubs: { RouterLink } },
    })
    expect(wrapper.find('img').attributes('src')).toBe('/storage/products/1/a.webp')
  })
})

describe('StockBadge', () => {
  it.each([
    [{ inStock: true, lowStock: false }, 'In stock'],
    [{ inStock: true, lowStock: true }, 'Low stock'],
    [{ inStock: false, lowStock: false }, 'Out of stock'],
  ])('renders %j as "%s"', (props, text) => {
    expect(mount(StockBadge, { props }).text()).toContain(text)
  })
})

describe('PaginationBar', () => {
  it('renders nothing for a single page', () => {
    expect(
      mount(PaginationBar, { props: { page: 1, lastPage: 1 } })
        .find('nav')
        .exists(),
    ).toBe(false)
  })

  it('collapses distant pages with a gap and marks the current page', () => {
    const wrapper = mount(PaginationBar, { props: { page: 10, lastPage: 20 } })
    const labels = wrapper.findAll('button').map((b) => b.text())

    expect(labels).toEqual(['Previous', '1', '9', '10', '11', '20', 'Next'])
    expect(wrapper.text()).toContain('…')
    expect(wrapper.find('[aria-current=page]').text()).toBe('10')
  })

  it('emits the requested page and disables Previous on the first page', async () => {
    const wrapper = mount(PaginationBar, { props: { page: 1, lastPage: 3 } })

    expect(wrapper.findAll('button')[0].attributes('disabled')).toBeDefined()
    await wrapper
      .findAll('button')
      .find((b) => b.text() === '3')
      .trigger('click')
    expect(wrapper.emitted('change')).toEqual([[3]])
  })
})
