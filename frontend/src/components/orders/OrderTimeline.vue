<script setup>
/**
 * The order's story, oldest first: placed, paid, packed, shipped... `entries` come from the server
 * ([{ status: { value, label }, at }]); the last one is where the order is now.
 */
defineProps({ entries: { type: Array, required: true } })

const when = (iso) =>
  new Intl.DateTimeFormat('en-MY', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
</script>

<template>
  <ol class="relative space-y-4 border-l border-line pl-6" aria-label="Order progress" data-testid="timeline">
    <li v-for="(entry, index) in entries" :key="`${entry.status.value}-${entry.at}`" class="relative">
      <span
        class="absolute -left-[1.85rem] top-1 size-3 rounded-full border-2"
        :class="index === entries.length - 1 ? 'border-accent bg-accent' : 'border-line bg-surface'"
        aria-hidden="true"
      />
      <p class="text-sm font-medium" :class="index === entries.length - 1 ? 'text-ink' : 'text-muted'">
        {{ entry.status.label }}
        <span v-if="index === entries.length - 1" class="sr-only">(current)</span>
      </p>
      <p class="text-xs text-muted">{{ when(entry.at) }}</p>
    </li>
  </ol>
</template>
