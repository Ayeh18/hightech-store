import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
  parseApiError: (e) => ({ message: e.message ?? 'error', errors: e.errors ?? {} }),
}))

import { api } from '@/lib/api'
import { usePcBuilderStore } from '@/stores/pcBuilder'

const money = (sen) => ({ amount: sen, currency: 'MYR', formatted: `RM ${(sen / 100).toFixed(2)}` })
const product = (id, sen) => ({
  id,
  name: `Product ${id}`,
  brand: { name: 'Brand' },
  price: money(sen),
  effective_price: money(sen),
  highlights: [],
})
const SLOTS = [
  { slot: 'cpu', label: 'CPU', required: true, max_quantity: 1, category: { slug: 'cpu' } },
  { slot: 'ram', label: 'RAM', required: true, max_quantity: 4, category: { slug: 'ram' } },
  { slot: 'gpu', label: 'GPU', required: false, max_quantity: 1, category: { slug: 'gpu' } },
]
const serverAnswer = (subtotal, extra = {}) => ({
  data: { data: { items: [], subtotal: money(subtotal), issues: [], purchasable: true, ...extra } },
})

async function setup() {
  const store = usePcBuilderStore()
  api.get.mockResolvedValue({ data: { data: SLOTS } })
  await store.loadSlots()
  return store
}

