<script setup>
/** The lines of an order as the customer thinks of them: single products, and each PC with its parts together. */
defineProps({ lines: { type: Array, required: true } })
</script>

<template>
  <ul class="divide-y divide-line">
    <li v-for="(line, index) in lines" :key="index" class="py-3">
      <div v-if="line.kind === 'product'" class="flex items-start justify-between gap-4 text-sm">
        <div class="min-w-0">
          <p class="font-medium">{{ line.name }}</p>
          <p class="text-xs text-muted">SKU {{ line.sku }} · {{ line.quantity }} × {{ line.unit_price.formatted }}</p>
        </div>
        <p class="shrink-0 font-medium tabular-nums">{{ line.line_total.formatted }}</p>
      </div>

      <details v-else class="group">
        <summary class="flex cursor-pointer list-none items-start justify-between gap-4 text-sm">
          <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wide text-accent">
              Complete PC build<span v-if="line.copies > 1"> × {{ line.copies }}</span>
            </p>
            <p class="font-medium">{{ line.name }}</p>
            <p class="text-xs text-muted">
              {{ line.parts.length }} parts <span class="ml-1 transition group-open:hidden">▾ show</span
              ><span class="ml-1 hidden group-open:inline">▴ hide</span>
            </p>
          </div>
          <p class="shrink-0 font-medium tabular-nums">{{ line.line_total.formatted }}</p>
        </summary>
        <ul class="mt-3 space-y-2 rounded-xl border border-line bg-bg/60 p-3 text-sm">
          <li v-for="part in line.parts" :key="part.sku" class="flex justify-between gap-4">
            <span class="min-w-0">
              <span class="text-xs text-muted">{{ part.slot_label }}</span
              ><br />
              {{ part.name }}<span v-if="part.quantity > 1" class="text-muted"> ×{{ part.quantity }}</span>
            </span>
            <span class="shrink-0 tabular-nums">{{ part.line_total.formatted }}</span>
          </li>
        </ul>
      </details>
    </li>
  </ul>
</template>
