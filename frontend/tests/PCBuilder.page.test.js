import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import { api } from '@/lib/api'
import PCBuilder from '@/pages/PCBuilder.vue'
import { useAuthStore } from '@/stores/auth'
import { usePcBuilderStore } from '@/stores/pcBuilder'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const cpu = {
  id: 1,
  name: 'Test CPU',
  brand: { name: 'Brand' },
  price: money(50000),
  effective_price: money(50000),
  highlights: [],
  image: null,
}
const SLOTS = [
  { slot: 'cpu', label: 'CPU', required: true, max_quantity: 1, category: { slug: 'cpu' } },
  { slot: 'ram', label: 'RAM', required: true, max_quantity: 4, category: { slug: 'ram' } },
]

const router = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/builder', name: 'builder', component: PCBuilder },
      { path: '/builder/:id', name: 'builder-edit', component: PCBuilder },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/my-builds', name: 'my-builds', component: { template: '<div />' } },
      { path: '/cart', name: 'cart', component: { template: '<div />' } },
    ],
  })

// Every mounted page is unmounted after its test, so a leftover page (and its teleported dialog) can never
// leak into the next test.
const mounted = []

async function mountAt(path) {
  const r = router()
  r.push(path)
  await r.isReady()
  const wrapper = mount(PCBuilder, { global: { plugins: [r] }, attachTo: document.body })
  mounted.push(wrapper)
  await flushPromises()
  return { wrapper, r }
}

// Shared by the describe blocks below: a fresh store, an empty draft, and an API that answers like Laravel would.
function prepare() {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  localStorage.clear()
  api.get.mockImplementation(async (url) => {
    if (url === '/api/pc-builder/components') return { data: { data: SLOTS } }
    if (url === '/api/builds/B1') {
      return {
        data: {
          data: {
            id: 'B1',
            name: 'Rig',
            subtotal: money(50000),
            issues: [],
            items: [{ slot: 'cpu', quantity: 1, product: cpu }],
          },
        },
      }
    }
    throw new Error(`unexpected GET ${url}`)
  })
  api.post.mockImplementation(async (url) => {
    if (url === '/api/builds') return { data: { data: { id: 'B1', name: 'Rig' } } }
    return { data: { data: { items: [], subtotal: money(50000), issues: [], purchasable: true } } }
  })
  useAuthStore().user = { id: 1, name: 'Tester', roles: ['customer'], permissions: [] }
}

const afterEachPage = () => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
  document.body.innerHTML = ''
}

