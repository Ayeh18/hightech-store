<script setup>
import { RouterLink } from 'vue-router'
import PriceTag from './PriceTag.vue'
import ProductImage from './ProductImage.vue'
import StockBadge from './StockBadge.vue'

defineProps({ product: { type: Object, required: true } })
</script>

<template>
  <article
    class="group relative flex h-full flex-col overflow-hidden rounded-card border border-line bg-surface transition hover:-translate-y-0.5 hover:border-accent/50 hover:shadow-glow"
  >
    <div class="relative aspect-[4/3] overflow-hidden border-b border-line bg-elevated">
      <ProductImage :src="product.image" :alt="product.name" :brand="product.brand.name" />
      <span
        v-if="product.on_sale"
        class="absolute left-3 top-3 rounded-md bg-accent px-2 py-0.5 text-xs font-semibold text-white"
        >Sale</span
      >
    </div>

    <div class="flex flex-1 flex-col gap-3 p-4">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide text-muted">{{ product.brand.name }}</p>
        <h3 class="mt-1 line-clamp-2 font-medium leading-snug">
          <!-- The stretched link makes the whole card clickable while keeping one real link for keyboards and screen readers. -->
          <RouterLink
            :to="{ name: 'product', params: { slug: product.slug } }"
            class="after:absolute after:inset-0 focus-visible:outline-none"
          >
            {{ product.name }}
          </RouterLink>
        </h3>
      </div>

      <ul v-if="product.highlights.length" class="flex flex-wrap gap-1.5">
        <li
          v-for="h in product.highlights"
          :key="h.key"
          class="rounded-md border border-line bg-bg px-2 py-0.5 text-xs text-muted"
          :title="h.label"
        >
          {{ h.display }}
        </li>
      </ul>

      <div class="mt-auto space-y-1.5">
        <PriceTag :price="product.price" :effective="product.effective_price" />
        <StockBadge :in-stock="product.in_stock" :low-stock="product.low_stock" />
      </div>
    </div>
  </article>
</template>
