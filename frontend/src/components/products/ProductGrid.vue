<script setup>
import EmptyState from '@/components/ui/EmptyState.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import ProductCard from './ProductCard.vue'

defineProps({
  products: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  skeletons: { type: Number, default: 8 },
})
defineEmits(['retry', 'reset'])
</script>

<template>
  <div>
    <div v-if="error" role="alert">
      <EmptyState title="We couldn't load the products" :text="error">
        <BaseButton @click="$emit('retry')">Try again</BaseButton>
      </EmptyState>
    </div>

    <ul
      v-else-if="loading && !products.length"
      class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4"
      aria-busy="true"
      aria-label="Loading products"
    >
      <li v-for="n in skeletons" :key="n" class="h-80 animate-pulse rounded-card border border-line bg-surface" />
    </ul>

    <EmptyState
      v-else-if="!products.length"
      title="No products match your filters"
      text="Try removing a filter or searching for something else."
    >
      <BaseButton variant="secondary" @click="$emit('reset')">Clear all filters</BaseButton>
    </EmptyState>

    <ul
      v-else
      class="grid grid-cols-2 gap-3 transition-opacity sm:gap-4 lg:grid-cols-3 xl:grid-cols-4"
      :class="loading ? 'opacity-60' : ''"
    >
      <li v-for="product in products" :key="product.id"><ProductCard :product="product" /></li>
    </ul>
  </div>
</template>
