<script setup>
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-12">
    <div
      class="pointer-events-none absolute -top-40 left-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-accent/20 blur-[120px]"
      aria-hidden="true"
    />
    <main class="relative w-full max-w-md">
      <RouterLink to="/" class="mb-8 block text-center font-display text-2xl font-semibold tracking-tight">
        PC Custom <span class="text-accent">Store</span>
      </RouterLink>
      <div class="rounded-card border border-line bg-surface p-6 shadow-2xl sm:p-8"><slot /></div>
    </main>
  </div>
</template>
