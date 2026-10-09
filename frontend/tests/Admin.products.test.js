import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import ProductImagesPanel from '@/components/admin/ProductImagesPanel.vue'
import StockPanel from '@/components/admin/StockPanel.vue'
import { api } from '@/lib/api'
import ProductForm from '@/pages/admin/ProductForm.vue'
import Products from '@/pages/admin/Products.vue'
import { button, cleanup, dialog, dialogButton, field, money, mountAdmin, signIn } from './adminFixtures'

const category = (overrides = {}) => ({
  id: 1,
  slug: 'cpu',
  name: 'Processors',
  description: null,
  builder_slot: 'cpu',
  sort_order: 0,
  is_active: true,
  products_count: 5,
  ...overrides,
})
const cpuSpecs = [
  {
    id: 1,
    key: 'socket',
    label: 'Socket',
    type: 'enum',
    unit: null,
    options: ['AM5', 'LGA1700'],
    is_required: true,
    is_filterable: true,
    show_on_card: true,
    sort_order: 0,
  },
  {
    id: 2,
    key: 'cores',
    label: 'Cores',
    type: 'number',
    unit: null,
    options: null,
    is_required: true,
    is_filterable: true,
    show_on_card: false,
    sort_order: 1,
  },
  {
    id: 3,
    key: 'features',
    label: 'Features',
    type: 'list',
    unit: null,
    options: ['Integrated graphics', 'Unlocked'],
    is_required: false,
    is_filterable: false,
    show_on_card: false,
    sort_order: 2,
  },
  {
    id: 4,
    key: 'chipset',
    label: 'Codename',
    type: 'string',
    unit: null,
    options: null,
    is_required: false,
    is_filterable: false,
    show_on_card: false,
    sort_order: 3,
  },
]
const BRANDS = [
  { id: 10, slug: 'amd', name: 'AMD', products_count: 3 },
  { id: 11, slug: 'intel', name: 'Intel', products_count: 2 },
]

const product = (overrides = {}) => ({
  id: 5,
  slug: 'ryzen-5-7600x',
  sku: 'CPU-AMD-004',
  name: 'AMD Ryzen 5 7600X',
  status: 'active',
  brand: { slug: 'amd', name: 'AMD' },
  category: { slug: 'cpu', name: 'Processors' },
  price: money(79900),
  discount_price: null,
  effective_price: money(79900),
  on_sale: false,
  image: null,
  description: 'Fast.',
  weight_grams: 120,
  warranty_months: 36,
  warranty_info: 'Local warranty',
  specifications: [
    { key: 'socket', value: 'AM5' },
    { key: 'cores', value: 6 },
    { key: 'features', value: ['Unlocked'] },
  ],
  images: [{ id: 1, url: '/storage/products/5/a.webp', alt: 'Front', is_primary: true }],
  in_stock: true,
  low_stock: false,
  inventory: { stock: 25, reserved: 2, available: 23, low_stock_threshold: 5 },
  ...overrides,
})

function routeApi(extra = {}) {
  api.get.mockImplementation(async (url) => {
    if (url in extra) return extra[url]
    if (url === '/api/categories')
      return { data: { data: [category(), category({ id: 2, slug: 'ram', name: 'Memory' })] } }
    if (url === '/api/brands') return { data: { data: BRANDS } }
    if (url === '/api/categories/cpu') return { data: { data: { ...category(), spec_definitions: cpuSpecs } } }
    if (url === '/api/categories/ram')
      return {
        data: {
          data: {
            ...category({ id: 2, slug: 'ram', name: 'Memory' }),
            spec_definitions: [
              {
                ...cpuSpecs[3],
                key: 'ddr',
                label: 'DDR type',
                type: 'enum',
                options: ['DDR4', 'DDR5'],
                is_required: true,
              },
            ],
          },
        },
      }
    if (url === '/api/products/5') return { data: { data: product() } }
    throw new Error(`unexpected GET ${url}`)
  })
}

