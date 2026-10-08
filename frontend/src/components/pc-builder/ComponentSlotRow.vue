<script setup>
import ProductImage from '@/components/products/ProductImage.vue'
import PriceTag from '@/components/products/PriceTag.vue'

/*
 * One row of the builder ("CPU", "Motherboard", ...): empty with a Choose button, or filled with the
 * chosen product, a quantity stepper (for RAM and storage) and any problems the server found.
 */
const props = defineProps({
  row: { type: Object, required: true }, // { slot, label, required, max_quantity, category, selected }
  issues: { type: Array, default: () => [] },
})
const emit = defineEmits(['choose', 'remove', 'quantity'])

const stepQuantity = (delta) => emit('quantity', props.row.selected.quantity + delta)
</script>

<template>
  <section
    class="rounded-card border bg-surface p-4 transition sm:p-5"
    :class="issues.some((i) => i.severity === 'error') ? 'border-danger/50' : 'border-line'"
    :aria-label="row.label"
  >
    <div class="flex items-center justify-between gap-3">
      <h3 class="font-display font-semibold">{{ row.label }}</h3>
      <span
        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
        :class="row.required ? 'bg-accent-soft text-accent' : 'bg-elevated text-muted'"
      >
        {{ row.required ? 'Required' : 'Optional' }}
      </span>
    </div>

    <div
      v-if="!row.selected"
      class="mt-3 flex items-center justify-between gap-3 rounded-xl border border-dashed border-line bg-bg/60 px-4 py-4"
    >
      <p class="text-sm text-muted">Nothing selected yet.</p>
      <button
        type="button"
        class="shrink-0 rounded-xl bg-accent px-4 py-2 text-sm font-semibold text-white shadow-glow transition hover:bg-accent-hover"
        @click="emit('choose')"
      >
        Choose {{ row.label }}
      </button>
    </div>

    <div v-else class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-center">
      <div class="size-20 shrink-0 overflow-hidden rounded-xl border border-line bg-elevated">
        <ProductImage
          :src="row.selected.product.image"
          :alt="row.selected.product.name"
          :brand="row.selected.product.brand.name"
        />
      </div>

      <div class="min-w-0 flex-1">
        <p class="text-xs font-medium uppercase tracking-wide text-muted">{{ row.selected.product.brand.name }}</p>
        <p class="line-clamp-2 font-medium leading-snug">{{ row.selected.product.name }}</p>
        <ul v-if="row.selected.product.highlights.length" class="mt-1.5 flex flex-wrap gap-1.5">
          <li
            v-for="h in row.selected.product.highlights"
            :key="h.key"
            class="rounded-md border border-line bg-bg px-2 py-0.5 text-xs text-muted"
          >
            {{ h.display }}
          </li>
        </ul>
      </div>

      <div class="flex items-center justify-between gap-4 sm:flex-col sm:items-end sm:gap-2">
        <div class="text-right">
          <PriceTag :price="row.selected.product.price" :effective="row.selected.product.effective_price" />
          <p v-if="row.selected.quantity > 1" class="text-xs text-muted">× {{ row.selected.quantity }}</p>
        </div>

        <div class="flex items-center gap-2">
          <div
            v-if="row.max_quantity > 1"
            class="flex items-center rounded-lg border border-line"
            role="group"
            :aria-label="`${row.label} quantity`"
          >
            <button
              type="button"
              class="px-2.5 py-1 text-muted transition enabled:hover:text-ink disabled:opacity-30"
              :disabled="row.selected.quantity <= 1"
              aria-label="Decrease quantity"
              @click="stepQuantity(-1)"
            >
              −
            </button>
            <span class="min-w-6 text-center text-sm tabular-nums" aria-live="polite">{{ row.selected.quantity }}</span>
            <button
              type="button"
              class="px-2.5 py-1 text-muted transition enabled:hover:text-ink disabled:opacity-30"
              :disabled="row.selected.quantity >= row.max_quantity"
              aria-label="Increase quantity"
              @click="stepQuantity(1)"
            >
              +
            </button>
          </div>
          <button
            type="button"
            class="rounded-lg border border-line px-3 py-1.5 text-sm transition hover:border-accent/60"
            @click="emit('choose')"
          >
            Change
          </button>
          <button
            type="button"
            class="rounded-lg px-2 py-1.5 text-sm text-muted transition hover:text-danger"
            :aria-label="`Remove ${row.label}`"
            @click="emit('remove')"
          >
            Remove
          </button>
        </div>
      </div>
    </div>

    <ul v-if="issues.length" class="mt-3 space-y-1.5">
      <li
        v-for="issue in issues"
        :key="issue.code"
        class="flex items-start gap-2 text-sm"
        :class="issue.severity === 'error' ? 'text-danger' : 'text-warning'"
        role="alert"
      >
        <span aria-hidden="true">{{ issue.severity === 'error' ? '✗' : '⚠' }}</span
        >{{ issue.message }}
      </li>
    </ul>
  </section>
</template>
