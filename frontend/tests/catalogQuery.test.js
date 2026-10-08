import { describe, expect, it } from 'vitest'
import {
  activeFilterChips,
  emptyFilters,
  filtersToApiParams,
  filtersToQuery,
  queryToFilters,
  removeChip,
  ringgitToSen,
} from '@/lib/catalogQuery'

describe('catalogQuery', () => {
  it('round-trips filters through the URL query', () => {
    const query = {
      category: 'cpu',
      q: 'ryzen',
      brands: 'amd,intel',
      price_min: '500',
      price_max: '1500',
      in_stock: '1',
      sort: 'price_asc',
      page: '3',
      'spec.socket': 'AM5,AM4',
      'spec.cores.min': '8',
    }
    const filters = queryToFilters(query)

    expect(filters).toMatchObject({
      category: 'cpu',
      q: 'ryzen',
      brands: ['amd', 'intel'],
      priceMin: 500,
      priceMax: 1500,
      inStock: true,
      sort: 'price_asc',
      page: 3,
      specs: { socket: ['AM5', 'AM4'], cores: { min: 8 } },
    })
    expect(filtersToQuery(filters)).toEqual(query)
  })

  it('uses safe defaults for empty, invalid or hostile input', () => {
    const filters = queryToFilters({
      sort: 'drop table',
      page: '-4',
      price_min: 'abc',
      in_stock: 'yes',
      brands: ',,',
      'spec.x.min': 'NaN',
    })

    expect(filters).toEqual(emptyFilters())
    expect(filtersToQuery(filters)).toEqual({})
  })

  it('converts ringgit to integer sen without float drift', () => {
    expect(ringgitToSen(4602)).toBe(460200)
    expect(ringgitToSen(19.99)).toBe(1999)
    expect(ringgitToSen(0.1 + 0.2)).toBe(30)
  })

  it('builds API params with prices in sen and specs as nested objects', () => {
    const filters = {
      ...emptyFilters(),
      category: 'cpu',
      brands: ['amd'],
      priceMin: 500,
      inStock: true,
      specs: { socket: ['AM5'] },
    }

    expect(filtersToApiParams(filters, 24)).toEqual({
      per_page: 24,
      page: 1,
      sort: 'popular',
      category: 'cpu',
      brands: ['amd'],
      price_min: 50000,
      in_stock: 1,
      specs: { socket: ['AM5'] },
    })
  })

  it('describes active filters as chips and removes them one by one', () => {
    const defs = {
      brands: [{ slug: 'amd', name: 'AMD' }],
      specs: [
        { key: 'socket', label: 'Socket' },
        { key: 'cores', label: 'Cores', unit: '' },
      ],
    }
    const filters = {
      ...emptyFilters(),
      brands: ['amd'],
      inStock: true,
      specs: { socket: ['AM5', 'AM4'], cores: { min: 8 } },
      page: 4,
    }

    const chips = activeFilterChips(filters, defs)
    expect(chips.map((c) => c.label)).toEqual(['AMD', 'In stock', 'Socket: AM5', 'Socket: AM4', 'Cores: 8 – …'])

    const withoutAm5 = removeChip(filters, 'spec:socket:AM5')
    expect(withoutAm5.specs.socket).toEqual(['AM4'])
    expect(withoutAm5.page).toBe(1) // changing a filter always returns to page 1
    expect(removeChip(withoutAm5, 'spec:socket:AM4').specs).not.toHaveProperty('socket')
    expect(removeChip(filters, 'brand:amd').brands).toEqual([])
    expect(filters.brands).toEqual(['amd']) // original is untouched
  })
})