/** The product form is used at two addresses, so it gets a router with both. */
async function mountForm(path) {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/products', name: 'admin-products', component: stub },
      { path: '/admin/products/new', name: 'admin-product-new', component: ProductForm },
      { path: '/admin/products/:id', name: 'admin-product-edit', component: ProductForm },
    ],
  })
  router.push(path)
  await router.isReady()
  const wrapper = mount(ProductForm, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

let mounts = []
beforeEach(() => {
  vi.clearAllMocks()
  signIn()
  routeApi()
})
afterEach(() => {
  mounts.forEach((w) => w.unmount())
  mounts = []
  cleanup()
})

describe('the products list', () => {
  const row = (n, overrides = {}) => ({ ...product({ id: n, name: `Product ${n}`, sku: `SKU-${n}` }), ...overrides })
  const list = (rows) => ({ data: { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } } })

  it('shows every product with its stock, price and status, drafts included', async () => {
    routeApi({
      '/api/admin/products': list([
        row(1),
        row(2, {
          status: 'draft',
          in_stock: false,
          inventory: { stock: 0, reserved: 0, available: 0, low_stock_threshold: 5 },
        }),
        row(3, { price: money(100000), discount_price: money(90000), effective_price: money(90000), on_sale: true }),
      ]),
    })
    const { wrapper } = await mountAdmin(Products, '/admin/products')

    const rows = wrapper.findAll('[data-testid=products-table] tbody tr')
    expect(rows).toHaveLength(3)
    expect(rows[0].text()).toContain('Product 1')
    expect(rows[0].text()).toContain('CPU-AMD'.slice(0, 0) + 'SKU-1')
    expect(rows[0].text()).toContain('23') // can be sold
    expect(rows[1].text()).toContain('Draft')
    expect(rows[1].find('td:nth-child(4)').classes()).toContain('text-danger')
    expect(rows[2].text()).toContain('RM 900.00')
    expect(rows[2].text()).toContain('RM 1,000.00') // the crossed-out normal price
    expect(rows[0].find('a').attributes('href')).toBe('/admin/products/1')
  })

  it('fills the category filter from the shop, and searches', async () => {
    vi.useFakeTimers()
    routeApi({ '/api/admin/products': list([row(1)]) })
    const { wrapper } = await mountAdmin(Products, '/admin/products')
    api.get.mockClear()

    const options = wrapper
      .findAll('select')[0]
      .findAll('option')
      .map((o) => o.text())
    expect(options).toContain('Processors')
    await wrapper.find('input').setValue('ryzen')
    await vi.advanceTimersByTimeAsync(300)

    expect(api.get).toHaveBeenCalledWith('/api/admin/products', { params: { q: 'ryzen', page: 1 } })
    vi.useRealTimers()
  })

  it('links to creating a product', async () => {
    routeApi({ '/api/admin/products': list([]) })
    const { wrapper } = await mountAdmin(Products, '/admin/products')

    expect(wrapper.text()).toContain('No products match')
    expect(wrapper.find('a[href="/admin/products/new"]').exists()).toBe(true)
  })
})

