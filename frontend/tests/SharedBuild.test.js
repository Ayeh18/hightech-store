import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))

import ShareBuildDialog from '@/components/pc-builder/ShareBuildDialog.vue'
import { api } from '@/lib/api'
import SharedBuild from '@/pages/SharedBuild.vue'
import { useBuildsStore } from '@/stores/builds'
import { usePcBuilderStore } from '@/stores/pcBuilder'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const product = (id, name, sen) => ({
  id,
  slug: `p-${id}`,
  name,
  brand: { name: 'Brand' },
  price: money(sen),
  effective_price: money(sen),
  highlights: [],
  image: null,
})
const line = (slot, label, p, extra = {}) => ({
  slot,
  slot_label: label,
  quantity: 1,
  product: p,
  unit_price: p?.price ?? null,
  line_total: p?.price ?? null,
  purchasable: !!p,
  ...extra,
})

const TOKEN = 'a'.repeat(40)
const LINK = { token: TOKEN, path: `/shared/${TOKEN}`, shared_at: '2026-10-11T08:00:00+00:00' }
const mounted = []
const text = () => document.body.textContent.replace(/\s+/g, ' ')
const button = (label) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === label)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  localStorage.clear()
})
afterEach(() => {
  mounted.splice(0).forEach((w) => w.unmount())
  usePcBuilderStore().$dispose()
  document.body.innerHTML = ''
  vi.unstubAllGlobals()
})

