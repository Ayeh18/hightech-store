import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))

import RecommendDialog from '@/components/pc-builder/RecommendDialog.vue'
import { api } from '@/lib/api'
import { usePcBuilderStore } from '@/stores/pcBuilder'

const money = (sen) => ({
  amount: sen,
  currency: 'MYR',
  formatted: `RM ${(sen / 100).toLocaleString('en-MY', { minimumFractionDigits: 2 })}`,
})
const product = (id, name, sen) => ({
  id,
  name,
  brand: { name: 'Brand' },
  price: money(sen),
  effective_price: money(sen),
  highlights: [],
  image: null,
})

const OPTIONS = {
  purposes: [
    { value: 'gaming', label: 'Gaming' },
    { value: 'creation', label: 'Video editing and 3D' },
    { value: 'work', label: 'Office, study and browsing' },
  ],
  priorities: [
    { value: 'performance', label: 'Best performance' },
    { value: 'balanced', label: 'Balanced' },
    { value: 'value', label: 'Save money' },
  ],
  resolutions: [
    { value: '1080p', label: '1080p (Full HD)' },
    { value: '1440p', label: '1440p (QHD)' },
    { value: '4k', label: '4K' },
  ],
  budget: { min: money(50000), max: money(10000000) },
  max_games: 5,
  uses_ai: false,
}

const line = (slot, label, p) => ({
  slot,
  slot_label: label,
  quantity: 1,
  product: p,
  unit_price: p.price,
  line_total: p.price,
  purchasable: true,
})
const SUGGESTION = {
  id: '01JRECOMMENDATION0000000000',
  source: 'rules',
  brief: {
    budget: money(400000),
    purpose: { value: 'gaming', label: 'Gaming' },
    priority: { value: 'balanced', label: 'Balanced' },
    resolution: { value: '1080p', label: '1080p (Full HD)' },
    games: [],
  },
  build: {
    items: [line('cpu', 'CPU', product(1, 'Ryzen Six', 80000)), line('gpu', 'GPU', product(2, 'Card 8GB', 130000))],
    subtotal: money(210000),
    complete: true,
    purchasable: true,
    issues: [],
    compatibility: {
      status: 'compatible',
      issues: [],
      metrics: { estimated_power_watts: 300, recommended_psu_watts: 450 },
    },
  },
  reasons: [
    { slot: 'cpu', slot_label: 'CPU', reason: '6 cores and 12 threads.' },
    { slot: 'gpu', slot_label: 'GPU', reason: 'The largest share of the budget.' },
  ],
  notes: ['RM 1,900.00 of your budget is left over.'],
  remaining: money(190000),
}

const mounted = []
async function open(props = {}, options = OPTIONS) {
  api.get.mockResolvedValue({ data: { data: options } })
  const wrapper = mount(RecommendDialog, { props: { open: true, ...props }, attachTo: document.body })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

// The dialog is teleported to <body>, so look there.
const $ = (selector) => document.body.querySelector(selector)
const text = () => document.body.textContent.replace(/\s+/g, ' ')
const button = (label) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === label)
async function type(selector, value) {
  const input = $(selector)
  input.value = value
  input.dispatchEvent(new Event('input'))
  await flushPromises()
}
async function pick(name, value) {
  const radio = $(`input[name="${name}"][value="${value}"]`)
  radio.checked = true
  radio.dispatchEvent(new Event('change'))
  await flushPromises()
}
async function submit() {
  $('#recommend-form').dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  localStorage.clear()
})
afterEach(() => {
  mounted.splice(0).forEach((w) => w.unmount())
  document.body.innerHTML = ''
})

