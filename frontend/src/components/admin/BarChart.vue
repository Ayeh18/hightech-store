<script setup>
import { computed } from 'vue'

/**
 * A simple bar chart made of plain boxes (no chart library). `points` are [{ label, value, text }]: `value` sets the bar's
 * height, `text` is what the tooltip says. Only some x-axis labels are drawn so they never crowd each other.
 */
const props = defineProps({
  points: { type: Array, required: true },
  summary: { type: String, required: true }, // read out by screen readers, since the picture itself cannot be
  height: { type: Number, default: 160 },
})

const max = computed(() => Math.max(1, ...props.points.map((p) => p.value)))
const every = computed(() => Math.max(1, Math.ceil(props.points.length / 8)))
const percent = (value) => (value <= 0 ? 0 : Math.max(2, Math.round((value / max.value) * 100))) // a sliver stays visible
</script>

<template>
  <figure>
    <div
      class="flex items-end gap-px sm:gap-0.5"
      :style="{ height: `${height}px` }"
      role="img"
      :aria-label="summary"
      data-testid="bar-chart"
    >
      <div
        v-for="point in points"
        :key="point.label"
        class="flex h-full min-w-0 flex-1 items-end"
        :title="`${point.label}: ${point.text}`"
      >
        <div
          class="w-full rounded-t bg-accent/80 transition-colors hover:bg-accent"
          :style="{ height: `${percent(point.value)}%` }"
          data-testid="bar"
        />
      </div>
    </div>
    <div class="mt-1 flex gap-px text-[10px] text-muted sm:gap-0.5" aria-hidden="true">
      <span
        v-for="(point, index) in points"
        :key="point.label"
        class="min-w-0 flex-1 overflow-visible whitespace-nowrap"
      >
        {{ index % every === 0 ? point.label : '' }}
      </span>
    </div>
    <figcaption class="sr-only">{{ summary }}</figcaption>
  </figure>
</template>
