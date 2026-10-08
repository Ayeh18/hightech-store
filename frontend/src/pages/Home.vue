<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import ProductGrid from '@/components/products/ProductGrid.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, parseApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useProductStore } from '@/stores/products'

const auth = useAuthStore()
const store = useProductStore()

// Popular products are fetched separately from the shop page's list so the two never overwrite each other.
const popular = ref([])
const popularLoading = ref(true)
const popularError = ref('')

async function loadPopular() {
  popularLoading.value = true
  popularError.value = ''
  try {
    const { data } = await api.get('/api/products', { params: { sort: 'popular', per_page: 8, in_stock: 1 } })
    popular.value = data.data
  } catch (e) {
    popularError.value = parseApiError(e).message
  } finally {
    popularLoading.value = false
  }
}

onMounted(() => {
  store.fetchCategories().catch(() => null)
  loadPopular()
})
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
            <span class="size-1.5 rounded-full bg-success" aria-hidden="true" /> HighTech Store · Malaysia
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
            <RouterLink :to="{ name: 'builder' }"><BaseButton class="px-6 py-3">Start building</BaseButton></RouterLink>
            <RouterLink :to="{ name: 'products' }"
              ><BaseButton variant="secondary" class="px-6 py-3">Browse parts</BaseButton></RouterLink
            >
          </div>
          <p v-if="!auth.isAuthenticated" class="mt-5 text-sm text-muted">
            <RouterLink to="/register" class="font-medium text-accent hover:underline">Create an account</RouterLink>
            to save your builds ·
            <RouterLink to="/login" class="font-medium text-accent hover:underline">Log in</RouterLink>
          </p>
          <p v-else class="mt-5 text-sm text-muted">
            Welcome back, <span class="font-semibold text-ink">{{ auth.user.name }}</span
            >.
            <RouterLink :to="{ name: 'my-builds' }" class="font-medium text-accent hover:underline"
              >My builds</RouterLink
            >
          </p>
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

    <section
      v-if="store.categories.length"
      class="mx-auto max-w-7xl px-4 pb-14 sm:px-6"
      aria-labelledby="categories-heading"
    >
      <h2 id="categories-heading" class="font-display text-2xl font-semibold tracking-tight">Shop by category</h2>
      <ul class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <li v-for="category in store.categories" :key="category.slug">
          <RouterLink
            :to="{ name: 'products', query: { category: category.slug } }"
            class="flex h-full flex-col justify-between rounded-card border border-line bg-surface p-4 transition hover:-translate-y-0.5 hover:border-accent/50"
          >
            <span class="font-medium">{{ category.name }}</span>
            <span class="mt-4 text-xs text-muted">{{ category.products_count }} products</span>
          </RouterLink>
        </li>
      </ul>
    </section>

    <section
      v-if="popularLoading || popularError || popular.length"
      class="mx-auto max-w-7xl px-4 pb-14 sm:px-6"
      aria-labelledby="popular-heading"
    >
      <div class="mb-5 flex items-end justify-between gap-4">
        <h2 id="popular-heading" class="font-display text-2xl font-semibold tracking-tight">Popular right now</h2>
        <RouterLink :to="{ name: 'products' }" class="text-sm font-medium text-accent hover:underline"
          >View all</RouterLink
        >
      </div>
      <ProductGrid :products="popular" :loading="popularLoading" :error="popularError" @retry="loadPopular" />
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
