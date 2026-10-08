<script setup>
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/** Shown when the server ended the customer's session, so they are never logged out without being told. */
const auth = useAuthStore()
const route = useRoute()
</script>

<template>
  <div v-if="auth.sessionEnded" role="alert" class="border-b border-warning/40 bg-warning/10">
    <div
      class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-3 text-sm sm:px-6"
    >
      <p class="text-ink">
        <span class="font-semibold text-warning">You were signed out.</span>
        Your session ended, so please log in again. Anything you were building is still saved in this browser.
      </p>
      <div class="flex items-center gap-3">
        <RouterLink
          :to="{ name: 'login', query: { redirect: route.fullPath } }"
          class="rounded-lg bg-accent px-3.5 py-1.5 font-semibold text-white transition hover:bg-accent-hover"
        >
          Log in
        </RouterLink>
        <button type="button" class="text-muted transition hover:text-ink" @click="auth.dismissSessionNotice()">
          Dismiss
        </button>
      </div>
    </div>
  </div>
</template>
