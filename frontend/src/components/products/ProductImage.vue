<script setup>
defineProps({
  src: { type: String, default: null },
  alt: { type: String, default: '' },
  brand: { type: String, default: '' },
})
</script>

<template>
  <img v-if="src" :src="src" :alt="alt" loading="lazy" class="size-full object-contain" />
  <div
    v-else
    class="flex size-full flex-col items-center justify-center gap-2 bg-gradient-to-br from-elevated to-surface"
    role="img"
    :aria-label="`${alt} (no photo yet)`"
  >
    <svg
      viewBox="0 0 24 24"
      class="size-12 text-accent/70"
      fill="none"
      stroke="currentColor"
      stroke-width="1.4"
      aria-hidden="true"
    >
      <rect x="6" y="6" width="12" height="12" rx="1.5" />
      <rect x="9.5" y="9.5" width="5" height="5" rx="0.5" />
      <path d="M9 3v3M12 3v3M15 3v3M9 18v3M12 18v3M15 18v3M3 9h3M3 12h3M3 15h3M18 9h3M18 12h3M18 15h3" />
    </svg>
    <span v-if="brand" class="text-xs font-semibold uppercase tracking-widest text-muted">{{ brand }}</span>
  </div>
</template>
