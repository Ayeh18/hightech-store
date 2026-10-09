import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, parseApiError } from '@/lib/api'

/** The customer's address book. After every change we reload it, so "which one is the default" is always the server's answer. */
export const useAddressStore = defineStore('addresses', () => {
  const addresses = ref([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref('')

  const defaultAddress = computed(() => addresses.value.find((a) => a.is_default) ?? addresses.value[0] ?? null)

  async function fetch() {
    loading.value = true
    error.value = ''
    try {
      const { data } = await api.get('/api/addresses')
      addresses.value = data.data
      loaded.value = true
    } catch (e) {
      error.value = parseApiError(e).message
    } finally {
      loading.value = false
    }
  }

  async function create(payload) {
    const { data } = await api.post('/api/addresses', payload)
    await fetch()
    return data.data
  }

  async function update(id, payload) {
    const { data } = await api.put(`/api/addresses/${id}`, payload)
    await fetch()
    return data.data
  }

  async function remove(id) {
    await api.delete(`/api/addresses/${id}`)
    await fetch()
  }

  function reset() {
    addresses.value = []
    loaded.value = false
    error.value = ''
  }

  return { addresses, loading, loaded, error, defaultAddress, fetch, create, update, remove, reset }
})
