<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ADMIN_NAV } from '@/lib/adminNav'

const auth = useAuthStore()
const route = useRoute()
const open = ref(false)

// Only what this person is allowed to use. (A convenience: the API refuses everything else anyway.)
const groups = computed(() =>
  ADMIN_NAV.map((group) => ({ ...group, items: group.items.filter((item) => auth.can(item.permission)) })).filter(
    (group) => group.items.length,
  ),
)

watch(
  () => route.fullPath,
  () => (open.value = false),
)
</script>

<template>
  <div class="min-h-screen lg:grid lg:grid-cols-[15rem_1fr]">
    <a
      href="#admin-main"
      class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2"
    >
      Skip to content
    </a>

    <!-- Small screens: a bar with a button that opens the menu. -->
    <header
      class="sticky top-0 z-30 flex items-center justify-between border-b border-line bg-bg/95 px-4 py-3 backdrop-blur lg:hidden"
    >
      <RouterLink to="/admin" class="font-display text-lg font-semibold"
        >HighTech <span class="text-accent">Admin</span></RouterLink
      >
      <button
        type="button"
        class="rounded-lg border border-line px-3 py-1.5 text-sm"
        :aria-expanded="open"
        aria-controls="admin-nav"
        @click="open = !open"
      >
        {{ open ? 'Close' : 'Menu' }}
      </button>
    </header>

    <aside
      id="admin-nav"
      class="border-b border-line bg-surface lg:sticky lg:top-0 lg:block lg:h-screen lg:overflow-y-auto lg:border-b-0 lg:border-r"
      :class="open ? 'block' : 'hidden'"
    >
      <div class="hidden px-5 py-5 lg:block">
        <RouterLink to="/admin" class="font-display text-xl font-semibold tracking-tight">
          HighTech <span class="text-accent">Admin</span>
        </RouterLink>
        <p class="mt-1 truncate text-xs text-muted">{{ auth.user?.email }}</p>
      </div>

      <nav class="space-y-5 px-3 pb-6 pt-2 lg:pt-0" aria-label="Admin">
        <div v-for="group in groups" :key="group.title">
          <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-muted">{{ group.title }}</p>
          <ul class="space-y-0.5">
            <li v-for="item in group.items" :key="item.to">
              <RouterLink
                :to="item.to"
                class="block rounded-lg px-3 py-2 text-sm text-muted transition hover:bg-elevated hover:text-ink"
                active-class="!bg-accent-soft !text-accent font-medium"
              >
                {{ item.label }}
              </RouterLink>
            </li>
          </ul>
        </div>
        <RouterLink
          to="/"
          class="block rounded-lg px-3 py-2 text-sm text-muted transition hover:bg-elevated hover:text-ink"
        >
          ← Back to the shop
        </RouterLink>
      </nav>
    </aside>

    <main id="admin-main" class="min-w-0 px-4 py-6 sm:px-6 lg:px-8"><slot /></main>
  </div>
</template>
