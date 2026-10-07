<script setup>
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

// Decorative only: these are slot names for the illustration, not product data.
const slots = ['CPU', 'Motherboard', 'RAM', 'GPU', 'Storage', 'PSU', 'Case', 'Cooler']

const features = [
  {
    title: 'Compatibility checked',
    text: 'Sockets, memory type, GPU length, cooler height and power draw are validated as you build.',
  },
  {
    title: 'Live, honest pricing',
    text: 'Your total is recalculated by the server from current prices, so what you see is what you pay.',
  },
  {
    title: 'Safe payments',
    text: 'Local payment methods with verified, duplicate-safe confirmations and full order tracking.',
  },
]
</script>

<template>
  <div>
    <section class="relative overflow-hidden">
      <div
        class="pointer-events-none absolute -right-32 -top-32 size-[34rem] rounded-full bg-accent/20 blur-[130px]"
        aria-hidden="true"
      />
      <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
        <div>
          <p
            class="mb-4 inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-xs font-medium text-muted"
          >
            <span class="size-1.5 rounded-full bg-success" aria-hidden="true" /> PC hardware store · Malaysia
          </p>
          <h1 class="font-display text-4xl font-bold leading-[1.1] tracking-tight sm:text-5xl lg:text-6xl">
            Build your dream PC.<br />
            <span class="bg-gradient-to-r from-accent to-[#38bdf8] bg-clip-text text-transparent">Zero guesswork.</span>
          </h1>
          <p class="mt-5 max-w-xl text-lg text-muted">
            Pick every component, watch the total update instantly, and let the compatibility engine catch mistakes
            before you check out.
          </p>
          <div class="mt-8 flex flex-wrap gap-3">
            <RouterLink v-if="!auth.isAuthenticated" to="/register"
              ><BaseButton class="px-6 py-3">Create account</BaseButton></RouterLink
            >
            <RouterLink v-if="!auth.isAuthenticated" to="/login"
              ><BaseButton variant="secondary" class="px-6 py-3">Log in</BaseButton></RouterLink
            >
            <p v-else class="text-sm text-muted">
              Welcome back, <span class="font-semibold text-ink">{{ auth.user.name }}</span
              >. The catalog and builder are coming next.
            </p>
          </div>
        </div>

        <div class="rounded-card border border-line bg-surface p-5 shadow-2xl" aria-hidden="true">
          <div class="mb-4 flex items-center justify-between">
            <span class="font-display text-sm font-semibold">Your build</span>
            <span class="rounded-full bg-success/15 px-2.5 py-1 text-xs font-medium text-success">✓ Compatible</span>
          </div>
          <ul class="space-y-2">
            <li
              v-for="slot in slots"
              :key="slot"
              class="flex items-center justify-between rounded-xl border border-line bg-elevated px-4 py-3 text-sm"
            >
              <span class="font-medium">{{ slot }}</span>
              <span class="rounded-lg bg-accent-soft px-2.5 py-1 text-xs font-medium text-accent">Choose</span>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 pb-20 sm:px-6">
      <div class="grid gap-4 md:grid-cols-3">
        <article
          v-for="feature in features"
          :key="feature.title"
          class="rounded-card border border-line bg-surface p-6"
        >
          <h2 class="font-display text-lg font-semibold">{{ feature.title }}</h2>
          <p class="mt-2 text-sm leading-relaxed text-muted">{{ feature.text }}</p>
        </article>
      </div>
    </section>
  </div>
</template>
