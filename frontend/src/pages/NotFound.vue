<script setup>
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
</script>

<template>
  <section class="mx-auto max-w-xl px-4 py-24 text-center">
    <p class="font-display text-6xl font-bold text-accent">404</p>
    <h1 class="mt-4 font-display text-2xl font-semibold">Page not found</h1>
    <p class="mt-2 text-muted">The page you are looking for does not exist or has moved.</p>
    <RouterLink to="/" class="mt-8 inline-block"><BaseButton>Back to home</BaseButton></RouterLink>
  </section>
</template>
