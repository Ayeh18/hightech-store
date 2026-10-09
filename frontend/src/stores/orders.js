import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, parseApiError } from '@/lib/api'

/** The customer's orders. Always read from the server; nothing about an order is computed here. */
export const useOrderStore = defineStore('orders', () => {
  const orders = ref([])
  const meta = ref({ current_page: 1, last_page: 1, total: 0 })
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref('')

  async function fetchOrders(page = 1) {
    loading.value = true
    error.value = ''
    try {
      const { data } = await api.get('/api/orders', { params: { page } })
      orders.value = data.data
      meta.value = data.meta
      loaded.value = true
    } catch (e) {
      error.value = parseApiError(e).message
    } finally {
      loading.value = false
    }
  }

  async function fetchOrder(id) {
    const { data } = await api.get(`/api/orders/${id}`)
    return data.data
  }

  async function cancel(id) {
    const { data } = await api.post(`/api/orders/${id}/cancel`)
    return data.data
  }

  /**
   * "Pay now". The server decides the amount (the order's own total) and returns the payment with the address of the
   * payment page. Asking again while a payment is waiting returns the same one.
   */
  async function startPayment(id) {
    const { data } = await api.post(`/api/orders/${id}/payments`)
    return data.data
  }

  function reset() {
    orders.value = []
    loaded.value = false
    error.value = ''
  }

  return { orders, meta, loading, loaded, error, fetchOrders, fetchOrder, cancel, startPayment, reset }
})