describe('PC builder page: saving', () => {
  beforeEach(prepare)
  afterEach(afterEachPage)

  it('resets the builder after a new build is saved, and says it was saved', async () => {
    const { wrapper } = await mountAt('/builder')
    const builder = usePcBuilderStore()
    builder.select('cpu', cpu)
    await flushPromises()
    expect(wrapper.text()).toContain('Test CPU')

    await [...wrapper.findAll('button')].find((b) => b.text() === 'Save build').trigger('click')
    await flushPromises()
    const input = document.body.querySelector('[role=dialog] input')
    input.value = 'Rig'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector('[role=dialog] button[type=submit]').click()
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/builds', {
      name: 'Rig',
      items: [{ slot: 'cpu', product_id: 1, quantity: 1 }],
    })
    // Everything is cleared...
    expect(builder.selectedCount).toBe(0)
    expect(builder.buildId).toBeNull()
    expect(wrapper.text()).not.toContain('Test CPU')
    expect(wrapper.text()).toContain('Nothing selected yet')
    expect(JSON.parse(localStorage.getItem('hightech.builder.draft.v1')).selection).toEqual({})
    // ...and the customer is told where the build went.
    expect(wrapper.text()).toContain('Saved as “Rig”')
    expect(wrapper.text()).toContain('View my builds')
  })

  it('hides the "saved" message as soon as a new build is started', async () => {
    const { wrapper } = await mountAt('/builder')
    const builder = usePcBuilderStore()
    builder.select('cpu', cpu)
    await flushPromises()
    await [...wrapper.findAll('button')].find((b) => b.text() === 'Save build').trigger('click')
    await flushPromises()
    const input = document.body.querySelector('[role=dialog] input')
    input.value = 'Rig'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector('[role=dialog] button[type=submit]').click()
    await flushPromises()
    expect(wrapper.text()).toContain('Saved as “Rig”')

    builder.select('cpu', cpu)
    await flushPromises()

    expect(wrapper.text()).not.toContain('Saved as')
  })

  it('stays on the build after saving changes to it, with its parts, and says the changes were saved', async () => {
    api.put.mockResolvedValue({ data: { data: { id: 'B1', name: 'Rig' } } })
    const { wrapper, r } = await mountAt('/builder/B1')
    const builder = usePcBuilderStore()
    expect(builder.buildId).toBe('B1')
    expect(wrapper.text()).toContain('Test CPU')

    await [...wrapper.findAll('button')].find((b) => b.text() === 'Save changes').trigger('click')
    await flushPromises()

    expect(api.put).toHaveBeenCalledWith('/api/builds/B1', expect.objectContaining({ name: 'Rig' }))
    // Still editing the same build: parts kept, same page, same heading.
    expect(builder.selectedCount).toBe(1)
    expect(builder.buildId).toBe('B1')
    expect(r.currentRoute.value.name).toBe('builder-edit')
    expect(wrapper.text()).toContain('Test CPU')
    expect(wrapper.text()).toContain('Editing')
    expect(wrapper.text()).toContain('Changes to “Rig” saved.')
    expect(wrapper.text()).not.toContain('Saved as')
    expect(Object.keys(JSON.parse(localStorage.getItem('hightech.builder.draft.v1')).selection)).toEqual(['cpu'])
  })

  it('hides the "changes saved" message once the parts are changed again', async () => {
    api.put.mockResolvedValue({ data: { data: { id: 'B1', name: 'Rig' } } })
    const { wrapper } = await mountAt('/builder/B1')
    const builder = usePcBuilderStore()

    await [...wrapper.findAll('button')].find((b) => b.text() === 'Save changes').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Changes to “Rig” saved.')

    builder.setQuantity('cpu', 1) // no real change: the message stays
    await flushPromises()
    expect(wrapper.text()).toContain('Changes to “Rig” saved.')

    builder.select('ram', { ...cpu, id: 2, name: 'Test RAM' })
    await flushPromises()
    expect(wrapper.text()).not.toContain('Changes to')
    expect(wrapper.text()).toContain('Test RAM')
  })

  it('"Save as a new build" while editing creates a separate build and starts a fresh builder', async () => {
    const { wrapper, r } = await mountAt('/builder/B1')
    const builder = usePcBuilderStore()
    api.post.mockImplementation(async (url) => {
      if (url === '/api/builds') return { data: { data: { id: 'B2', name: 'Rig copy' } } }
      return { data: { data: { items: [], subtotal: money(50000), issues: [], purchasable: true } } }
    })

    await [...wrapper.findAll('button')].find((b) => b.text() === 'Save as a new build').trigger('click')
    await flushPromises()
    const input = document.body.querySelector('[role=dialog] input')
    expect(input.value).toBe('Rig (copy)') // suggests a different name
    input.value = 'Rig copy'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector('[role=dialog] button[type=submit]').click()
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/builds', expect.objectContaining({ name: 'Rig copy' }))
    expect(api.put).not.toHaveBeenCalled() // the original build was not overwritten
    expect(builder.selectedCount).toBe(0)
    expect(builder.buildId).toBeNull()
    expect(r.currentRoute.value.name).toBe('builder')
    expect(wrapper.text()).toContain('Saved as “Rig copy”.')
  })

  it('keeps the parts when saving fails, so nothing is lost', async () => {
    api.post.mockImplementation(async (url) => {
      if (url === '/api/builds') throw { message: 'Server error', errors: { name: ['You can save up to 25 builds.'] } }
      return { data: { data: { items: [], subtotal: money(50000), issues: [], purchasable: true } } }
    })
    const { wrapper } = await mountAt('/builder')
    const builder = usePcBuilderStore()
    builder.select('cpu', cpu)
    await flushPromises()

    await [...wrapper.findAll('button')].find((b) => b.text() === 'Save build').trigger('click')
    await flushPromises()
    const input = document.body.querySelector('[role=dialog] input')
    input.value = 'Rig'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    document.body.querySelector('[role=dialog] button[type=submit]').click()
    await flushPromises()

    expect(builder.selectedCount).toBe(1) // still there
    expect(wrapper.text()).toContain('Test CPU')
    expect(wrapper.text()).not.toContain('Saved as')
    expect(document.body.querySelector('[role=dialog]').textContent).toContain('You can save up to 25 builds.')
  })
})