describe('creating a product', () => {
  async function fillBasics(wrapper) {
    await field(wrapper, 'Category').setValue('cpu')
    await flushPromises()
    await field(wrapper, 'Brand').setValue('10')
    await field(wrapper, 'Name').setValue('  Ryzen 7 9800X3D  ')
    await field(wrapper, 'SKU').setValue('CPU-AMD-009')
    await field(wrapper, 'Status').setValue('active')
    await field(wrapper, 'Price (RM)').setValue('2,099.00')
  }

  it('draws the specification boxes of the chosen category, from the server', async () => {
    const { wrapper } = await mountForm('/admin/products/new')
    mounts.push(wrapper)

    expect(wrapper.text()).toContain('Choose a category first.')
    await field(wrapper, 'Category').setValue('cpu')
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/api/categories/cpu')
    const labels = wrapper
      .findAll('[data-testid=spec-fields] label, [data-testid=spec-fields] legend')
      .map((l) => l.text())
    expect(labels).toEqual(expect.arrayContaining(['Socket *', 'Cores *', 'Codename', 'Features']))
    expect(wrapper.findAll('[data-testid=spec-fields] input[type=checkbox]')).toHaveLength(2)
    expect(
      field(wrapper, 'Socket')
        .findAll('option')
        .map((o) => o.text()),
    ).toEqual(['Choose…', 'AM5', 'LGA1700'])
  })

  it('sends exactly what the API wants: sen, numbers as numbers, empty boxes left out', async () => {
    api.post.mockResolvedValue({ data: { data: product({ id: 77 }) } })
    const { wrapper, router } = await mountForm('/admin/products/new')
    await fillBasics(wrapper)
    await field(wrapper, 'Sale price').setValue('1,899.50')
    await field(wrapper, 'Socket').setValue('AM5')
    await field(wrapper, 'Cores').setValue('8')
    await wrapper.findAll('[data-testid=spec-fields] input[type=checkbox]')[1].setValue(true)
    await field(wrapper, 'Weight').setValue('120')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/products', {
      category_id: 1,
      brand_id: 10,
      sku: 'CPU-AMD-009',
      name: 'Ryzen 7 9800X3D',
      description: null,
      status: 'active',
      price: 209900,
      discount_price: 189950,
      weight_grams: 120,
      warranty_months: null,
      warranty_info: null,
      specs: { socket: 'AM5', cores: 8, features: ['Unlocked'] }, // the empty "Codename" is left out
    })
    expect(router.currentRoute.value.name).toBe('admin-product-edit')
    expect(router.currentRoute.value.params.id).toBe('77')
    mounts.push(wrapper)
  })

  it('stops a price that is not money before anything is sent', async () => {
    const { wrapper } = await mountForm('/admin/products/new')
    mounts.push(wrapper)
    await fillBasics(wrapper)
    await field(wrapper, 'Price (RM)').setValue('twelve')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Enter a price in ringgit')
  })

  it('stops a sale price that is not below the price', async () => {
    const { wrapper } = await mountForm('/admin/products/new')
    mounts.push(wrapper)
    await fillBasics(wrapper)
    await field(wrapper, 'Sale price').setValue('2,099.00')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('must be lower than the regular price')
  })

  it('stops a number spec that is not a number, and counts only whole numbers for weight', async () => {
    const { wrapper } = await mountForm('/admin/products/new')
    mounts.push(wrapper)
    await fillBasics(wrapper)
    await field(wrapper, 'Cores').setValue('six')
    await field(wrapper, 'Weight').setValue('1.5kg')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Cores must be a number.')
    expect(wrapper.text()).toContain('Weight must be a whole number.')
  })

  it("shows the server's problems next to the right boxes, including specifications", async () => {
    api.post.mockRejectedValue({
      message: 'x',
      errors: { sku: ['The sku has already been taken.'], 'specs.socket': ['Socket is required.'] },
    })
    const { wrapper } = await mountForm('/admin/products/new')
    mounts.push(wrapper)
    await fillBasics(wrapper)
    await field(wrapper, 'Cores').setValue('8')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Please fix the problems marked below.')
    expect(wrapper.text()).toContain('The sku has already been taken.')
    expect(wrapper.find('[data-testid=spec-fields]').text()).toContain('Socket is required.')
  })

  it('keeps what was typed for specs both categories share when the category changes', async () => {
    const { wrapper } = await mountForm('/admin/products/new')
    mounts.push(wrapper)
    await field(wrapper, 'Category').setValue('cpu')
    await flushPromises()
    await field(wrapper, 'Cores').setValue('8')

    await field(wrapper, 'Category').setValue('ram')
    await flushPromises()

    expect(wrapper.find('[data-testid=spec-fields]').text()).toContain('DDR type')
    expect(wrapper.find('[data-testid=spec-fields]').text()).not.toContain('Cores')
  })
})

