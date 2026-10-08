import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, parseApiError } from '@/lib/api'

/*
 * The shopping cart.
 *
 * The browser holds NO prices or rules here. Every response from the API is the whole cart, re-priced and
 * re-validated by Laravel (current prices, stock across all lines, build compatibility), and it simply replaces
 * what we had. If the server says a line is a problem, we show that; we never decide it ourselves.
 */
export const useCartStore = defineStore('cart', () => {
  const cart = ref(null)
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref('')
  const busy = ref(false) // a change is in flight

  const items = computed(() => cart.value?.items ?? [])
  const itemCount = computed(() => cart.value?.item_count ?? 0)
  const subtotal = computed(() => cart.value?.subtotal ?? null)
  const checkoutReady = computed(() => cart.value?.checkout_ready ?? false)
  const hasPriceChanges = computed(() => cart.value?.has_price_changes ?? false)

  async function fetch() {
    loading.value = true
    error.value = ''
    try {
      const { data } = await api.get('/api/cart')
      cart.value = data.data
      loaded.value = true
    } catch (e) {
      error.value = parseApiError(e).message
    } finally {
      loading.value = false
    }
  }

  /** Runs a change and adopts the cart the server sends back. Errors are thrown for the caller to display. */
  async function change(request) {
    busy.value = true
    try {
      const { data } = await request()
      cart.value = data.data
      loaded.value = true
      return data.data
    } finally {
      busy.value = false
    }
  }

  const addProduct = (productId, quantity = 1) =>
    change(() => api.post('/api/cart/products', { product_id: productId, quantity }))

  /** `source` is either { buildId } for a saved build or { items, name } for the parts in the builder. */
  const addBuild = (source, quantity = 1) =>
    change(() =>
      api.post('/api/cart/builds', {
        quantity,
        ...(source.buildId ? { build_id: source.buildId } : { items: source.items }),
        ...(source.name ? { name: source.name } : {}),
      }),
    )

  const updateQuantity = (itemId, quantity) => change(() => api.put(`/api/cart/items/${itemId}`, { quantity }))
  const remove = (itemId) => change(() => api.delete(`/api/cart/items/${itemId}`))
  const clear = () => change(() => api.delete('/api/cart'))
  const acceptPrices = () => change(() => api.post('/api/cart/accept-prices'))

  /** Forget everything (used when the customer logs out, so the next person never sees this cart). */
  function reset() {
    cart.value = null
    loaded.value = false
    loading.value = false
    error.value = ''
  }

  return {
    cart,
    loading,
    loaded,
    error,
    busy,
    items,
    itemCount,
    subtotal,
    checkoutReady,
    hasPriceChanges,
    fetch,
    addProduct,
    addBuild,
    updateQuantity,
    remove,
    clear,
    acceptPrices,
    reset,
  }
})