describe('sharing one of my builds', () => {
  function open(share = null) {
    const store = useBuildsStore()
    store.builds = [{ id: 'B1', name: 'Budget Beast', share }]
    const wrapper = mount(ShareBuildDialog, { props: { open: true, build: store.builds[0] }, attachTo: document.body })
    mounted.push(wrapper)
    return { wrapper, store }
  }

  it('explains that a build is private until a link is created, and creates one on request', async () => {
    api.post.mockResolvedValue({ data: { data: LINK } })
    const { store } = open()

    expect(text()).toContain('This build is private.')
    expect(document.body.querySelector('#share-link')).toBeNull()

    button('Create a link').click()
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/builds/B1/share')
    expect(store.builds[0].share).toEqual(LINK)
    // The API gives a path; the website's own address goes in front of it.
    expect(document.body.querySelector('#share-link').value).toBe(`${window.location.origin}/shared/${TOKEN}`)
    expect(text()).toContain('does not say who made it')
  })

  it('copies the link to the clipboard and says so', async () => {
    const writeText = vi.fn().mockResolvedValue()
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    open(LINK)

    button('Copy').click()
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/shared/${TOKEN}`)
    expect(button('Copied ✓')).toBeTruthy()
    expect(text()).toContain('The link is on your clipboard.')
  })

  it('selects the link for copying by hand when the clipboard cannot be used', async () => {
    vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } })
    open(LINK)
    const field = document.body.querySelector('#share-link')
    const select = vi.spyOn(field, 'select')

    button('Copy').click()
    await flushPromises()

    expect(select).toHaveBeenCalled()
    expect(button('Copied ✓')).toBeUndefined()
  })

  it('stops sharing: the link is gone and the build is private again', async () => {
    api.delete.mockResolvedValue({})
    const { store } = open(LINK)

    button('Stop sharing').click()
    await flushPromises()

    expect(api.delete).toHaveBeenCalledWith('/api/builds/B1/share')
    expect(store.builds[0].share).toBeNull()
    expect(document.body.querySelector('#share-link')).toBeNull()
    expect(text()).toContain('This build is private.')
  })

  it('says so when the link could not be created, and stays private', async () => {
    api.post.mockRejectedValue({ message: 'Too many attempts. Please wait a moment and try again.' })
    const { store } = open()

    button('Create a link').click()
    await flushPromises()

    expect(text()).toContain('Too many attempts.')
    expect(store.builds[0].share).toBeNull()
  })
})

describe('opening a shared build', () => {
  const cpu = product(1, 'Ryzen Six', 80000)
  const ram = product(2, 'DDR5 32GB', 45000)
  const SHARED = {
    name: 'Budget Beast',
    shared_at: LINK.shared_at,
    items: [line('cpu', 'CPU', cpu), line('ram', 'RAM', ram, { quantity: 2, line_total: money(90000) })],
    subtotal: money(170000),
    complete: false,
    purchasable: false,
    issues: [],
    compatibility: {
      status: 'compatible',
      issues: [],
      metrics: { estimated_power_watts: 200, recommended_psu_watts: 300 },
    },
  }

  async function visit() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/shared/:token', name: 'shared-build', component: SharedBuild },
        { path: '/builder', name: 'builder', component: { template: '<div>builder</div>' } },
        { path: '/products/:slug', name: 'product', component: { template: '<div />' } },
      ],
    })
    router.push(`/shared/${TOKEN}`)
    await router.isReady()
    const wrapper = mount(SharedBuild, { global: { plugins: [router] }, attachTo: document.body })
    mounted.push(wrapper)
    await flushPromises()
    return { wrapper, router }
  }

  it('shows the build as the shop prices it now, without any way to change it', async () => {
    api.get.mockResolvedValue({ data: { data: SHARED } })
    const { wrapper } = await visit()

    expect(api.get).toHaveBeenCalledWith(`/api/shared-builds/${TOKEN}`)
    expect(wrapper.find('h1').text()).toBe('Budget Beast')
    expect(wrapper.find('[data-slot="cpu"]').text()).toContain('Ryzen Six')
    expect(wrapper.find('[data-slot="cpu"]').text()).toContain('RM 800.00')
    expect(wrapper.find('[data-slot="ram"]').text()).toContain('2 ×')
    expect(wrapper.find('[data-slot="ram"]').text()).toContain('RM 900.00')
    expect(wrapper.find('[data-testid="shared-total"]').text()).toBe('RM 1700.00')
    expect(wrapper.text()).toContain('Prices and stock are shown as they are right now.')
    expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Open in the builder']) // nothing to edit, rename or delete
  })

  it("copies it into the visitor's own builder as a new build", async () => {
    api.get.mockResolvedValue({ data: { data: SHARED } })
    const { wrapper, router } = await visit()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    const builder = usePcBuilderStore()
    expect(Object.keys(builder.selection)).toEqual(['cpu', 'ram'])
    expect(builder.selection.ram.quantity).toBe(2)
    expect(builder.buildId).toBeNull() // their own, unsaved copy
    expect(router.currentRoute.value.name).toBe('builder')
  })

  it('says plainly when a part is no longer sold, without inventing a name for it', async () => {
    const withGap = { ...SHARED, items: [line('cpu', 'CPU', cpu), line('ram', 'RAM', null)] }
    api.get.mockResolvedValue({ data: { data: withGap } })
    const { wrapper } = await visit()

    expect(wrapper.find('[data-slot="ram"]').text()).toContain('No longer available')
    expect(wrapper.text()).toContain('One part of this build is no longer sold here')
  })

  it('explains a link that has been switched off or deleted, and offers the builder instead', async () => {
    api.get.mockRejectedValue({ response: { status: 404 }, message: 'Resource not found.' })
    const { wrapper } = await visit()

    expect(wrapper.text()).toContain('This link is no longer available')
    expect(wrapper.text()).toContain('its owner stopped sharing it')
    expect(wrapper.find('[data-testid="shared-parts"]').exists()).toBe(false)
  })

  it('offers to try again when the shop cannot be reached', async () => {
    api.get
      .mockRejectedValueOnce({ message: 'Unable to reach the server.' })
      .mockResolvedValue({ data: { data: SHARED } })
    const { wrapper } = await visit()
    expect(wrapper.text()).toContain('Unable to reach the server.')

    await [...wrapper.findAll('button')].find((b) => b.text() === 'Try again').trigger('click')
    await flushPromises()

    expect(wrapper.find('h1').text()).toBe('Budget Beast')
  })
})
