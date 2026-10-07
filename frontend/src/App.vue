<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppLayout from '@/layouts/AppLayout.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'

// Each route picks its layout in router/index.js via meta.layout (default: the main shell).
const route = useRoute()
const layout = computed(() => (route.meta.layout === 'auth' ? AuthLayout : AppLayout))
</script>

<template>
  <component :is="layout">
    <RouterView />
  </component>
</template>
