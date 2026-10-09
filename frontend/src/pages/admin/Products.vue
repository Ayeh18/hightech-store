<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useAdminList } from '@/composables/useAdminList'
import { api } from '@/lib/api'

const list = useAdminList('/api/admin/products', { q: '', category: '', status: '' })
const categories = ref([])
const STATUSES = [
  { value: 'active', label: 'On sale' },
  { value: 'draft', label: 'Draft (hidden)' },
  { value: 'archived', label: 'Archived (hidden)' },
]

onMounted(async () => {
  try {
    categories.value = (await api.get('/api/categories')).data.data.map((c) => ({ value: c.slug, label: c.name }))
  } catch {
    /* the category filter just stays empty; the list still works */
  }
})

const statusTone = (status) =>
  ({ active: 'bg-success/15 text-success', draft: 'bg-warning/15 text-warning', archived: 'bg-line text-muted' })[
    status
  ]
const statusLabel = (status) => STATUSES.find((s) => s.value === status)?.label.split(' (')[0] ?? status
</script>

<template>
  <AdminPage title="Products" subtitle="Everything in the catalog, including drafts and archived products.">
    <template #actions
      ><RouterLink :to="{ name: 'admin-product-new' }"><BaseButton>New product</BaseButton></RouterLink></template
    >

    <form
      class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_14rem_14rem]"
      role="search"
      @submit.prevent="list.apply"
    >
      <BaseInput v-model="list.params.q" label="Search" placeholder="Name, SKU or brand" />
      <FormSelect v-model="list.params.category" label="Category" :options="categories" placeholder="All categories" />
      <FormSelect v-model="list.params.status" label="Status" :options="STATUSES" placeholder="Any status" />
    </form>

    <AlertMessage v-if="list.error.value" class="mb-4">{{ list.error.value }}</AlertMessage>
    <div
      v-if="!list.loaded.value && list.loading.value"
      class="h-64 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading products"
    />
    <EmptyState
      v-else-if="list.loaded.value && !list.rows.value.length"
      title="No products match"
      text="Try a different search or filter."
    />
    <div
      v-else
      class="overflow-x-auto rounded-card border border-line bg-surface"
      :class="{ 'opacity-60 transition': list.loading.value }"
    >
      <table class="admin-table" data-testid="products-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Category</th>
            <th class="text-right">Price</th>
            <th class="text-right">Can be sold</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="product in list.rows.value" :key="product.id">
            <td>
              <div class="flex items-center gap-3">
                <img
                  v-if="product.image"
                  :src="product.image"
                  alt=""
                  class="size-10 shrink-0 rounded-lg bg-bg object-contain"
                  loading="lazy"
                />
                <div v-else class="size-10 shrink-0 rounded-lg bg-elevated" aria-hidden="true" />
                <div class="min-w-0">
                  <RouterLink
                    :to="{ name: 'admin-product-edit', params: { id: product.id } }"
                    class="block truncate font-medium text-accent hover:underline"
                    >{{ product.name }}</RouterLink
                  >
                  <p class="text-xs text-muted">{{ product.sku }} · {{ product.brand.name }}</p>
                </div>
              </div>
            </td>
            <td class="text-muted">{{ product.category.name }}</td>
            <td class="text-right tabular-nums">
              {{ product.effective_price.formatted }}
              <p v-if="product.on_sale" class="text-xs text-muted line-through">{{ product.price.formatted }}</p>
            </td>
            <td
              class="text-right tabular-nums"
              :class="!product.in_stock ? 'font-semibold text-danger' : product.low_stock ? 'text-warning' : ''"
            >
              {{ product.inventory ? product.inventory.available : product.in_stock ? 'In stock' : 'Sold out' }}
            </td>
            <td>
              <span
                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                :class="statusTone(product.status)"
                >{{ statusLabel(product.status) }}</span
              >
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="list.loaded.value" class="mt-3 text-center text-xs text-muted">
      {{ list.meta.value.total }} product{{ list.meta.value.total === 1 ? '' : 's' }}
    </p>
    <div class="mt-4">
      <PaginationBar :page="list.meta.value.current_page" :last-page="list.meta.value.last_page" @change="list.load" />
    </div>
  </AdminPage>
</template>
