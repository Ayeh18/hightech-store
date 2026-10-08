<script setup>
import { computed } from 'vue'
import PriceTag from '@/components/products/PriceTag.vue'
import ProductImage from '@/components/products/ProductImage.vue'
import StockBadge from '@/components/products/StockBadge.vue'

const props = defineProps({
  product: { type: Object, required: true },
  selected: { type: Boolean, default: false },
})
defineEmits(['select'])

// `compatibility` comes from the server's check of this part against the rest of the build.
const status = computed(() => props.product.compatibility?.status ?? 'compatible')
const issues = computed(() => props.product.compatibility?.issues ?? [])
const incompatible = computed(() => status.value === 'incompatible')

const border = computed(() => {
  if (props.selected) return 'border-accent shadow-glow'
  if (incompatible.value) return 'border-danger/40 hover:border-danger/70'
  if (status.value === 'warning') return 'border-warning/40 hover:border-warning/70'
  return 'border-line hover:border-accent/40'
})

const buttonLabel = computed(() => {
  if (props.selected) return 'Selected ✓'
  if (!props.product.in_stock) return 'Out of stock'
  return incompatible.value ? 'Select anyway' : 'Select'
})
</script>

<template>
  <article
    class="rounded-card border bg-surface p-3 transition sm:p-4"
    :class="[border, incompatible && !selected ? 'bg-surface/60' : '']"
  >
    <div class="flex gap-4">
      <div class="size-20 shrink-0 overflow-hidden rounded-xl border border-line bg-elevated sm:size-24">
        <ProductImage :src="product.image" :alt="product.name" :brand="product.brand.name" />
      </div>

      <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
        <div class="min-w-0 flex-1">
          <p class="text-xs font-medium uppercase tracking-wide text-muted">{{ product.brand.name }}</p>
          <h4 class="line-clamp-2 font-medium leading-snug">{{ product.name }}</h4>
          <ul v-if="product.highlights.length" class="mt-1.5 flex flex-wrap gap-1.5">
            <li
              v-for="h in product.highlights"
              :key="h.key"
              class="rounded-md border border-line bg-bg px-2 py-0.5 text-xs text-muted"
              :title="h.label"
            >
              {{ h.display }}
            </li>
          </ul>
          <p v-if="status === 'compatible' && product.compatibility" class="mt-2 text-xs font-medium text-success">
            <span aria-hidden="true">✓</span> Compatible with your build
          </p>
        </div>

        <div class="flex items-center justify-between gap-4 sm:flex-col sm:items-end sm:gap-2">
          <div class="space-y-1 sm:text-right">
            <PriceTag :price="product.price" :effective="product.effective_price" />
            <StockBadge :in-stock="product.in_stock" :low-stock="product.low_stock" />
          </div>
          <button
            type="button"
            class="shrink-0 rounded-xl px-5 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
            :class="
              selected
                ? 'border border-accent bg-accent-soft text-accent'
                : incompatible
                  ? 'border border-line text-ink hover:border-danger/60'
                  : 'bg-accent text-white hover:bg-accent-hover'
            "
            :disabled="!product.in_stock && !selected"
            :aria-label="selected ? `${product.name} is selected` : `Select ${product.name}`"
            @click="$emit('select', product)"
          >
            {{ buttonLabel }}
          </button>
        </div>
      </div>
    </div>

    <ul v-if="issues.length" class="mt-3 space-y-1 border-t border-line pt-3">
      <li
        v-for="issue in issues"
        :key="issue.rule"
        class="flex items-start gap-2 text-xs"
        :class="issue.severity === 'error' ? 'text-danger' : 'text-warning'"
      >
        <span aria-hidden="true">{{ issue.severity === 'error' ? '✗' : '⚠' }}</span>
        <span
          ><span class="sr-only">{{ issue.severity === 'error' ? 'Incompatible: ' : 'Warning: ' }}</span
          >{{ issue.message }}</span
        >
      </li>
    </ul>
  </article>
</template>
