<script setup>
import AppFooter from '@/components/layout/AppFooter.vue'
import AppHeader from '@/components/layout/AppHeader.vue'
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <a
      href="#main"
      class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2"
    >
      Skip to content
    </a>
    <AppHeader />
    <main id="main" class="flex-1"><slot /></main>
    <AppFooter />
  </div>
</template>
