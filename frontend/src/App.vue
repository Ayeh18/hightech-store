<script setup>
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppLayout from '@/layouts/AppLayout.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

// Each route picks its layout in router/index.js via meta.layout (default: the main shell).
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const cart = useCartStore()
const layout = computed(() => (route.meta.layout === 'auth' ? AuthLayout : AppLayout))

// The cart follows the login: load it when a customer is signed in, and forget it the moment they are not,
// so one person's cart is never shown to the next person on the same browser.
watch(
  () => auth.isAuthenticated,
  (signedIn) => (signedIn ? cart.fetch() : cart.reset()),
  { immediate: true },
)

// If the server ends the session while the customer is on a members-only page, take them to the login page
// (and bring them back here afterwards). Public pages such as the builder simply show the "signed out" notice.
watch(
  () => auth.sessionEnded,
  (ended) => {
    if (ended && (route.meta.requiresAuth || route.meta.permission)) {
      router.push({ name: 'login', query: { redirect: route.fullPath } })
    }
  },
)
</script>

<template>
  <component :is="layout">
    <RouterView />
  </component>
</template>
