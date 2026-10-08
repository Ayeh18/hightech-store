/*
 * The shop's filters live in the URL, so a filtered view can be bookmarked, shared and survives refresh.
 * These two functions translate between the three shapes the same data takes:
 *
 *   URL query (strings)  <->  filter object (used by components)  ->  API params (what Laravel expects)
 *
 *   ?category=cpu&brands=amd,intel&price_min=500&spec.socket=AM5&spec.cores.min=8
 */

const text = (value) => (typeof value === 'string' && value.trim() !== '' ? value.trim() : '')
const list = (value) => (text(value) ? text(value).split(',').filter(Boolean) : [])
const number = (value) => {
  const n = Number(value)
  return text(value) !== '' && Number.isFinite(n) && n >= 0 ? n : null
}

export const SORT_OPTIONS = [
  { value: 'relevance', label: 'Most relevant' },
  { value: 'popular', label: 'Most popular' },
  { value: 'newest', label: 'Newest' },
  { value: 'price_asc', label: 'Price: low to high' },
  { value: 'price_desc', label: 'Price: high to low' },
]

/** "Most relevant" only makes sense while searching, so it is the default then and unavailable otherwise. */
export const defaultSort = (hasSearch) => (hasSearch ? 'relevance' : 'popular')
export const sortOptionsFor = (hasSearch) => SORT_OPTIONS.filter((o) => hasSearch || o.value !== 'relevance')

export function emptyFilters() {
  return {
    q: '',
    category: '',
    brands: [],
    priceMin: null,
    priceMax: null,
    inStock: false,
    sort: 'popular',
    page: 1,
    specs: {},
  }
}

/** URL query object -> filter object. Anything unrecognised is ignored. */
export function queryToFilters(query = {}) {
  const filters = emptyFilters()

  filters.q = text(query.q)
  filters.category = text(query.category)
  filters.brands = list(query.brands)
  filters.priceMin = number(query.price_min)
  filters.priceMax = number(query.price_max)
  filters.inStock = query.in_stock === '1'
  filters.sort = sortOptionsFor(Boolean(filters.q)).some((o) => o.value === query.sort)
    ? query.sort
    : defaultSort(Boolean(filters.q))
  filters.page = Math.max(1, Math.floor(number(query.page) ?? 1))

  for (const [key, value] of Object.entries(query)) {
    if (!key.startsWith('spec.')) continue
    const [specKey, bound] = key.slice(5).split('.')
    if (bound === 'min' || bound === 'max') {
      const n = number(value)
      if (n !== null) filters.specs[specKey] = { ...filters.specs[specKey], [bound]: n }
    } else if (list(value).length) {
      filters.specs[specKey] = list(value)
    }
  }

  return filters
}

/** Filter object -> URL query. Defaults are left out so URLs stay short. */
export function filtersToQuery(filters) {
  const query = {}

  if (filters.q) query.q = filters.q
  if (filters.category) query.category = filters.category
  if (filters.brands.length) query.brands = filters.brands.join(',')
  if (filters.priceMin !== null) query.price_min = String(filters.priceMin)
  if (filters.priceMax !== null) query.price_max = String(filters.priceMax)
  if (filters.inStock) query.in_stock = '1'
  // Only written when it differs from the default for this state, so plain searches keep short URLs.
  if (filters.sort && filters.sort !== defaultSort(Boolean(filters.q))) query.sort = filters.sort
  if (filters.page > 1) query.page = String(filters.page)

  for (const [key, value] of Object.entries(filters.specs)) {
    if (Array.isArray(value) && value.length) query[`spec.${key}`] = value.join(',')
    else if (value && typeof value === 'object') {
      if (value.min != null) query[`spec.${key}.min`] = String(value.min)
      if (value.max != null) query[`spec.${key}.max`] = String(value.max)
    }
  }

  return query
}

/** Ringgit typed by a person -> integer sen for the API (never a float). */
export const ringgitToSen = (ringgit) => Math.round(ringgit * 100)

/** Filter object -> query parameters for GET /api/products. Prices are converted to sen. */
export function filtersToApiParams(filters, perPage = 12) {
  const params = { per_page: perPage, page: filters.page, sort: filters.sort }

  if (filters.q) params.q = filters.q
  if (filters.category) params.category = filters.category
  if (filters.brands.length) params.brands = filters.brands
  if (filters.priceMin !== null) params.price_min = ringgitToSen(filters.priceMin)
  if (filters.priceMax !== null) params.price_max = ringgitToSen(filters.priceMax)
  if (filters.inStock) params.in_stock = 1
  if (Object.keys(filters.specs).length) params.specs = filters.specs

  return params
}

/** Active filters as removable chips: [{ id, label }]. Used for the "clear" row above results. */
export function activeFilterChips(filters, filterDefs = null) {
  const chips = []
  const brandNames = Object.fromEntries((filterDefs?.brands ?? []).map((b) => [b.slug, b.name]))

  if (filters.q) chips.push({ id: 'q', label: `"${filters.q}"` })
  filters.brands.forEach((slug) => chips.push({ id: `brand:${slug}`, label: brandNames[slug] ?? slug }))
  if (filters.priceMin !== null || filters.priceMax !== null) {
    chips.push({
      id: 'price',
      label: `RM ${filters.priceMin ?? 0} – ${filters.priceMax !== null ? `RM ${filters.priceMax}` : 'any'}`,
    })
  }
  if (filters.inStock) chips.push({ id: 'in_stock', label: 'In stock' })

  for (const [key, value] of Object.entries(filters.specs)) {
    const def = filterDefs?.specs?.find((s) => s.key === key)
    const label = def?.label ?? key
    if (Array.isArray(value)) value.forEach((v) => chips.push({ id: `spec:${key}:${v}`, label: `${label}: ${v}` }))
    else
      chips.push({
        id: `spec:${key}`,
        label: `${label}: ${value.min ?? '…'} – ${value.max ?? '…'}${def?.unit ? ` ${def.unit}` : ''}`,
      })
  }

  return chips
}

/** Returns a copy of `filters` without the thing a chip represents. */
export function removeChip(filters, chipId) {
  const next = { ...filters, brands: [...filters.brands], specs: { ...filters.specs }, page: 1 }
  const [type, key, value] = chipId.split(':')

  if (type === 'q') {
    next.q = ''
    if (next.sort === 'relevance') next.sort = 'popular' // nothing left to rank by
  } else if (type === 'brand') next.brands = next.brands.filter((b) => b !== key)
  else if (type === 'price') [next.priceMin, next.priceMax] = [null, null]
  else if (type === 'in_stock') next.inStock = false
  else if (type === 'spec') {
    if (value !== undefined && Array.isArray(next.specs[key])) {
      next.specs[key] = next.specs[key].filter((v) => v !== value)
      if (!next.specs[key].length) delete next.specs[key]
    } else delete next.specs[key]
  }

  return next
}
