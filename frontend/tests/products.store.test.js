import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn() },
  parseApiError: (e) => ({ message: e.message, errors: {} }),
}))

import { api } from '@/lib/api'
import { emptyFilters } from '@/lib/catalogQuery'
import { useProductStore } from '@/stores/products'

describe('product store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads products and pagination meta using sen-based API params', async () => {
    api.get.mockResolvedValue({
      data: { data: [{ id: 1 }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 12 } },
    })
    const store = useProductStore()

    await store.fetchProducts({ ...emptyFilters(), category: 'gpu', priceMax: 2000 })

    expect(api.get).toHaveBeenCalledWith(
      '/api/products',
      expect.objectContaining({ params: expect.objectContaining({ category: 'gpu', price_max: 200000 }) }),
    )
    expect(store.products).toEqual([{ id: 1 }])
    expect(store.loading).toBe(false)
    expect(store.error).toBe('')
  })

  it('exposes a readable error and clears the list when loading fails', async () => {
    api.get.mockRejectedValue(new Error('Unable to reach the server.'))
    const store = useProductStore()
    store.products = [{ id: 9 }]

    await store.fetchProducts(emptyFilters())

    expect(store.products).toEqual([])
    expect(store.error).toBe('Unable to reach the server.')
    expect(store.loading).toBe(false)
  })

  it('ignores cancelled requests so a stale response cannot replace a fresh one', async () => {
    api.get.mockRejectedValueOnce(Object.assign(new Error('canceled'), { code: 'ERR_CANCELED' }))
    const store = useProductStore()
    store.products = [{ id: 1 }]

    await store.fetchProducts(emptyFilters())

    expect(store.products).toEqual([{ id: 1 }])
    expect(store.error).toBe('')
  })

  it('fetches category filter definitions only once per category', async () => {
    api.get.mockResolvedValue({ data: { data: { specs: [] } } })
    const store = useProductStore()

    await store.fetchFilterDefs('cpu')
    await store.fetchFilterDefs('cpu')

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(store.filterDefs.cpu).toEqual({ specs: [] })
  })
})
