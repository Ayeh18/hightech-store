import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, parseApiError } from '@/lib/api'

/** The signed-in customer's saved builds ("My builds"). */
export const useBuildsStore = defineStore('builds', () => {
  const builds = ref([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref('')

  async function fetchBuilds() {
    loading.value = true
    error.value = ''
    try {
      const { data } = await api.get('/api/builds')
      builds.value = data.data
      loaded.value = true
    } catch (e) {
      error.value = parseApiError(e).message
    } finally {
      loading.value = false
    }
  }

  async function rename(id, name) {
    await api.put(`/api/builds/${id}`, { name })
    const build = builds.value.find((b) => b.id === id)
    if (build) build.name = name
  }

  async function remove(id) {
    await api.delete(`/api/builds/${id}`)
    builds.value = builds.value.filter((b) => b.id !== id)
  }

  async function duplicate(id) {
    await api.post(`/api/builds/${id}/duplicate`)
    await fetchBuilds()
  }

  return { builds, loading, loaded, error, fetchBuilds, rename, remove, duplicate }
})
