import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, parseApiError } from '@/lib/api'
import { filtersToApiParams } from '@/lib/catalogQuery'

export const useProductStore = defineStore('products', () => {
  const categories = ref([])
  const products = ref([])
  const meta = ref({ total: 0, current_page: 1, last_page: 1, per_page: 12 })
  const loading = ref(false)
  const error = ref('')

  // Filter definitions per category slug, e.g. { cpu: { brands, price, specs } }. Fetched once, then reused.
  const filterDefs = ref({})
  const brands = ref([])

  let controller = null

  async function fetchCategories() {
    if (categories.value.length) return
    const { data } = await api.get('/api/categories')
    categories.value = data.data
  }

  async function fetchBrands() {
    if (brands.value.length) return
    const { data } = await api.get('/api/brands')
    brands.value = data.data
  }

  async function fetchFilterDefs(categorySlug) {
    if (!categorySlug || filterDefs.value[categorySlug]) return filterDefs.value[categorySlug] ?? null
    const { data } = await api.get(`/api/categories/${categorySlug}/filters`)
    filterDefs.value = { ...filterDefs.value, [categorySlug]: data.data }
    return data.data
  }

  /** Loads a page of products. A newer call cancels the one still in flight, so slow responses never overwrite fresh ones. */
  async function fetchProducts(filters, perPage = 12) {
    controller?.abort()
    controller = new AbortController()
    loading.value = true
    error.value = ''

    try {
      const { data } = await api.get('/api/products', {
        params: filtersToApiParams(filters, perPage),
        signal: controller.signal,
      })
      products.value = data.data
      meta.value = data.meta
    } catch (e) {
      if (e.code === 'ERR_CANCELED') return
      products.value = []
      error.value = parseApiError(e).message
    } finally {
      if (!controller.signal.aborted) loading.value = false
    }
  }

  async function fetchProduct(idOrSlug) {
    const { data } = await api.get(`/api/products/${encodeURIComponent(idOrSlug)}`)
    return data.data
  }

  return {
    categories,
    products,
    meta,
    loading,
    error,
    filterDefs,
    brands,
    fetchCategories,
    fetchBrands,
    fetchFilterDefs,
    fetchProducts,
    fetchProduct,
  }
})
