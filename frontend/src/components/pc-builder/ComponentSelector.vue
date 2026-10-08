<script setup>
import { computed, reactive, ref, watch } from 'vue'
import ProductFilters from '@/components/products/ProductFilters.vue'
import ProductSortSelect from '@/components/products/ProductSortSelect.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { api, parseApiError } from '@/lib/api'
import { emptyFilters, filtersToApiParams } from '@/lib/catalogQuery'
import { useProductStore } from '@/stores/products'
import ComponentCard from './ComponentCard.vue'

/*
 * "Choose CPU": a dialog with search, filters, sorting and paging. It asks GET /api/pc-builder/parts, which is the
 * shop's product search plus a compatibility check of every part against what is already in the build:
 * compatible parts come first and incompatible ones are hidden unless the customer asks to see them.
 * It keeps its own state (not the URL), so browsing parts never disturbs the page behind it.
 */
const props = defineProps({
  open: { type: Boolean, required: true },
  row: { type: Object, default: null }, // the builder row being filled
  context: { type: Object, default: () => ({}) }, // the build so far: { cpu: { id, qty }, ... }
})
const emit = defineEmits(['close', 'select'])

const products = useProductStore()
const PER_PAGE = 8

const filters = ref(emptyFilters())
const results = reactive({ items: [], meta: { total: 0, current_page: 1, last_page: 1 }, loading: false, error: '' })
const searchText = ref('')
const filtersOpen = ref(false)
const includeIncompatible = ref(false)
const compat = reactive({ hidden: 0, counts: {} })

// With nothing else chosen yet there is nothing to be compatible with.
const hasContext = computed(() => Object.keys(props.context).some((slot) => slot !== props.row?.slot))

const definitions = computed(() => (props.row ? (products.filterDefs[props.row.category.slug] ?? null) : null))
const selectedId = computed(() => props.row?.selected?.product.id ?? null)

let controller = null
let searchTimer = null

async function load() {
  if (!props.row) return
  controller?.abort()
  controller = new AbortController()
  results.loading = true
  results.error = ''

  try {
    const { data } = await api.get('/api/pc-builder/parts', {
      params: {
        ...filtersToApiParams(filters.value, PER_PAGE),
        slot: props.row.slot,
        parts: props.context,
        include_incompatible: includeIncompatible.value ? 1 : 0,
      },
      signal: controller.signal,
    })
    results.items = data.data
    results.meta = data.meta
    compat.hidden = data.compatibility?.hidden_incompatible ?? 0
    compat.counts = data.compatibility?.counts ?? {}
  } catch (e) {
    if (e.code === 'ERR_CANCELED') return
    results.items = []
    results.error = parseApiError(e).message
  } finally {
    if (!controller.signal.aborted) results.loading = false
  }
}

function update(next) {
  filters.value = next
  load()
}

function setIncompatible(show) {
  includeIncompatible.value = show
  update({ ...filters.value, page: 1 })
}

function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(
    () =>
      update({
        ...filters.value,
        q: searchText.value.trim(),
        sort: searchText.value.trim() ? 'relevance' : 'popular',
        page: 1,
      }),
    300,
  )
}

const reset = () => {
  searchText.value = ''
  update({ ...emptyFilters(), category: props.row.category.slug })
}

// Every time the dialog opens for a slot, start fresh on that slot's category.
watch(
  () => [props.open, props.row?.slot],
  ([open]) => {
    if (!open || !props.row) return
    searchText.value = ''
    filtersOpen.value = false
    includeIncompatible.value = false
    filters.value = { ...emptyFilters(), category: props.row.category.slug }
    products.fetchFilterDefs(props.row.category.slug).catch(() => null)
    products.fetchBrands().catch(() => null)
    load()
  },
  { immediate: true },
)

const activeCount = computed(
  () =>
    filters.value.brands.length +
    Object.keys(filters.value.specs).length +
    Number(filters.value.inStock) +
    Number(filters.value.priceMin !== null || filters.value.priceMax !== null),
)
</script>

