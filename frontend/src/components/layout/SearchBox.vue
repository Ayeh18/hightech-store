<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

/*
 * Header search. On the shop page it filters as you type (debounced); anywhere else it jumps to the
 * shop with the text. The URL is the source of truth, so the box always mirrors ?q=.
 */
const route = useRoute()
const router = useRouter()
const text = ref(typeof route.query.q === 'string' ? route.query.q : '')
let timer

watch(
  () => route.query.q,
  (q) => {
    const next = typeof q === 'string' ? q : ''
    if (next !== text.value.trim()) text.value = next
  },
)

function go() {
  clearTimeout(timer)
  const q = text.value.trim()
  const base = route.name === 'products' ? { ...route.query } : {}
  delete base.page
  if (q) base.q = q
  else {
    delete base.q
    if (base.sort === 'relevance') delete base.sort // relevance needs search text
  }
  router.push({ name: 'products', query: base })
}

function onInput() {
  clearTimeout(timer)
  // Only auto-search while already on the shop page; elsewhere wait for Enter.
  if (route.name === 'products') timer = setTimeout(go, 350)
}
</script>

<template>
  <form class="relative w-full" role="search" @submit.prevent="go">
    <label for="site-search" class="sr-only">Search products</label>
    <svg
      viewBox="0 0 24 24"
      class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-3.5-3.5" />
    </svg>
    <input
      id="site-search"
      v-model="text"
      type="search"
      maxlength="100"
      autocomplete="off"
      placeholder="Search CPUs, GPUs, SKU, brand…"
      class="w-full rounded-xl border border-line bg-surface py-2.5 pl-10 pr-4 text-sm text-ink placeholder:text-muted/70 focus:border-accent focus:outline-none"
      @input="onInput"
    />
  </form>
</template>
