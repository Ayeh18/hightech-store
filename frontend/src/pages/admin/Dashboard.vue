<script setup>
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6">
    <h1 class="font-display text-3xl font-semibold">Admin</h1>
    <p class="mt-2 text-muted">
      Signed in as <span class="text-ink">{{ auth.user?.email }}</span> ({{ auth.roles.join(', ') }}). The dashboard,
      products and orders arrive in later phases.
    </p>
    <div class="mt-6 flex flex-wrap gap-2">
      <span
        v-for="permission in auth.permissions"
        :key="permission"
        class="rounded-lg border border-line bg-surface px-3 py-1 text-xs text-muted"
      >
        {{ permission }}
      </span>
    </div>
  </section>
</template>