describe('the brief', () => {
  it('offers the choices the API lists, never its own', async () => {
    await open()

    expect(api.get).toHaveBeenCalledWith('/api/pc-builder/recommendations/options')
    expect([...document.body.querySelectorAll('input[name="purpose"]')].map((i) => i.value)).toEqual([
      'gaming',
      'creation',
      'work',
    ])
    expect(text()).toContain('Video editing and 3D')
    expect(text()).toContain('Save money')
    expect(text()).toContain('From RM 500.00 to RM 100,000.00')
  })

  it('sends the budget in whole sen with the chosen answers', async () => {
    api.post.mockResolvedValue({ data: { data: SUGGESTION } })
    await open()

    await type('input[placeholder="4000"]', '4,000.50')
    await pick('purpose', 'creation')
    await pick('priority', 'performance')
    await pick('resolution', '1440p')
    await submit()

    expect(api.post).toHaveBeenCalledWith('/api/pc-builder/recommendations', {
      budget: 400050,
      purpose: 'creation',
      priority: 'performance',
      resolution: '1440p',
    })
  })

  it('does not ask about a screen for an office PC, and does not send one', async () => {
    api.post.mockResolvedValue({ data: { data: SUGGESTION } })
    await open()

    await pick('purpose', 'work')
    expect($('input[name="resolution"]')).toBeNull()

    await type('input[placeholder="4000"]', '2500')
    await submit()
    expect(api.post.mock.calls[0][1]).toEqual({ budget: 250000, purpose: 'work', priority: 'balanced' })
  })

  it('asks for games only when an AI will read them, and sends them as a list', async () => {
    await open()
    expect(text()).not.toContain('Games you play')
    mounted.splice(0).forEach((w) => w.unmount())

    api.post.mockResolvedValue({ data: { data: SUGGESTION } })
    await open({}, { ...OPTIONS, uses_ai: true })
    expect(text()).toContain('Games you play (optional)')

    await type('input[placeholder="4000"]', '4000')
    await type('input[placeholder="Valorant, Cyberpunk 2077"]', ' Valorant ,, Cyberpunk 2077 ')
    await submit()
    expect(api.post.mock.calls[0][1].games).toEqual(['Valorant', 'Cyberpunk 2077'])
  })

  it('catches a budget that is not an amount before asking the server', async () => {
    await open()

    await type('input[placeholder="4000"]', 'about four thousand')
    await submit()

    expect(api.post).not.toHaveBeenCalled()
    expect(text()).toContain('Enter your budget in ringgit, for example 4000.')
  })

  it("shows the server's reason under the budget when it is too low", async () => {
    api.post.mockRejectedValue({
      message: 'x',
      errors: {
        budget: ['The cheapest complete PC we can build from what is in stock is RM 1,974.00. Try a higher budget.'],
      },
    })
    await open()

    await type('input[placeholder="4000"]', '1000')
    await submit()

    expect(text()).toContain('The cheapest complete PC we can build from what is in stock is RM 1,974.00.')
    expect($('[data-testid="recommendation"]')).toBeNull()
  })

  it('shows any other failure as a message and keeps the answers', async () => {
    api.post.mockRejectedValue({ message: 'Too many attempts. Please wait a moment and try again.', errors: {} })
    await open()

    await type('input[placeholder="4000"]', '4000')
    await submit()

    expect(text()).toContain('Too many attempts. Please wait a moment and try again.')
    expect($('input[placeholder="4000"]').value).toBe('4000')
  })

  it('says so when the choices cannot be loaded', async () => {
    api.get.mockRejectedValue({ message: 'Unable to reach the server.' })
    const wrapper = mount(RecommendDialog, { props: { open: true }, attachTo: document.body })
    mounted.push(wrapper)
    await flushPromises()

    expect(text()).toContain('Unable to reach the server.')
    expect(button('Suggest a build').disabled).toBe(true)
  })
})