describe('PC builder page: adding the build to the cart', () => {
  const CART = { items: [], item_count: 1, subtotal: money(50000), checkout_ready: true }
  const addButton = (wrapper) => [...wrapper.findAll('button')].find((b) => b.text() === 'Add build to cart')

  beforeEach(prepare)
  afterEach(afterEachPage)

  // Picks a part and waits until the server has confirmed the build, which is what enables "Add build to cart".
  async function buildReady(path = '/builder') {
    const mount = await mountAt(path)
    const builder = usePcBuilderStore()
    if (path === '/builder') builder.select('cpu', cpu)
    await builder.validate()
    await flushPromises()
    return { ...mount, builder }
  }

  it('resets the builder once the PC is in the cart, and says so', async () => {
    api.post.mockImplementation(async (url) => {
      if (url === '/api/cart/builds') return { data: { data: CART } }
      return { data: { data: { items: [], subtotal: money(50000), issues: [], purchasable: true } } }
    })
    const { wrapper, builder } = await buildReady()
    expect(wrapper.text()).toContain('Test CPU')

    await addButton(wrapper).trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith(
      '/api/cart/builds',
      expect.objectContaining({ items: [{ slot: 'cpu', product_id: 1, quantity: 1 }] }),
    )
    expect(builder.selectedCount).toBe(0)
    expect(wrapper.text()).not.toContain('Test CPU')
    expect(wrapper.text()).toContain('Nothing selected yet')
    expect(JSON.parse(localStorage.getItem('hightech.builder.draft.v1')).selection).toEqual({})
    // The customer is still told where the PC went, and the message goes away once they start a new build.
    expect(wrapper.text()).toContain('Added to your cart.')
    expect(wrapper.text()).toContain('View cart')

    builder.select('cpu', cpu)
    await flushPromises()
    expect(wrapper.text()).not.toContain('Added to your cart.')
  })

  it('leaves a saved build being edited, and the saved build itself stays', async () => {
    api.post.mockImplementation(async (url) => {
      if (url === '/api/cart/builds') return { data: { data: CART } }
      return { data: { data: { items: [], subtotal: money(50000), issues: [], purchasable: true } } }
    })
    const { wrapper, builder, r } = await buildReady('/builder/B1')
    expect(builder.buildId).toBe('B1')

    await addButton(wrapper).trigger('click')
    await flushPromises()

    expect(builder.selectedCount).toBe(0)
    expect(builder.buildId).toBeNull() // the next save would create a new build, not overwrite "Rig"
    expect(r.currentRoute.value.name).toBe('builder')
    expect(wrapper.text()).toContain('Added to your cart.')
    expect(api.put).not.toHaveBeenCalled()
  })

  it('keeps the parts when the server refuses the build, so nothing is lost', async () => {
    api.post.mockImplementation(async (url) => {
      if (url === '/api/cart/builds') throw { message: 'Refused', errors: { build: ['The RAM is out of stock.'] } }
      return { data: { data: { items: [], subtotal: money(50000), issues: [], purchasable: true } } }
    })
    const { wrapper, builder } = await buildReady()

    await addButton(wrapper).trigger('click')
    await flushPromises()

    expect(builder.selectedCount).toBe(1)
    expect(wrapper.text()).toContain('Test CPU')
    expect(wrapper.text()).toContain('The RAM is out of stock.')
    expect(wrapper.text()).not.toContain('Added to your cart.')
  })

  it('keeps a part the customer changed while the request was still on its way', async () => {
    let finish
    api.post.mockImplementation((url) => {
      if (url === '/api/cart/builds')
        return new Promise((resolve) => (finish = () => resolve({ data: { data: CART } })))
      return Promise.resolve({ data: { data: { items: [], subtotal: money(50000), issues: [], purchasable: true } } })
    })
    const { wrapper, builder } = await buildReady()

    await addButton(wrapper).trigger('click')
    builder.select('ram', { ...cpu, id: 2, name: 'Test RAM' }) // changed after the request left
    finish()
    await flushPromises()

    expect(builder.selectedCount).toBe(2) // what is on screen was not what got added, so it is not wiped
    expect(wrapper.text()).toContain('Test RAM')
    expect(wrapper.text()).toContain('Added to your cart.')
  })
})
