<script setup>
import { computed } from 'vue'
import AdminPage from '@/components/admin/AdminPage.vue'
import BrandsPanel from '@/components/admin/BrandsPanel.vue'
import CategoriesPanel from '@/components/admin/CategoriesPanel.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const canBrands = computed(() => auth.can('brands.manage'))
const canCategories = computed(() => auth.can('categories.manage'))
</script>

<template>
  <AdminPage
    title="Brands & categories"
    subtitle="How the catalog is organised, and which specifications each kind of product has."
  >
    <div class="space-y-10">
      <CategoriesPanel v-if="canCategories" />
      <BrandsPanel v-if="canBrands" />
    </div>
  </AdminPage>
</template>