describe('the suggestion', () => {
  async function suggested(props = {}, suggestion = SUGGESTION) {
    api.post.mockResolvedValue({ data: { data: suggestion } })
    const wrapper = await open(props)
    await type('input[placeholder="4000"]', '4000')
    await submit()
    return wrapper
  }

  it('shows every part with its price and the reason for it, the total and what is left', async () => {
    await suggested()

    expect($('[data-testid="recommendation-total"]').textContent.trim()).toBe('RM 2,100.00')
    expect(text()).toContain('of your RM 4,000.00 budget · RM 1,900.00 left')
    expect($('[data-slot="cpu"]').textContent).toContain('Ryzen Six')
    expect($('[data-slot="cpu"]').textContent).toContain('RM 800.00')
    expect($('[data-slot="cpu"]').textContent).toContain('6 cores and 12 threads.')
    expect($('[data-slot="gpu"]').textContent).toContain('The largest share of the budget.')
    expect($('[data-testid="recommendation-notes"]').textContent).toContain('RM 1,900.00 of your budget is left over.')
    expect(text()).toContain('Every part fits together, checked by the store.')
  })

  it('says honestly who chose the parts', async () => {
    await suggested()
    expect($('[data-testid="recommendation-source"]').textContent.trim()).toBe("Chosen by the store's rules")
    mounted.splice(0).forEach((w) => w.unmount())

    await suggested({}, { ...SUGGESTION, source: 'ai' })
    expect($('[data-testid="recommendation-source"]').textContent.trim()).toBe('Chosen by AI, checked by the store')
  })

  it("shows the store's warnings about a build instead of claiming everything fits", async () => {
    const withWarning = structuredClone(SUGGESTION)
    withWarning.build.compatibility = {
      status: 'warning',
      issues: [
        {
          rule: 'psu_has_headroom',
          severity: 'warning',
          message: 'The power supply has little room to spare.',
          slots: ['psu'],
        },
      ],
      metrics: {},
    }

    await suggested({}, withWarning)

    expect(text()).toContain('The power supply has little room to spare.')
    expect(text()).not.toContain('Every part fits together')
  })

  it('hands the build over, as the server priced it, and records that it was used', async () => {
    const wrapper = await suggested()

    button('Use this build').click()
    await flushPromises()

    expect(wrapper.emitted('use')[0][0]).toEqual(SUGGESTION.build)
    expect(api.post).toHaveBeenLastCalledWith('/api/pc-builder/recommendations/01JRECOMMENDATION0000000000/accept')
  })

  it('still hands the build over when recording its use fails', async () => {
    const wrapper = await suggested()
    api.post.mockRejectedValue(new Error('offline'))

    button('Use this build').click()
    await flushPromises()

    expect(wrapper.emitted('use')).toHaveLength(1)
  })

  it('warns before replacing parts the customer already chose', async () => {
    await suggested({ currentParts: 3 })
    expect($('[data-testid="replace-warning"]').textContent).toContain('replaces the 3 parts you have chosen')
    mounted.splice(0).forEach((w) => w.unmount())

    await suggested({ currentParts: 0 })
    expect($('[data-testid="replace-warning"]')).toBeNull()
  })

  it('goes back to the answers, still filled in, to try something else', async () => {
    await suggested()

    button('Change my answers').click()
    await flushPromises()

    expect($('[data-testid="recommendation"]')).toBeNull()
    expect($('input[placeholder="4000"]').value).toBe('4000')
  })
})

describe('the builder store', () => {
  it('takes a suggested build as a new, unsaved build that the server has already confirmed', () => {
    const builder = usePcBuilderStore()
    builder.buildId = 'B1'
    builder.buildName = 'Old build'

    builder.applyBuild(SUGGESTION.build)

    expect(Object.keys(builder.selection)).toEqual(['cpu', 'gpu'])
    expect(builder.selection.gpu).toEqual({ product: SUGGESTION.build.items[1].product, quantity: 1 })
    expect(builder.buildId).toBeNull()
    expect(builder.buildName).toBe('')
    expect(builder.verified).toBe(true) // no second request needed: the server's own answer is on screen
    expect(builder.totalSen).toBe(210000)
    expect(JSON.parse(localStorage.getItem('hightech.builder.draft.v1'))).toBeTruthy() // survives a page reload
  })
})
