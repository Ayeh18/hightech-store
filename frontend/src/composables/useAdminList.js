import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, parseApiError } from '@/lib/api'

/**
 * A filtered, paged list from the admin API: loads on open, reloads when a filter changes, and never lets a slow old
 * answer overwrite a newer one. Filters may be pre-set from the address (`/admin/orders?status=PAID`).
 *
 *   const list = useAdminList('/api/admin/orders', { status: '', q: '' })
 *   list.params.status = 'PAID'   // the list reloads by itself a moment later (page 1)
 */
export function useAdminList(path, filters = {}, { debounceMs = 300 } = {}) {
  const route = useRoute()
  const params = reactive({ ...filters })
  for (const key of Object.keys(filters)) {
    if (typeof route?.query?.[key] === 'string') params[key] = route.query[key]
  }

  const rows = ref([])
  const meta = ref({ current_page: 1, last_page: 1, total: 0 })
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref('')

  let latest = 0
  let timer = null

  async function load(page = 1) {
    const mine = ++latest
    loading.value = true
    error.value = ''
    try {
      const query = Object.fromEntries(Object.entries(params).filter(([, value]) => value !== '' && value !== null))
      const { data } = await api.get(path, { params: { ...query, page } })
      if (mine !== latest) return // a newer request has already been sent
      rows.value = data.data
      meta.value = data.meta ?? { current_page: 1, last_page: 1, total: data.data.length }
      loaded.value = true
    } catch (e) {
      if (mine === latest) error.value = parseApiError(e).message
    } finally {
      if (mine === latest) loading.value = false
    }
  }

  /** Reload now, from page 1 (e.g. when a search form is submitted). */
  const apply = () => {
    clearTimeout(timer)
    return load(1)
  }

  // Any filter change reloads from page 1, after a short pause so typing in a search box does not send a request per key.
  watch(params, () => {
    clearTimeout(timer)
    timer = setTimeout(() => load(1), debounceMs)
  })

  onMounted(() => load(1))
  onBeforeUnmount(() => clearTimeout(timer))

  return {
    params,
    rows,
    meta,
    loading,
    loaded,
    error,
    load,
    apply,
    reload: () => load(meta.value.current_page),
  }
}
