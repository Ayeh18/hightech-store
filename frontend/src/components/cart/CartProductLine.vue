<script setup>
import { RouterLink } from 'vue-router'
import ProductImage from '@/components/products/ProductImage.vue'
import QuantityStepper from '@/components/ui/QuantityStepper.vue'
import { MAX_PRODUCT_QUANTITY } from '@/lib/cartLimits'
import CartIssues from './CartIssues.vue'

defineProps({
  line: { type: Object, required: true },
  busy: { type: Boolean, default: false },
})
defineEmits(['quantity', 'remove'])
</script>

<template>
  <article
    class="rounded-card border bg-surface p-4 sm:p-5"
    :class="line.issues.some((i) => i.severity === 'error') ? 'border-danger/50' : 'border-line'"
  >
    <div class="flex gap-4">
      <div class="size-20 shrink-0 overflow-hidden rounded-xl border border-line bg-elevated sm:size-24">
        <ProductImage :src="line.product.image" :alt="line.product.name" :brand="line.product.brand.name" />
      </div>

      <div class="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <p class="text-xs font-medium uppercase tracking-wide text-muted">{{ line.product.brand.name }}</p>
          <h3 class="mt-0.5 line-clamp-2 font-medium leading-snug">
            <RouterLink :to="{ name: 'product', params: { slug: line.product.slug } }" class="hover:text-accent">
              {{ line.name }}
            </RouterLink>
          </h3>
          <p class="mt-1 text-sm text-muted">
            <template v-if="line.unit_price">{{ line.unit_price.formatted }} each</template>
            <template v-else>No longer available</template>
          </p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 sm:flex-col sm:items-end">
          <p class="font-display text-lg font-bold tabular-nums" data-testid="line-total">
            {{ line.unit_price ? line.line_total.formatted : '—' }}
          </p>
          <div class="flex flex-wrap items-center gap-2">
            <QuantityStepper
              :model-value="line.quantity"
              :max="MAX_PRODUCT_QUANTITY"
              :disabled="busy"
              :label="`Quantity of ${line.name}`"
              @update:model-value="$emit('quantity', $event)"
            />
            <button
              type="button"
              class="rounded-lg px-2 py-1.5 text-sm text-muted transition hover:text-danger disabled:opacity-40"
              :disabled="busy"
              :aria-label="`Remove ${line.name} from cart`"
              @click="$emit('remove')"
            >
              Remove
            </button>
          </div>
        </div>
      </div>
    </div>

    <CartIssues :issues="line.issues" />
  </article>
</template>
