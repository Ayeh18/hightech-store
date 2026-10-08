<script setup>
import PriceTag from '@/components/products/PriceTag.vue'
import ProductImage from '@/components/products/ProductImage.vue'
import StockBadge from '@/components/products/StockBadge.vue'

defineProps({
  product: { type: Object, required: true },
  selected: { type: Boolean, default: false },
})
defineEmits(['select'])
</script>

<template>
  <article
    class="flex gap-4 rounded-card border bg-surface p-3 transition sm:p-4"
    :class="selected ? 'border-accent shadow-glow' : 'border-line hover:border-accent/40'"
  >
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
            selected ? 'border border-accent bg-accent-soft text-accent' : 'bg-accent text-white hover:bg-accent-hover'
          "
          :disabled="!product.in_stock && !selected"
          :aria-label="selected ? `${product.name} is selected` : `Select ${product.name}`"
          @click="$emit('select', product)"
        >
          {{ selected ? 'Selected ✓' : product.in_stock ? 'Select' : 'Out of stock' }}
        </button>
      </div>
    </div>
  </article>
</template>