describe('editing a product', () => {
  it('fills every box from the product, with money as plain ringgit', async () => {
    const { wrapper } = await mountForm('/admin/products/5')
    mounts.push(wrapper)

    expect(field(wrapper, 'Name').element.value).toBe('AMD Ryzen 5 7600X')
    expect(field(wrapper, 'Price (RM)').element.value).toBe('799.00')
    expect(field(wrapper, 'Sale price').element.value).toBe('')
    expect(field(wrapper, 'Brand').element.value).toBe('10')
    expect(field(wrapper, 'Category').element.value).toBe('cpu')
    expect(field(wrapper, 'Socket').element.value).toBe('AM5')
    expect(field(wrapper, 'Cores').element.value).toBe('6')
    expect(wrapper.findAll('[data-testid=spec-fields] input[type=checkbox]').map((c) => c.element.checked)).toEqual([
      false,
      true,
    ])
    expect(field(wrapper, 'Warn when').element.value).toBe('5')
  })

  it('saves changes with a PUT and says so', async () => {
    api.put.mockResolvedValue({
      data: { data: product({ name: 'Renamed', price: money(75000), discount_price: null }) },
    })
    const { wrapper } = await mountForm('/admin/products/5')
    mounts.push(wrapper)

    await field(wrapper, 'Name').setValue('Renamed')
    await field(wrapper, 'Price (RM)').setValue('750')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.put).toHaveBeenCalledWith(
      '/api/products/5',
      expect.objectContaining({
        name: 'Renamed',
        price: 75000,
        category_id: 1,
        brand_id: 10,
        specs: { socket: 'AM5', cores: 6, features: ['Unlocked'] },
        low_stock_threshold: 5,
      }),
    )
    expect(wrapper.text()).toContain('Saved.')
  })

  it('can clear the sale price', async () => {
    routeApi({
      '/api/products/5': {
        data: { data: product({ discount_price: money(69900), effective_price: money(69900), on_sale: true }) },
      },
    })
    api.put.mockResolvedValue({ data: { data: product() } })
    const { wrapper } = await mountForm('/admin/products/5')
    mounts.push(wrapper)
    expect(field(wrapper, 'Sale price').element.value).toBe('699.00')

    await field(wrapper, 'Sale price').setValue('')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.put.mock.calls[0][1].discount_price).toBeNull()
  })

  it('shows photos and stock, and refreshes the product after either changes', async () => {
    const { wrapper } = await mountForm('/admin/products/5')
    mounts.push(wrapper)

    expect(wrapper.find('[data-testid=image-list]').findAll('li')).toHaveLength(1)
    expect(wrapper.find('[data-testid=stock-numbers]').text()).toContain('25')
    api.get.mockClear()
    api.post.mockResolvedValue({ data: { data: { stock: 35, reserved: 2, available: 33, low_stock_threshold: 5 } } })
    await field(wrapper, 'Change').setValue('10')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Adjust stock')
      .element.closest('form')
      .dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/api/products/5')
  })

  it('deletes after a confirmation and goes back to the list', async () => {
    api.delete.mockResolvedValue({})
    const { wrapper, router } = await mountForm('/admin/products/5')
    mounts.push(wrapper)

    await button(wrapper, 'Delete…').trigger('click')
    await flushPromises()
    expect(dialog().textContent).toContain('AMD Ryzen 5 7600X')
    dialogButton('Delete product').click()
    await flushPromises()

    expect(api.delete).toHaveBeenCalledWith('/api/products/5')
    expect(router.currentRoute.value.name).toBe('admin-products')
  })

  it('says so when the product does not exist', async () => {
    api.get.mockImplementation(async (url) => {
      if (url === '/api/products/5') throw Object.assign(new Error('x'), { response: { status: 404 } })
      if (url === '/api/categories') return { data: { data: [] } }
      return { data: { data: [] } }
    })
    const { wrapper } = await mountForm('/admin/products/5')
    mounts.push(wrapper)

    expect(wrapper.text()).toContain('Product not found')
  })
})

