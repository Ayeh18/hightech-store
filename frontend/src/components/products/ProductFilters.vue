<script setup>
import { computed, reactive, watch } from 'vue'

/*
 * Every control here is generated from `definitions` (what the API returned for the category),
 * so adding a spec in the database adds a filter to the shop with no frontend change.
 * The component never edits `filters` directly: it emits a full updated copy.
 */
const props = defineProps({
  filters: { type: Object, required: true },
  definitions: { type: Object, default: null }, // /api/categories/{slug}/filters
  brands: { type: Array, default: () => [] }, // fallback brand list when no category is selected
  hasCategory: { type: Boolean, default: false },
})
const emit = defineEmits(['update'])

const brandOptions = computed(
  () => props.definitions?.brands ?? props.brands.map((b) => ({ slug: b.slug, name: b.name, count: b.products_count })),
)
const specDefs = computed(() => props.definitions?.specs ?? [])

// Text boxes keep what the user typed; they are applied when the field loses focus or Enter is pressed.
const draft = reactive({ priceMin: '', priceMax: '', specs: {} })

function syncDraft() {
  draft.priceMin = props.filters.priceMin ?? ''
  draft.priceMax = props.filters.priceMax ?? ''
  draft.specs = Object.fromEntries(
    specDefs.value
      .filter((spec) => spec.type === 'number')
      .map((spec) => [
        spec.key,
        { min: props.filters.specs[spec.key]?.min ?? '', max: props.filters.specs[spec.key]?.max ?? '' },
      ]),
  )
}
watch([() => props.filters, specDefs], syncDraft, { immediate: true, deep: true })

const copy = () => ({ ...props.filters, brands: [...props.filters.brands], specs: { ...props.filters.specs }, page: 1 })
const toNumber = (v) => (v === '' || v === null || Number.isNaN(Number(v)) || Number(v) < 0 ? null : Number(v))

function toggleBrand(slug) {
  const next = copy()
  next.brands = next.brands.includes(slug) ? next.brands.filter((b) => b !== slug) : [...next.brands, slug]
  emit('update', next)
}

function toggleInStock(checked) {
  emit('update', { ...copy(), inStock: checked })
}

function applyPrice() {
  const next = copy()
  next.priceMin = toNumber(draft.priceMin)
  next.priceMax = toNumber(draft.priceMax)
  emit('update', next)
}

function toggleOption(key, value) {
  const next = copy()
  const current = Array.isArray(next.specs[key]) ? next.specs[key] : []
  const updated = current.includes(value) ? current.filter((v) => v !== value) : [...current, value]
  if (updated.length) next.specs[key] = updated
  else delete next.specs[key]
  emit('update', next)
}

function applyRange(key) {
  const next = copy()
  const min = toNumber(draft.specs[key]?.min)
  const max = toNumber(draft.specs[key]?.max)
  if (min === null && max === null) delete next.specs[key]
  else next.specs[key] = { ...(min !== null && { min }), ...(max !== null && { max }) }
  emit('update', next)
}

const isChecked = (key, value) => Array.isArray(props.filters.specs[key]) && props.filters.specs[key].includes(value)
const input =
  'w-full rounded-lg border border-line bg-bg px-3 py-2 text-sm text-ink placeholder:text-muted/60 focus:border-accent focus:outline-none'
</script>

<template>
  <div class="space-y-6 text-sm">
    <fieldset>
      <label class="flex cursor-pointer items-center gap-3">
        <input
          type="checkbox"
          class="size-4 accent-accent"
          :checked="filters.inStock"
          @change="toggleInStock($event.target.checked)"
        />
        <span class="font-medium">In stock only</span>
      </label>
    </fieldset>

    <fieldset>
      <legend class="mb-2 font-display font-semibold">Price (RM)</legend>
      <div class="flex items-center gap-2">
        <input
          v-model="draft.priceMin"
          type="number"
          min="0"
          inputmode="numeric"
          placeholder="Min"
          aria-label="Minimum price in ringgit"
          :class="input"
          @change="applyPrice"
          @keyup.enter="applyPrice"
        />
        <span class="text-muted" aria-hidden="true">–</span>
        <input
          v-model="draft.priceMax"
          type="number"
          min="0"
          inputmode="numeric"
          placeholder="Max"
          aria-label="Maximum price in ringgit"
          :class="input"
          @change="applyPrice"
          @keyup.enter="applyPrice"
        />
      </div>
      <p v-if="definitions" class="mt-1.5 text-xs text-muted">
        Range in this category: RM {{ Math.floor(definitions.price.min / 100) }} – RM
        {{ Math.ceil(definitions.price.max / 100) }}
      </p>
    </fieldset>

    <fieldset v-if="brandOptions.length">
      <legend class="mb-2 font-display font-semibold">Brand</legend>
      <ul class="max-h-56 space-y-2 overflow-y-auto pr-1">
        <li v-for="brand in brandOptions" :key="brand.slug">
          <label class="flex cursor-pointer items-center gap-3">
            <input
              type="checkbox"
              class="size-4 accent-accent"
              :checked="filters.brands.includes(brand.slug)"
              @change="toggleBrand(brand.slug)"
            />
            <span class="flex-1">{{ brand.name }}</span>
            <span class="text-xs text-muted">{{ brand.count }}</span>
          </label>
        </li>
      </ul>
    </fieldset>

    <fieldset v-for="spec in specDefs" :key="spec.key">
      <legend class="mb-2 font-display font-semibold">
        {{ spec.label }}<span v-if="spec.unit" class="font-normal text-muted"> ({{ spec.unit }})</span>
      </legend>

      <div v-if="spec.type === 'number'" class="flex items-center gap-2">
        <input
          v-model="draft.specs[spec.key].min"
          type="number"
          min="0"
          :placeholder="String(spec.min)"
          :aria-label="`Minimum ${spec.label}`"
          :class="input"
          @change="applyRange(spec.key)"
        />
        <span class="text-muted" aria-hidden="true">–</span>
        <input
          v-model="draft.specs[spec.key].max"
          type="number"
          min="0"
          :placeholder="String(spec.max)"
          :aria-label="`Maximum ${spec.label}`"
          :class="input"
          @change="applyRange(spec.key)"
        />
      </div>

      <ul v-else class="space-y-2">
        <li v-for="option in spec.options" :key="option.value">
          <label class="flex cursor-pointer items-center gap-3">
            <input
              type="checkbox"
              class="size-4 accent-accent"
              :checked="isChecked(spec.key, option.value)"
              @change="toggleOption(spec.key, option.value)"
            />
            <span class="flex-1">{{ option.value }}</span>
            <span class="text-xs text-muted">{{ option.count }}</span>
          </label>
        </li>
      </ul>
    </fieldset>

    <p v-if="!hasCategory" class="rounded-xl border border-line bg-bg px-3 py-3 text-xs text-muted">
      Pick a category above to filter by specifications such as socket, memory type or capacity.
    </p>
  </div>
</template>
