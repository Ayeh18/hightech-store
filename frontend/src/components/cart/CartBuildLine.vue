<script setup>
import { computed } from 'vue'
import QuantityStepper from '@/components/ui/QuantityStepper.vue'
import { MAX_BUILD_QUANTITY } from '@/lib/cartLimits'
import CartIssues from './CartIssues.vue'

const props = defineProps({
  line: { type: Object, required: true },
  busy: { type: Boolean, default: false },
})
defineEmits(['quantity', 'remove'])

const status = computed(() => props.line.compatibility?.status ?? 'compatible')
const badge = computed(
  () =>
    ({
      compatible: { text: '✓ Compatible', tone: 'bg-success/15 text-success' },
      warning: { text: '⚠ Compatible, with warnings', tone: 'bg-warning/15 text-warning' },
      incompatible: { text: '✗ Incompatible', tone: 'bg-danger/15 text-danger' },
    })[status.value],
)

// The price at the time the PC was added, shown struck through only for parts whose price has since changed.
const changed = (part) =>
  part.unit_price && part.price_when_added && part.unit_price.amount !== part.price_when_added.amount
</script>

<template>
  <article
    class="rounded-card border bg-surface p-4 sm:p-5"
    :class="line.issues.some((i) => i.severity === 'error') ? 'border-danger/50' : 'border-line'"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-xs font-medium uppercase tracking-wide text-accent">Complete PC build</p>
        <h3 class="mt-0.5 font-display text-lg font-semibold leading-snug">{{ line.name }}</h3>
        <span class="mt-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium" :class="badge.tone">
          {{ badge.text }}
        </span>
      </div>

      <div class="flex items-center justify-between gap-4 sm:flex-col sm:items-end">
        <p class="font-display text-lg font-bold tabular-nums" data-testid="line-total">
          {{ line.unit_price ? line.line_total.formatted : '—' }}
        </p>
        <div class="flex items-center gap-2">
          <QuantityStepper
            :model-value="line.quantity"
            :max="MAX_BUILD_QUANTITY"
            :disabled="busy"
            :label="`Copies of ${line.name}`"
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

    <details class="group mt-4 rounded-xl border border-line bg-bg/60">
      <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium">
        <span>{{ line.components.length }} parts in this PC</span>
        <span class="text-muted transition group-open:rotate-180" aria-hidden="true">▾</span>
      </summary>
      <ul class="divide-y divide-line border-t border-line text-sm">
        <li v-for="part in line.components" :key="part.slot" class="flex items-start justify-between gap-4 px-4 py-2.5">
          <div class="min-w-0">
            <p class="text-xs text-muted">{{ part.slot_label }}</p>
            <p class="truncate">
              {{ part.product.name }}<span v-if="part.quantity > 1" class="text-muted"> ×{{ part.quantity }}</span>
            </p>
          </div>
          <p class="shrink-0 text-right tabular-nums">
            <template v-if="part.unit_price">
              <s v-if="changed(part)" class="mr-1.5 text-xs text-muted">{{ part.price_when_added.formatted }}</s>
              {{ part.unit_price.formatted }}
            </template>
            <span v-else class="text-danger">Unavailable</span>
          </p>
        </li>
      </ul>
    </details>

    <CartIssues :issues="line.issues" />
  </article>
</template>
