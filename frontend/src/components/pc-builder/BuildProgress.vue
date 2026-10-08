<script setup>
import { computed } from 'vue'

const props = defineProps({
  filled: { type: Number, required: true },
  total: { type: Number, required: true },
})

const percent = computed(() => (props.total ? Math.round((props.filled / props.total) * 100) : 0))
</script>

<template>
  <div>
    <div class="mb-1.5 flex items-center justify-between text-xs">
      <span class="font-medium text-ink">{{ filled }} of {{ total }} required parts</span>
      <span class="text-muted">{{ percent }}%</span>
    </div>
    <div
      class="h-2 overflow-hidden rounded-full bg-elevated"
      role="progressbar"
      :aria-valuenow="filled"
      aria-valuemin="0"
      :aria-valuemax="total"
      aria-label="Build progress"
    >
      <div
        class="h-full rounded-full bg-gradient-to-r from-accent to-[#38bdf8] transition-all duration-300"
        :style="{ width: `${percent}%` }"
      />
    </div>
  </div>
</template>
