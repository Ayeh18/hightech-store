<script setup>
import { computed } from 'vue'

const props = defineProps({
  page: { type: Number, required: true },
  lastPage: { type: Number, required: true },
})
const emit = defineEmits(['change'])

// 1 … 4 5 [6] 7 8 … 20
const pages = computed(() => {
  const { page, lastPage } = props
  const wanted = new Set([1, lastPage, page - 1, page, page + 1])
  const sorted = [...wanted].filter((n) => n >= 1 && n <= lastPage).sort((a, b) => a - b)
  return sorted.flatMap((n, i) => (i > 0 && n - sorted[i - 1] > 1 ? ['gap', n] : [n]))
})
</script>

<template>
  <nav v-if="lastPage > 1" class="flex items-center justify-center gap-1.5" aria-label="Pagination">
    <button
      type="button"
      class="rounded-lg border border-line px-3 py-2 text-sm transition enabled:hover:border-accent/60 disabled:opacity-40"
      :disabled="page <= 1"
      @click="emit('change', page - 1)"
    >
      Previous
    </button>
    <template v-for="(item, index) in pages" :key="index">
      <span v-if="item === 'gap'" class="px-1 text-muted" aria-hidden="true">…</span>
      <button
        v-else
        type="button"
        class="min-w-10 rounded-lg border px-3 py-2 text-sm transition"
        :class="
          item === page
            ? 'border-accent bg-accent-soft font-semibold text-accent'
            : 'border-line hover:border-accent/60'
        "
        :aria-current="item === page ? 'page' : undefined"
        @click="emit('change', item)"
      >
        {{ item }}
      </button>
    </template>
    <button
      type="button"
      class="rounded-lg border border-line px-3 py-2 text-sm transition enabled:hover:border-accent/60 disabled:opacity-40"
      :disabled="page >= lastPage"
      @click="emit('change', page + 1)"
    >
      Next
    </button>
  </nav>
</template>
