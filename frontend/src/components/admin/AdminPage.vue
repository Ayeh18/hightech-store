<script setup>
/** The title block every admin page starts with, with room for buttons on the right. */
defineProps({ title: { type: String, required: true }, subtitle: { type: String, default: '' } })
</script>

<template>
  <section>
    <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-0">
        <h1 class="font-display text-2xl font-semibold tracking-tight sm:text-3xl">{{ title }}</h1>
        <p v-if="subtitle" class="mt-1 text-sm text-muted">{{ subtitle }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </header>
    <slot />
  </section>
</template>
