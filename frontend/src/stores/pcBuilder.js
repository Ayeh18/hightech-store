import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, parseApiError } from '@/lib/api'
import { formatSen, sumSen } from '@/lib/money'

/*
 * The PC builder's state.
 *
 * IMPORTANT: this store is for speed and comfort, not for truth. The total shown instantly while you click
 * is calculated here from the prices the browser already has; a moment later the server recalculates the build
 * from the database (POST /api/pc-builder/validate) and its answer replaces ours. Prices, stock and what is
 * "compatible" are only ever decided by Laravel.
 */

const DRAFT_KEY = 'hightech.builder.draft.v1'
const VALIDATE_DELAY_MS = 350

export const usePcBuilderStore = defineStore('pcBuilder', () => {
  const slots = ref([]) // from GET /api/pc-builder/components, in screen order
  const selection = ref({}) // { cpu: { product, quantity }, ... }
  const buildId = ref(null) // set while editing a saved build
  const buildName = ref('')
  const server = ref(null) // the server's last priced answer
  const serverFresh = ref(false) // true only when `server` describes the current selection
  const validating = ref(false)
  const validateError = ref('')
  const saving = ref(false)

  let timer = null
  let controller = null
  let sequence = 0

  // ---------- derived state ----------
  const rows = computed(() => slots.value.map((slot) => ({ ...slot, selected: selection.value[slot.slot] ?? null })))
  const requiredSlots = computed(() => slots.value.filter((s) => s.required))
  const requiredFilled = computed(() => requiredSlots.value.filter((s) => selection.value[s.slot]).length)
  const missingRequired = computed(() =>
    requiredSlots.value.filter((s) => !selection.value[s.slot]).map((s) => s.label),
  )
  const selectedCount = computed(() => Object.keys(selection.value).length)
  const isEmpty = computed(() => selectedCount.value === 0)

  /** Instant total in sen, from the prices the browser has (integer maths only). */
  const localTotalSen = computed(() =>
    sumSen(
      Object.values(selection.value).map(({ product, quantity }) => ({
        unitSen: product.effective_price.amount,
        quantity,
      })),
    ),
  )

  /** What to show: the server's number once it has confirmed the current selection, ours until then. */
  const totalSen = computed(() =>
    serverFresh.value && server.value ? server.value.subtotal.amount : localTotalSen.value,
  )
  const totalFormatted = computed(() => formatSen(totalSen.value))
  const verified = computed(() => serverFresh.value && server.value !== null)

  /** The server found different prices than the ones the browser was showing. */
  const pricesChanged = computed(() => verified.value && server.value.subtotal.amount !== localTotalSen.value)

  // The server's latest verdict. While a newer check is on its way the last answer stays visible (marked stale),
  // so messages do not flash away on every click.
  const stale = computed(() => server.value !== null && !serverFresh.value)
  const issues = computed(() => server.value?.issues ?? []) // stock / availability
  const compatibility = computed(() => server.value?.compatibility ?? null)
  const compatStatus = computed(() => compatibility.value?.status ?? null) // compatible | warning | incompatible
  const compatIssues = computed(() => compatibility.value?.issues ?? [])
  const compatMetrics = computed(() => compatibility.value?.metrics ?? null)

  /** Everything wrong with one slot: stock problems plus compatibility problems that involve it. */
  const issuesFor = (slot) => [
    ...issues.value
      .filter((issue) => issue.slot === slot)
      .map((i) => ({ key: `${i.code}:${slot}`, severity: i.severity, message: i.message })),
    ...compatIssues.value
      .filter((issue) => showsOnRow(issue, slot))
      .map((i) => ({ key: `${i.rule}:${slot}`, severity: i.severity, message: i.message })),
  ]

  /**
   * A problem between two parts (socket, size) is shown on both rows. A problem that depends on three or more parts
   * (e.g. "the PSU is too small for this CPU + GPU") is shown only on its first, main row: the PSU's.
   */
  function showsOnRow(issue, slot) {
    return issue.slots.length > 2 ? issue.slots[0] === slot : issue.slots.includes(slot)
  }

  /** Blocking problems (stock errors and compatibility errors) in the latest verdict. */
  const blockingCount = computed(
    () =>
      issues.value.filter((i) => i.severity === 'error').length +
      compatIssues.value.filter((i) => i.severity === 'error').length,
  )
  const purchasable = computed(() => verified.value && server.value.purchasable)

  // ---------- draft persistence ----------
  function saveDraft() {
    try {
      localStorage.setItem(
        DRAFT_KEY,
        JSON.stringify({ selection: selection.value, buildId: buildId.value, buildName: buildName.value }),
      )
    } catch {
      /* private mode or full storage: the builder still works, it just will not survive a refresh */
    }
  }

  function restoreDraft() {
    try {
      const draft = JSON.parse(localStorage.getItem(DRAFT_KEY) ?? 'null')
      if (draft?.selection && typeof draft.selection === 'object') {
        selection.value = draft.selection
        buildId.value = draft.buildId ?? null
        buildName.value = draft.buildName ?? ''
        if (!isEmpty.value) scheduleValidate(0)
      }
    } catch {
      /* corrupt draft: start empty */
    }
  }

  // ---------- server validation ----------
  const payload = () =>
    Object.entries(selection.value).map(([slot, { product, quantity }]) => ({ slot, product_id: product.id, quantity }))

  function scheduleValidate(delay = VALIDATE_DELAY_MS) {
    serverFresh.value = false
    clearTimeout(timer)
    if (isEmpty.value) {
      controller?.abort()
      server.value = null
      validating.value = false
      return
    }
    timer = setTimeout(validate, delay)
  }

  async function validate() {
    if (isEmpty.value) return
    controller?.abort()
    controller = new AbortController()
    const mine = ++sequence
    validating.value = true
    validateError.value = ''

    try {
      const { data } = await api.post('/api/pc-builder/validate', { items: payload() }, { signal: controller.signal })
      if (mine !== sequence) return // a newer change already superseded this answer

      // Replace what the browser knew (price, stock, name) with what the server says now.
      for (const line of data.data.items) {
        if (line.product && selection.value[line.slot]) selection.value[line.slot].product = line.product
      }
      server.value = data.data
      serverFresh.value = true
      saveDraft()
    } catch (e) {
      if (e.code === 'ERR_CANCELED') return
      validateError.value = parseApiError(e).message
    } finally {
      if (mine === sequence) validating.value = false
    }
  }

  // ---------- user actions ----------
  async function loadSlots() {
    if (slots.value.length) return
    const { data } = await api.get('/api/pc-builder/components')
    slots.value = data.data
  }

  function select(slot, product) {
    const definition = slots.value.find((s) => s.slot === slot)
    const keep = selection.value[slot]?.quantity ?? 1
    selection.value = {
      ...selection.value,
      [slot]: { product, quantity: Math.min(keep, definition?.max_quantity ?? 1) },
    }
    changed()
  }

  function remove(slot) {
    const next = { ...selection.value }
    delete next[slot]
    selection.value = next
    changed()
  }

  function setQuantity(slot, quantity) {
    const definition = slots.value.find((s) => s.slot === slot)
    const clamped = Math.max(1, Math.min(Math.floor(quantity) || 1, definition?.max_quantity ?? 1))
    if (!selection.value[slot] || selection.value[slot].quantity === clamped) return
    selection.value = { ...selection.value, [slot]: { ...selection.value[slot], quantity: clamped } }
    changed()
  }

  /** Stop editing the saved build; the next save creates a new one. */
  function detach() {
    buildId.value = null
    saveDraft()
  }

  function clear() {
    selection.value = {}
    buildId.value = null
    buildName.value = ''
    changed()
  }

  function changed() {
    saveDraft()
    scheduleValidate()
  }

  /** Save as a new build, or update the one being edited. Returns the saved build. */
  async function save(name) {
    saving.value = true
    try {
      const body = { name, items: payload() }
      const { data } = buildId.value
        ? await api.put(`/api/builds/${buildId.value}`, body)
        : await api.post('/api/builds', body)
      buildId.value = data.data.id
      buildName.value = data.data.name
      saveDraft()
      return data.data
    } finally {
      saving.value = false
    }
  }

  /** Open a saved build for editing. */
  async function loadBuild(id) {
    const { data } = await api.get(`/api/builds/${id}`)
    const next = {}
    for (const line of data.data.items) {
      if (line.product) next[line.slot] = { product: line.product, quantity: line.quantity }
    }
    selection.value = next
    buildId.value = data.data.id
    buildName.value = data.data.name
    server.value = data.data
    serverFresh.value = true
    saveDraft()
  }

  return {
    slots,
    selection,
    buildId,
    buildName,
    server,
    validating,
    validateError,
    saving,
    rows,
    requiredSlots,
    requiredFilled,
    missingRequired,
    selectedCount,
    isEmpty,
    localTotalSen,
    totalSen,
    totalFormatted,
    verified,
    pricesChanged,
    stale,
    issues,
    compatibility,
    compatStatus,
    compatIssues,
    compatMetrics,
    issuesFor,
    blockingCount,
    purchasable,
    loadSlots,
    select,
    remove,
    setQuantity,
    clear,
    detach,
    save,
    loadBuild,
    validate,
    restoreDraft,
  }
})
