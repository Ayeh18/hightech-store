<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CategoryTabs from '@/components/products/CategoryTabs.vue'
import ProductFilters from '@/components/products/ProductFilters.vue'
import ProductGrid from '@/components/products/ProductGrid.vue'
import ProductSortSelect from '@/components/products/ProductSortSelect.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { activeFilterChips, emptyFilters, filtersToQuery, queryToFilters, removeChip } from '@/lib/catalogQuery'
import { useProductStore } from '@/stores/products'

const store = useProductStore()
const route = useRoute()
const router = useRouter()

const PER_PAGE = 12
const filtersOpen = ref(false)

// The URL is the single source of truth: every control edits the URL, and the URL drives the data.
const filters = computed(() => queryToFilters(route.query))
const definitions = computed(() => (filters.value.category ? (store.filterDefs[filters.value.category] ?? null) : null))
const chips = computed(() => activeFilterChips(filters.value, definitions.value))
const category = computed(() => store.categories.find((c) => c.slug === filters.value.category))
const title = computed(
  () => category.value?.name ?? (filters.value.q ? `Results for "${filters.value.q}"` : 'All products'),
)

function apply(next, { replace = false } = {}) {
  router[replace ? 'replace' : 'push']({ name: 'products', query: filtersToQuery(next) })
}

function selectCategory(slug) {
  // Spec filters belong to one category, so they are dropped when the category changes.
  apply({ ...filters.value, category: slug, specs: {}, brands: [], page: 1 })
}

function goToPage(page) {
  apply({ ...filters.value, page })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const reset = () => apply({ ...emptyFilters(), category: filters.value.category, q: '' })

async function load() {
  await Promise.all([
    store.fetchProducts(filters.value, PER_PAGE),
    filters.value.category ? store.fetchFilterDefs(filters.value.category).catch(() => null) : Promise.resolve(),
  ])
}

onMounted(() => {
  store.fetchCategories().catch(() => null)
  store.fetchBrands().catch(() => null)
})

// Reload whenever the URL's filters change (including back/forward navigation).
watch(() => route.fullPath, load, { immediate: true })
watch(
  () => [filters.value.q, filters.value.category],
  () => (document.title = `${title.value} · HighTech Store`),
  { immediate: true },
)
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <header class="mb-6">
      <h1 class="font-display text-3xl font-semibold tracking-tight">{{ title }}</h1>
      <p class="mt-1 text-sm text-muted" aria-live="polite">
        <template v-if="store.error" />
        <template v-else-if="store.loading && !store.products.length">Loading…</template>
        <template v-else>{{ store.meta.total }} {{ store.meta.total === 1 ? 'product' : 'products' }}</template>
      </p>
    </header>

    <CategoryTabs
      :categories="store.categories"
      :model-value="filters.category"
      class="mb-6"
      @update:model-value="selectCategory"
    />

    <div class="grid gap-8 lg:grid-cols-[17rem_1fr]">
      <!-- Filters: a slide-down panel on mobile, a sticky sidebar on desktop. -->
      <aside class="lg:sticky lg:top-24 lg:self-start" aria-label="Filters">
        <button
          type="button"
          class="mb-3 flex w-full items-center justify-between rounded-xl border border-line bg-surface px-4 py-3 text-sm font-medium lg:hidden"
          :aria-expanded="filtersOpen"
          @click="filtersOpen = !filtersOpen"
        >
          <span
            >Filters<span v-if="chips.length" class="ml-2 rounded-full bg-accent px-2 py-0.5 text-xs text-white">{{
              chips.length
            }}</span></span
          >
          <span aria-hidden="true">{{ filtersOpen ? '−' : '+' }}</span>
        </button>
        <div :class="filtersOpen ? 'block' : 'hidden'" class="rounded-card border border-line bg-surface p-5 lg:block">
          <ProductFilters
            :filters="filters"
            :definitions="definitions"
            :brands="store.brands"
            :has-category="Boolean(filters.category)"
            @update="apply"
          />
        </div>
      </aside>

      <section aria-label="Products">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <ul v-if="chips.length" class="flex flex-wrap items-center gap-2" aria-label="Active filters">
            <li v-for="chip in chips" :key="chip.id">
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full border border-accent/40 bg-accent-soft px-3 py-1 text-xs font-medium text-accent transition hover:border-accent"
                :aria-label="`Remove filter ${chip.label}`"
                @click="apply(removeChip(filters, chip.id))"
              >
                {{ chip.label }} <span aria-hidden="true">×</span>
              </button>
            </li>
            <li>
              <button type="button" class="px-2 text-xs text-muted underline-offset-2 hover:underline" @click="reset">
                Clear all
              </button>
            </li>
          </ul>
          <span v-else />
          <ProductSortSelect
            :model-value="filters.sort"
            @update:model-value="apply({ ...filters, sort: $event, page: 1 })"
          />
        </div>

        <ProductGrid
          :products="store.products"
          :loading="store.loading"
          :error="store.error"
          :skeletons="PER_PAGE"
          @retry="load"
          @reset="reset"
        />

        <div class="mt-8">
          <PaginationBar :page="store.meta.current_page" :last-page="store.meta.last_page" @change="goToPage" />
        </div>
      </section>
    </div>
  </div>
</template>
