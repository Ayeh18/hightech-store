<script setup>
import { computed } from 'vue'

/** A ranked list with a bar behind each row: "what sold most". `rows` are [{ key, name, detail, value, text }]. */
const props = defineProps({
  rows: { type: Array, required: true },
  empty: { type: String, default: 'Nothing yet.' },
})

const max = computed(() => Math.max(1, ...props.rows.map((r) => r.value)))
// A small value still gets a sliver of bar; zero gets none.
const width = (value) => (value > 0 ? Math.max(2, (value / max.value) * 100) : 0)
</script>

<template>
  <p v-if="!rows.length" class="text-sm text-muted">{{ empty }}</p>
  <ol v-else class="space-y-3" data-testid="ranked-bars">
    <li v-for="row in rows" :key="row.key">
      <div class="flex items-baseline justify-between gap-3 text-sm">
        <span class="min-w-0 truncate font-medium">{{ row.name }}</span>
        <span class="shrink-0 tabular-nums">{{ row.text }}</span>
      </div>
      <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-elevated" aria-hidden="true">
        <div class="h-full rounded-full bg-accent/80" :style="{ width: `${width(row.value)}%` }" />
      </div>
      <p v-if="row.detail" class="mt-0.5 text-xs text-muted">{{ row.detail }}</p>
    </li>
  </ol>
</template>