describe('pcBuilder store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.useFakeTimers()
    localStorage.clear()
  })
  afterEach(() => vi.useRealTimers())

  it('updates the total immediately from local prices, using integer sen', async () => {
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(0))

    store.select('cpu', product(1, 69900))
    store.select('ram', product(2, 12345))
    store.setQuantity('ram', 3)

    expect(store.localTotalSen).toBe(69900 + 12345 * 3)
    expect(store.totalFormatted).toBe('RM 1,069.35')
    expect(store.verified).toBe(false) // the server has not answered yet
  })

  it('shows the server total once it confirms the current selection', async () => {
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(75000))

    store.select('cpu', product(1, 69900))
    await vi.advanceTimersByTimeAsync(400)

    expect(api.post).toHaveBeenCalledWith(
      '/api/pc-builder/validate',
      { items: [{ slot: 'cpu', product_id: 1, quantity: 1 }] },
      expect.any(Object),
    )
    expect(store.verified).toBe(true)
    expect(store.totalSen).toBe(75000) // the server's number wins
    expect(store.pricesChanged).toBe(true) // and we know it differs from what the browser showed
  })

  it('debounces: several quick changes cause a single server call', async () => {
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(0))

    store.select('cpu', product(1, 100))
    store.select('ram', product(2, 100))
    store.setQuantity('ram', 2)
    await vi.advanceTimersByTimeAsync(400)

    expect(api.post).toHaveBeenCalledTimes(1)
    expect(api.post.mock.calls[0][1].items).toHaveLength(2)
  })

  it('goes back to the local total as soon as the selection changes again', async () => {
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(70000))
    store.select('cpu', product(1, 69900))
    await vi.advanceTimersByTimeAsync(400)
    expect(store.verified).toBe(true)

    store.select('ram', product(2, 10000))

    expect(store.verified).toBe(false)
    expect(store.totalSen).toBe(79900)
  })

  it('replaces browser prices with the ones the server returns', async () => {
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(65000, { items: [{ slot: 'cpu', product: product(1, 65000) }] }))

    store.select('cpu', product(1, 69900))
    await vi.advanceTimersByTimeAsync(400)

    expect(store.selection.cpu.product.effective_price.amount).toBe(65000)
    expect(store.localTotalSen).toBe(65000)
    expect(store.pricesChanged).toBe(false)
  })

  it('ignores a slow answer that was superseded by a newer change', async () => {
    const store = await setup()
    let resolveFirst
    api.post
      .mockImplementationOnce(() => new Promise((resolve) => (resolveFirst = resolve)))
      .mockResolvedValueOnce(serverAnswer(200))

    store.select('cpu', product(1, 100))
    await vi.advanceTimersByTimeAsync(400) // first request is now in flight
    store.select('ram', product(2, 100))
    await vi.advanceTimersByTimeAsync(400) // second request completes
    resolveFirst(serverAnswer(999999)) // the stale answer arrives late
    await vi.advanceTimersByTimeAsync(0)

    expect(store.totalSen).toBe(200)
  })

  it('clamps quantities to the slot limit and ignores invalid values', async () => {
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(0))
    store.select('cpu', product(1, 100))
    store.select('ram', product(2, 100))

    store.setQuantity('ram', 9)
    expect(store.selection.ram.quantity).toBe(4)
    store.setQuantity('ram', 0)
    expect(store.selection.ram.quantity).toBe(1)
    store.setQuantity('ram', Number.NaN)
    expect(store.selection.ram.quantity).toBe(1)
    store.setQuantity('cpu', 3)
    expect(store.selection.cpu.quantity).toBe(1) // a CPU is always one
  })

  it('tracks required progress and what is still missing, ignoring optional slots', async () => {
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(0))

    store.select('gpu', product(3, 100))
    expect(store.requiredFilled).toBe(0)
    expect(store.missingRequired).toEqual(['CPU', 'RAM'])

    store.select('cpu', product(1, 100))
    expect(store.requiredFilled).toBe(1)
    expect(store.missingRequired).toEqual(['RAM'])
  })

  it('removes a part, and clears everything including the saved build link', async () => {
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(0))
    store.select('cpu', product(1, 100))
    store.select('ram', product(2, 100))
    store.buildId = 'abc'

    store.remove('cpu')
    expect(Object.keys(store.selection)).toEqual(['ram'])

    store.clear()
    expect(store.isEmpty).toBe(true)
    expect(store.buildId).toBeNull()
    expect(store.totalSen).toBe(0)
  })

  it('survives a refresh through the browser-stored draft', async () => {
    const first = await setup()
    api.post.mockResolvedValue(serverAnswer(0))
    first.select('cpu', product(1, 69900))

    setActivePinia(createPinia())
    const second = await setup()
    second.restoreDraft()

    expect(second.selection.cpu.product.id).toBe(1)
    expect(second.localTotalSen).toBe(69900)
  })

  it('keeps working when browser storage is unavailable or corrupt', async () => {
    localStorage.setItem('hightech.builder.draft.v1', '{not json')
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(0))
    expect(() => store.restoreDraft()).not.toThrow()

    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota')
    })
    expect(() => store.select('cpu', product(1, 100))).not.toThrow()
    spy.mockRestore()
  })

  it('exposes a readable error when the server cannot verify, and keeps the local total', async () => {
    const store = await setup()
    api.post.mockRejectedValue({ message: 'Unable to reach the server.' })

    store.select('cpu', product(1, 100))
    await vi.advanceTimersByTimeAsync(400)

    expect(store.validateError).toBe('Unable to reach the server.')
    expect(store.verified).toBe(false)
    expect(store.totalSen).toBe(100)
  })

  it('exposes the server compatibility verdict and merges it with stock problems per slot', async () => {
    const store = await setup()
    api.post.mockResolvedValue(
      serverAnswer(100, {
        issues: [{ code: 'out_of_stock', severity: 'error', message: 'Product 1 is out of stock.', slot: 'cpu' }],
        compatibility: {
          status: 'incompatible',
          issues: [
            {
              rule: 'cpu_socket_matches_motherboard',
              severity: 'error',
              message: 'Socket mismatch.',
              slots: ['cpu', 'ram'],
            },
            { rule: 'psu_has_headroom', severity: 'warning', message: 'Low headroom.', slots: ['gpu'] },
          ],
          metrics: { estimated_power_watts: 420, recommended_psu_watts: 550 },
        },
        purchasable: false,
      }),
    )
    store.select('cpu', product(1, 100))
    await vi.advanceTimersByTimeAsync(400)

    expect(store.compatStatus).toBe('incompatible')
    expect(store.compatMetrics).toEqual({ estimated_power_watts: 420, recommended_psu_watts: 550 })
    // The CPU row shows both its stock problem and the compatibility problem; the GPU row only its own warning.
    expect(store.issuesFor('cpu').map((i) => i.message)).toEqual(['Product 1 is out of stock.', 'Socket mismatch.'])
    expect(store.issuesFor('ram').map((i) => i.message)).toEqual(['Socket mismatch.'])
    expect(store.issuesFor('gpu').map((i) => i.severity)).toEqual(['warning'])
    expect(store.blockingCount).toBe(2) // one stock error + one compatibility error; warnings do not block
    expect(store.purchasable).toBe(false)
  })

  it('shows a problem between two parts on both rows, but an aggregate problem only on its main row', async () => {
    const store = await setup()
    api.post.mockResolvedValue(
      serverAnswer(100, {
        compatibility: {
          status: 'incompatible',
          issues: [
            { rule: 'gpu_fits_case', severity: 'error', message: 'GPU too long.', slots: ['gpu', 'case'] },
            {
              rule: 'psu_covers_estimated_load',
              severity: 'error',
              message: 'PSU too small.',
              slots: ['psu', 'cpu', 'gpu'],
            },
          ],
          metrics: {},
        },
      }),
    )
    store.select('cpu', product(1, 100))
    await vi.advanceTimersByTimeAsync(400)

    expect(store.issuesFor('gpu').map((i) => i.message)).toEqual(['GPU too long.'])
    expect(store.issuesFor('case').map((i) => i.message)).toEqual(['GPU too long.'])
    expect(store.issuesFor('psu').map((i) => i.message)).toEqual(['PSU too small.'])
    expect(store.issuesFor('cpu')).toEqual([]) // not repeated under CPU
    expect(store.compatIssues).toHaveLength(2) // but the summary card still lists everything
  })

  it('keeps showing the last verdict, marked stale, while a newer check is running', async () => {
    const store = await setup()
    api.post.mockResolvedValue(
      serverAnswer(100, {
        compatibility: {
          status: 'warning',
          issues: [{ rule: 'r', severity: 'warning', message: 'm', slots: ['cpu'] }],
          metrics: {},
        },
      }),
    )
    store.select('cpu', product(1, 100))
    await vi.advanceTimersByTimeAsync(400)
    expect(store.stale).toBe(false)

    store.select('ram', product(2, 100)) // triggers a new check
    expect(store.stale).toBe(true)
    expect(store.compatStatus).toBe('warning') // not blank in the meantime
    expect(store.purchasable).toBe(false) // but never treated as buyable until the server confirms
  })

  // Why this matters: tests make a new store for every case but share one browser storage. A store left behind with
  // a timer still running used to wake up during the NEXT test and write its old draft there, which made one page test
  // fail about one run in six.
  it('never touches the draft again once it has been thrown away: no leftover timer', async () => {
    api.post.mockResolvedValue(serverAnswer(50000))
    const old = await setup()
    old.select('cpu', product(1, 50000)) // writes a draft and starts the 350 ms timer
    old.$dispose()

    localStorage.clear() // what the next user of this storage starts with
    await vi.advanceTimersByTimeAsync(1000)

    expect(api.post).not.toHaveBeenCalled()
    expect(localStorage.getItem('hightech.builder.draft.v1')).toBeNull()
  })

  it('never touches the draft again once it has been thrown away: no late answer', async () => {
    let answer
    api.post.mockReturnValue(new Promise((resolve) => (answer = resolve)))
    const old = await setup()
    old.select('cpu', product(1, 50000))
    await vi.advanceTimersByTimeAsync(400) // the check is now on its way to the server
    expect(api.post).toHaveBeenCalledTimes(1)
    old.$dispose()

    localStorage.clear()
    answer(serverAnswer(50000)) // the server replies after the store is gone
    await vi.advanceTimersByTimeAsync(0)

    expect(localStorage.getItem('hightech.builder.draft.v1')).toBeNull()
  })

  it('clears the verdict when the build becomes empty', async () => {
    const store = await setup()
    api.post.mockResolvedValue(serverAnswer(100, { compatibility: { status: 'compatible', issues: [], metrics: {} } }))
    store.select('cpu', product(1, 100))
    await vi.advanceTimersByTimeAsync(400)

    store.remove('cpu')

    expect(store.compatStatus).toBeNull()
    expect(store.issuesFor('cpu')).toEqual([])
  })

  it('creates a new build, then updates the same one on the next save', async () => {
    const store = await setup()
    // No timers advance here, so the first POST is the save itself (not the debounced price check).
    api.post.mockResolvedValueOnce({ data: { data: { id: 'B1', name: 'Rig' } } })
    api.put.mockResolvedValue({ data: { data: { id: 'B1', name: 'Rig v2' } } })
    store.select('cpu', product(1, 100))

    await store.save('Rig')
    expect(api.post).toHaveBeenLastCalledWith('/api/builds', {
      name: 'Rig',
      items: [{ slot: 'cpu', product_id: 1, quantity: 1 }],
    })
    expect(store.buildId).toBe('B1')

    await store.save('Rig v2')
    expect(api.put).toHaveBeenCalledWith('/api/builds/B1', expect.objectContaining({ name: 'Rig v2' }))
    expect(store.buildName).toBe('Rig v2')

    store.detach()
    expect(store.buildId).toBeNull() // "save as new"
  })

  it('opens a saved build for editing and skips parts the server would not reveal', async () => {
    const store = await setup()
    api.get.mockResolvedValue({
      data: {
        data: {
          id: 'B9',
          name: 'Old rig',
          subtotal: money(300),
          issues: [],
          items: [
            { slot: 'cpu', quantity: 1, product: product(1, 300) },
            { slot: 'ram', quantity: 2, product: null },
          ],
        },
      },
    })

    await store.loadBuild('B9')

    expect(store.buildId).toBe('B9')
    expect(store.buildName).toBe('Old rig')
    expect(Object.keys(store.selection)).toEqual(['cpu'])
    expect(store.verified).toBe(true)
  })
})
