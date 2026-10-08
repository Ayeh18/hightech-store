import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ProductSortSelect from '@/components/products/ProductSortSelect.vue'

const labels = (wrapper) => wrapper.findAll('option').map((o) => o.text())

describe('ProductSortSelect', () => {
  it('hides "Most relevant" when there is no search text', () => {
    const wrapper = mount(ProductSortSelect, { props: { modelValue: 'popular', searching: false } })
    expect(labels(wrapper)).toEqual(['Most popular', 'Newest', 'Price: low to high', 'Price: high to low'])
  })

  it('keeps the right option selected when "Most relevant" appears together with the new value', async () => {
    const wrapper = mount(ProductSortSelect, {
      props: { modelValue: 'popular', searching: false },
      attachTo: document.body,
    })

    // Same render: the search text appears, the option list grows, and the value becomes "relevance".
    await wrapper.setProps({ searching: true, modelValue: 'relevance' })

    expect(labels(wrapper)[0]).toBe('Most relevant')
    expect(wrapper.find('select').element.value).toBe('relevance')
    expect(wrapper.find('select').element.selectedIndex).toBeGreaterThanOrEqual(0)
    wrapper.unmount()
  })

  it('emits the chosen sort', async () => {
    const wrapper = mount(ProductSortSelect, { props: { modelValue: 'popular' } })
    await wrapper.find('select').setValue('price_asc')
    expect(wrapper.emitted('update:modelValue')).toEqual([['price_asc']])
  })
})