<template>
  <BaseModal :open="open" :title="row ? `Choose ${row.label}` : 'Choose a part'" size="xl" @close="emit('close')">
    <div v-if="row" class="grid gap-0 lg:grid-cols-[16rem_1fr]">
      <aside class="border-b border-line p-4 lg:border-b-0 lg:border-r lg:p-5" aria-label="Filters">
        <button
          type="button"
          class="flex w-full items-center justify-between rounded-xl border border-line bg-bg px-4 py-2.5 text-sm font-medium lg:hidden"
          :aria-expanded="filtersOpen"
          @click="filtersOpen = !filtersOpen"
        >
          <span
            >Filters<span v-if="activeCount" class="ml-2 rounded-full bg-accent px-2 py-0.5 text-xs text-white">{{
              activeCount
            }}</span></span
          >
          <span aria-hidden="true">{{ filtersOpen ? '−' : '+' }}</span>
        </button>
        <div :class="filtersOpen ? 'mt-4 block' : 'hidden'" class="lg:block">
          <ProductFilters
            :filters="filters"
            :definitions="definitions"
            :brands="products.brands"
            has-category
            @update="update"
          />
        </div>
      </aside>

      <section class="p-4 lg:p-5" aria-label="Parts">
        <div class="mb-4 flex flex-wrap items-center gap-3">
          <div class="relative min-w-48 flex-1">
            <label for="selector-search" class="sr-only">Search {{ row.label }}</label>
            <input
              id="selector-search"
              v-model="searchText"
              type="search"
              data-autofocus
              maxlength="100"
              autocomplete="off"
              :placeholder="`Search ${row.label.toLowerCase()}…`"
              class="w-full rounded-xl border border-line bg-bg px-4 py-2.5 text-sm text-ink placeholder:text-muted/70 focus:border-accent focus:outline-none"
              @input="onSearch"
            />
          </div>
          <ProductSortSelect
            :model-value="filters.sort"
            :searching="Boolean(filters.q)"
            @update:model-value="update({ ...filters, sort: $event, page: 1 })"
          />
        </div>

        <div v-if="hasContext" class="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <label class="flex cursor-pointer items-center gap-2.5 text-sm">
            <input
              type="checkbox"
              class="size-4 accent-accent"
              :checked="includeIncompatible"
              @change="setIncompatible($event.target.checked)"
            />
            Show incompatible parts
          </label>
          <p class="text-xs text-muted" data-testid="compat-note">
            <template v-if="!includeIncompatible && compat.hidden">
              {{ compat.hidden }} incompatible {{ compat.hidden === 1 ? 'part is' : 'parts are' }} hidden.
            </template>
            <template v-else>Parts that fit your build are listed first.</template>
          </p>
        </div>

        <p class="mb-3 text-sm text-muted" aria-live="polite">
          {{
            results.loading ? 'Loading…' : `${results.meta.total} ${results.meta.total === 1 ? 'option' : 'options'}`
          }}
        </p>

        <div v-if="results.error" role="alert">
          <EmptyState title="We couldn't load the parts" :text="results.error"
            ><BaseButton @click="load">Try again</BaseButton></EmptyState
          >
        </div>
        <EmptyState
          v-else-if="!results.loading && !results.items.length"
          title="No parts match your filters"
          text="Try removing a filter or searching for something else."
        >
          <BaseButton variant="secondary" @click="reset">Clear all filters</BaseButton>
        </EmptyState>
        <ul
          v-else
          class="space-y-3 transition-opacity"
          :class="results.loading ? 'opacity-60' : ''"
          :aria-busy="results.loading"
        >
          <li v-for="product in results.items" :key="product.id">
            <ComponentCard :product="product" :selected="product.id === selectedId" @select="emit('select', $event)" />
          </li>
        </ul>

        <div class="mt-5">
          <PaginationBar
            :page="results.meta.current_page"
            :last-page="results.meta.last_page"
            @change="update({ ...filters, page: $event })"
          />
        </div>
      </section>
    </div>
  </BaseModal>
</template>