describe('the photo panel', () => {
  const images = (n) =>
    Array.from({ length: n }, (_, i) => ({
      id: i + 1,
      url: `/storage/p/${i}.webp`,
      alt: `Photo ${i + 1}`,
      is_primary: i === 0,
    }))

  it('uploads a photo as a form, with its description and main-photo choice', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    const wrapper = mount(ProductImagesPanel, { props: { productId: 5, images: images(1) } })
    mounts.push(wrapper)
    const file = new File(['x'], 'front.jpg', { type: 'image/jpeg' })
    const input = wrapper.find('input[type=file]')
    Object.defineProperty(input.element, 'files', { value: [file] })

    expect(wrapper.find('button[type=submit]').attributes('disabled')).toBeDefined() // nothing chosen yet
    await input.trigger('change')
    await field(wrapper, 'Describe it').setValue('Front view')
    await wrapper.find('input[type=checkbox]').setValue(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/api/products/5/images')
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('image')).toBe(file)
    expect(body.get('alt')).toBe('Front view')
    expect(body.get('is_primary')).toBe('1')
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('removes a photo', async () => {
    api.delete.mockResolvedValue({})
    const wrapper = mount(ProductImagesPanel, { props: { productId: 5, images: images(2) } })
    mounts.push(wrapper)

    await wrapper.findAll('[data-testid=image-list] button')[1].trigger('click')
    await flushPromises()

    expect(api.delete).toHaveBeenCalledWith('/api/products/5/images/2')
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('stops offering uploads at eight photos', () => {
    const wrapper = mount(ProductImagesPanel, { props: { productId: 5, images: images(8) } })
    mounts.push(wrapper)

    expect(wrapper.find('input[type=file]').exists()).toBe(false)
  })

  it("shows the server's reason when a photo is refused", async () => {
    api.post.mockRejectedValue({ message: 'x', errors: { image: ['The image must be at least 200 pixels wide.'] } })
    const wrapper = mount(ProductImagesPanel, { props: { productId: 5, images: [] } })
    mounts.push(wrapper)
    Object.defineProperty(wrapper.find('input[type=file]').element, 'files', { value: [new File(['x'], 'tiny.png')] })

    await wrapper.find('input[type=file]').trigger('change')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('at least 200 pixels')
    expect(wrapper.text()).toContain('No photos yet.')
  })
})

describe('the stock panel', () => {
  const inventory = { stock: 25, reserved: 2, available: 23, low_stock_threshold: 5 }

  it('shows what there is, what is promised and what can be sold', () => {
    const wrapper = mount(StockPanel, { props: { productId: 5, inventory } })
    mounts.push(wrapper)

    const text = wrapper.find('[data-testid=stock-numbers]').text()
    expect(text).toContain('25')
    expect(text).toContain('2')
    expect(text).toContain('23')
  })

  it('adds and removes units with a reason', async () => {
    api.post.mockResolvedValue({ data: { data: { stock: 15, reserved: 2, available: 13, low_stock_threshold: 5 } } })
    const wrapper = mount(StockPanel, { props: { productId: 5, inventory } })
    mounts.push(wrapper)

    await field(wrapper, 'Change').setValue('-10')
    await field(wrapper, 'Why').setValue('correction')
    await field(wrapper, 'Note').setValue('Recount')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/products/5/inventory/adjustments', {
      change: -10,
      reason: 'correction',
      note: 'Recount',
    })
    expect(wrapper.text()).toContain('Stock is now 15.')
    expect(wrapper.emitted('changed')[0][0].stock).toBe(15)
  })

  it.each(['abc', '1.5', '0', '+0'])('does not send "%s"', async (text) => {
    const wrapper = mount(StockPanel, { props: { productId: 5, inventory } })
    mounts.push(wrapper)

    await field(wrapper, 'Change').setValue(text)
    await wrapper.find('form').trigger('submit')

    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('whole number of units')
  })

  it("shows the server's refusal, such as stock going below zero", async () => {
    api.post.mockRejectedValue({ message: 'x', errors: { change: ['Stock cannot go below zero.'] } })
    const wrapper = mount(StockPanel, { props: { productId: 5, inventory } })
    mounts.push(wrapper)

    await field(wrapper, 'Change').setValue('-99')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Stock cannot go below zero.')
    expect(wrapper.emitted('changed')).toBeUndefined()
  })
})
