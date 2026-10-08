<script setup>
import { onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import PriceTag from '@/components/products/PriceTag.vue'
import ProductImage from '@/components/products/ProductImage.vue'
import AddToCartForm from '@/components/cart/AddToCartForm.vue'
import StockBadge from '@/components/products/StockBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { parseApiError } from '@/lib/api'
import { useProductStore } from '@/stores/products'

const store = useProductStore()
const route = useRoute()

const product = ref(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const activeImage = ref(0)

async function load() {
  loading.value = true
  notFound.value = false
  error.value = ''
  activeImage.value = 0
  try {
    product.value = await store.fetchProduct(route.params.slug)
    document.title = `${product.value.name} · HighTech Store`
  } catch (e) {
    product.value = null
    if (e.response?.status === 404) notFound.value = true
    else error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => route.params.slug, load)
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <div v-if="loading" class="grid animate-pulse gap-8 lg:grid-cols-2" aria-busy="true" aria-label="Loading product">
      <div class="aspect-square rounded-card bg-surface" />
      <div class="space-y-4">
        <div class="h-6 w-1/3 rounded bg-surface" />
        <div class="h-10 w-3/4 rounded bg-surface" />
        <div class="h-24 rounded bg-surface" />
      </div>
    </div>

    <EmptyState
      v-else-if="notFound"
      title="Product not found"
      text="It may have been removed or is no longer available."
    >
      <RouterLink :to="{ name: 'products' }"><BaseButton>Browse all products</BaseButton></RouterLink>
    </EmptyState>

    <div v-else-if="error" role="alert">
      <EmptyState title="We couldn't load this product" :text="error"
        ><BaseButton @click="load">Try again</BaseButton></EmptyState
      >
    </div>

    <article v-else-if="product">
      <nav class="mb-6 text-sm text-muted" aria-label="Breadcrumb">
        <RouterLink :to="{ name: 'products' }" class="hover:text-ink">Shop</RouterLink>
        <span class="mx-2" aria-hidden="true">/</span>
        <RouterLink :to="{ name: 'products', query: { category: product.category.slug } }" class="hover:text-ink">{{
          product.category.name
        }}</RouterLink>
      </nav>

      <div class="grid gap-10 lg:grid-cols-2">
        <div>
          <div class="aspect-square overflow-hidden rounded-card border border-line bg-elevated">
            <ProductImage
              :src="product.images[activeImage]?.url"
              :alt="product.images[activeImage]?.alt ?? product.name"
              :brand="product.brand.name"
            />
          </div>
          <ul v-if="product.images.length > 1" class="mt-3 flex gap-2">
            <li v-for="(image, index) in product.images" :key="image.id">
              <button
                type="button"
                class="size-16 overflow-hidden rounded-lg border bg-elevated transition"
                :class="index === activeImage ? 'border-accent' : 'border-line hover:border-accent/50'"
                :aria-label="`Show image ${index + 1}`"
                @click="activeImage = index"
              >
                <img :src="image.url" :alt="image.alt" class="size-full object-cover" loading="lazy" />
              </button>
            </li>
          </ul>
        </div>

        <div>
          <p class="text-sm font-medium uppercase tracking-wide text-accent">{{ product.brand.name }}</p>
          <h1 class="mt-1 font-display text-3xl font-semibold leading-tight tracking-tight">{{ product.name }}</h1>
          <p class="mt-2 text-xs text-muted">SKU {{ product.sku }}</p>

          <div class="mt-6 space-y-2">
            <PriceTag :price="product.price" :effective="product.effective_price" size="lg" />
            <StockBadge :in-stock="product.in_stock" :low-stock="product.low_stock" />
          </div>

          <AddToCartForm :product="product" />

          <dl class="mt-6 grid grid-cols-2 gap-4 rounded-card border border-line bg-surface p-4 text-sm">
            <div>
              <dt class="text-muted">Warranty</dt>
              <dd class="mt-0.5 font-medium">{{ product.warranty_info ?? 'Not specified' }}</dd>
            </div>
            <div v-if="product.weight_grams">
              <dt class="text-muted">Weight</dt>
              <dd class="mt-0.5 font-medium">{{ (product.weight_grams / 1000).toFixed(2) }} kg</dd>
            </div>
          </dl>

          <p v-if="product.description" class="mt-6 leading-relaxed text-muted">{{ product.description }}</p>
        </div>
      </div>

      <section class="mt-12" aria-labelledby="specs-heading">
        <h2 id="specs-heading" class="font-display text-xl font-semibold">Specifications</h2>
        <dl class="mt-4 divide-y divide-line overflow-hidden rounded-card border border-line bg-surface">
          <div v-for="spec in product.specifications" :key="spec.key" class="grid grid-cols-3 gap-4 px-5 py-3 text-sm">
            <dt class="text-muted">{{ spec.label }}</dt>
            <dd class="col-span-2 font-medium">{{ spec.display }}</dd>
          </div>
        </dl>
      </section>
    </article>
  </div>
</template>
