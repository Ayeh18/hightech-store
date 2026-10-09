<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import FormTextarea from '@/components/admin/FormTextarea.vue'
import ProductImagesPanel from '@/components/admin/ProductImagesPanel.vue'
import SpecFields from '@/components/admin/SpecFields.vue'
import StockPanel from '@/components/admin/StockPanel.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { api, parseApiError } from '@/lib/api'
import { firstError } from '@/lib/errors'
import { parseRinggit, senToInput } from '@/lib/money'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const editing = computed(() => route.name === 'admin-product-edit')
const loading = ref(true)
const loadError = ref('')
const notFound = ref(false)
const saving = ref(false)
const saved = ref('')
const generalError = ref('')
const errors = ref({})

const categories = ref([])
const brands = ref([])
const product = ref(null)
const specDefinitions = ref([])
const specs = ref({})
const form = reactive({
  category: '',
  brand: '',
  sku: '',
  name: '',
  description: '',
  status: 'draft',
  price: '',
  discount_price: '',
  weight_grams: '',
  warranty_months: '',
  warranty_info: '',
  low_stock_threshold: '',
})

const STATUSES = [
  { value: 'draft', label: 'Draft: hidden from the shop' },
  { value: 'active', label: 'On sale' },
  { value: 'archived', label: 'Archived: hidden from the shop' },
]

const error = (field) => errors.value[field]?.[0] ?? ''
const specErrors = computed(() =>
  Object.fromEntries(Object.entries(errors.value).filter(([key]) => key.startsWith('specs'))),
)

/** Loads the spec boxes of a category, keeping what was already typed for specs both categories have. */
async function loadSpecDefinitions(slug, existing = {}) {
  if (!slug) {
    specDefinitions.value = []
    specs.value = {}
    return
  }
  const { data } = await api.get(`/api/categories/${slug}`)
  specDefinitions.value = data.data.spec_definitions ?? []
  specs.value = Object.fromEntries(
    specDefinitions.value.map((d) => [d.key, existing[d.key] ?? (d.type === 'list' ? [] : '')]),
  )
}

async function fill(p) {
  product.value = p
  Object.assign(form, {
    category: p.category.slug,
    brand: String(brands.value.find((b) => b.slug === p.brand.slug)?.id ?? ''),
    sku: p.sku,
    name: p.name,
    description: p.description ?? '',
    status: p.status,
    price: senToInput(p.price.amount),
    discount_price: p.discount_price ? senToInput(p.discount_price.amount) : '',
    weight_grams: p.weight_grams ?? '',
    warranty_months: p.warranty_months ?? '',
    warranty_info: p.warranty_info ?? '',
    low_stock_threshold: p.inventory?.low_stock_threshold ?? '',
  })
  const existing = Object.fromEntries(
    (p.specifications ?? []).map((s) => [s.key, Array.isArray(s.value) ? s.value : String(s.value)]),
  )
  await loadSpecDefinitions(p.category.slug, existing)
}

async function init() {
  loading.value = true
  loadError.value = ''
  notFound.value = false
  saved.value = route.query.created ? 'Product created. Add photos and check the stock below.' : ''
  try {
    const [c, b] = await Promise.all([api.get('/api/categories'), api.get('/api/brands')])
    categories.value = c.data.data
    brands.value = b.data.data
    if (editing.value) await fill((await api.get(`/api/products/${route.params.id}`)).data.data)
    else {
      product.value = null
      Object.assign(form, {
        category: '',
        brand: '',
        sku: '',
        name: '',
        description: '',
        status: 'draft',
        price: '',
        discount_price: '',
        weight_grams: '',
        warranty_months: '',
        warranty_info: '',
        low_stock_threshold: '',
      })
      specDefinitions.value = []
      specs.value = {}
    }
  } catch (e) {
    if (e.response?.status === 404) notFound.value = true
    else loadError.value = firstError(e)
  } finally {
    loading.value = false
  }
}

onMounted(init)
watch(
  () => route.fullPath,
  (now, before) => now !== before && init(),
)

watch(
  () => form.category,
  (slug, before) => {
    if (!loading.value && slug !== before)
      loadSpecDefinitions(slug, specs.value).catch((e) => (generalError.value = firstError(e)))
  },
)

/** Turns the boxes into what the API wants (money in sen, numbers as numbers), or lists what is wrong with them. */
function buildPayload() {
  const problems = {}
  const wholeNumber = (field, text, label) => {
    const value = String(text ?? '').trim()
    if (value === '') return null
    if (!/^\d+$/.test(value)) problems[field] = [`${label} must be a whole number.`]
    return Number(value)
  }

  const price = parseRinggit(form.price)
  if (price === null || price < 1) problems.price = ['Enter a price in ringgit, for example 1599.00']

  let discount = null
  if (form.discount_price.trim() !== '') {
    discount = parseRinggit(form.discount_price)
    if (discount === null || discount < 1) problems.discount_price = ['Enter a price in ringgit, for example 1499.00']
    else if (price !== null && discount >= price)
      problems.discount_price = ['The discount price must be lower than the regular price.']
  }

  const sent = {}
  for (const definition of specDefinitions.value) {
    const value = specs.value[definition.key]
    if (definition.type === 'list') {
      if (value?.length) sent[definition.key] = value
    } else if (String(value ?? '').trim() !== '') {
      if (definition.type === 'number') {
        if (!/^-?\d+(\.\d+)?$/.test(String(value).trim()))
          problems[`specs.${definition.key}`] = [`${definition.label} must be a number.`]
        else sent[definition.key] = Number(value)
      } else sent[definition.key] = String(value).trim()
    }
  }

  const payload = {
    category_id: categories.value.find((c) => c.slug === form.category)?.id,
    brand_id: Number(form.brand) || undefined,
    sku: form.sku.trim(),
    name: form.name.trim(),
    description: form.description.trim() || null,
    status: form.status,
    price,
    discount_price: discount,
    weight_grams: wholeNumber('weight_grams', form.weight_grams, 'Weight'),
    warranty_months: wholeNumber('warranty_months', form.warranty_months, 'Warranty'),
    warranty_info: form.warranty_info.trim() || null,
    specs: sent,
  }
  const threshold = wholeNumber('low_stock_threshold', form.low_stock_threshold, 'The warning level')
  if (threshold !== null) payload.low_stock_threshold = threshold

  errors.value = problems
  return Object.keys(problems).length ? null : payload
}

async function save() {
  generalError.value = ''
  saved.value = ''
  const payload = buildPayload()
  if (!payload) return

  saving.value = true
  try {
    if (editing.value) {
      const { data } = await api.put(`/api/products/${product.value.id}`, payload)
      await fill(data.data)
      saved.value = 'Saved.'
    } else {
      const { data } = await api.post('/api/products', payload)
      router.push({ name: 'admin-product-edit', params: { id: data.data.id }, query: { created: '1' } })
    }
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.errors
    generalError.value = Object.keys(parsed.errors).length ? 'Please fix the problems marked below.' : parsed.message
  } finally {
    saving.value = false
  }
}

async function reloadProduct() {
  await fill((await api.get(`/api/products/${product.value.id}`)).data.data)
}

// ---------- delete
const confirmingDelete = ref(false)
const deleting = ref(false)
const deleteError = ref('')

async function destroy() {
  deleting.value = true
  deleteError.value = ''
  try {
    await api.delete(`/api/products/${product.value.id}`)
    router.push({ name: 'admin-products' })
  } catch (e) {
    deleteError.value = firstError(e)
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <AdminPage :title="editing ? (product?.name ?? 'Product') : 'New product'">
    <template #actions
      ><RouterLink :to="{ name: 'admin-products' }" class="text-sm text-muted hover:text-ink"
        >← All products</RouterLink
      ></template
    >

    <div
      v-if="loading && !product && editing"
      class="h-72 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading the product"
    />
    <EmptyState v-else-if="notFound" title="Product not found" text="There is no product with that id." />
    <div v-else-if="loadError" role="alert">
      <AlertMessage>{{ loadError }}</AlertMessage
      ><BaseButton class="mt-3" variant="secondary" @click="init">Try again</BaseButton>
    </div>

    <div v-else class="space-y-4">
      <AlertMessage v-if="saved" type="success">{{ saved }}</AlertMessage>
      <AlertMessage v-if="generalError">{{ generalError }}</AlertMessage>

      <form class="space-y-4" novalidate @submit.prevent="save">
        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="basics-heading">
          <h2 id="basics-heading" class="mb-4 font-display text-lg font-semibold">Basics</h2>
          <div class="grid gap-4 sm:grid-cols-2">
            <FormSelect
              v-model="form.category"
              label="Category"
              :options="categories.map((c) => ({ value: c.slug, label: c.name }))"
              placeholder="Choose a category"
              :error="error('category_id')"
            />
            <FormSelect
              v-model="form.brand"
              label="Brand"
              :options="brands.map((b) => ({ value: String(b.id), label: b.name }))"
              placeholder="Choose a brand"
              :error="error('brand_id')"
            />
            <div class="sm:col-span-2">
              <BaseInput v-model="form.name" label="Name" required :error="error('name')" />
            </div>
            <BaseInput
              v-model="form.sku"
              label="SKU (the code you use for it)"
              required
              placeholder="GPU-NV-5090"
              :error="error('sku')"
            />
            <FormSelect v-model="form.status" label="Status" :options="STATUSES" :error="error('status')" />
            <div class="sm:col-span-2">
              <FormTextarea
                v-model="form.description"
                label="Description"
                :rows="4"
                :maxlength="10000"
                :error="error('description')"
              />
            </div>
          </div>
        </section>

        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="price-heading">
          <h2 id="price-heading" class="mb-4 font-display text-lg font-semibold">Price</h2>
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput v-model="form.price" label="Price (RM)" required placeholder="1599.00" :error="error('price')" />
            <BaseInput
              v-model="form.discount_price"
              label="Sale price (RM), optional"
              placeholder="1499.00"
              :error="error('discount_price')"
            />
          </div>
          <p class="mt-2 text-xs text-muted">
            Customers always pay the price the server holds at checkout, so changing it here is safe for carts that are
            already filled.
          </p>
        </section>

        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="specs-heading">
          <h2 id="specs-heading" class="mb-1 font-display text-lg font-semibold">Specifications</h2>
          <p class="mb-4 text-xs text-muted">
            These boxes come from the category. The shop's filters and the PC builder's compatibility checks use them,
            so fill in every one that applies.
          </p>
          <p v-if="!form.category" class="text-sm text-muted">Choose a category first.</p>
          <p v-else-if="!specDefinitions.length" class="text-sm text-muted">This category has no specifications.</p>
          <SpecFields v-else v-model="specs" :definitions="specDefinitions" :errors="specErrors" />
          <p v-if="error('specs')" class="mt-2 text-xs text-danger" role="alert">{{ error('specs') }}</p>
        </section>

        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="extra-heading">
          <h2 id="extra-heading" class="mb-4 font-display text-lg font-semibold">Weight, warranty and stock alert</h2>
          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <BaseInput v-model="form.weight_grams" label="Weight (grams)" :error="error('weight_grams')" />
            <BaseInput v-model="form.warranty_months" label="Warranty (months)" :error="error('warranty_months')" />
            <BaseInput v-model="form.warranty_info" label="Warranty note" :error="error('warranty_info')" />
            <BaseInput
              v-model="form.low_stock_threshold"
              label="Warn when units left"
              :error="error('low_stock_threshold')"
            />
          </div>
        </section>

        <div class="flex items-center gap-3">
          <BaseButton type="submit" :loading="saving">{{ editing ? 'Save changes' : 'Create product' }}</BaseButton>
          <RouterLink :to="{ name: 'admin-products' }" class="text-sm text-muted hover:text-ink">Cancel</RouterLink>
        </div>
      </form>

      <template v-if="editing && product">
        <ProductImagesPanel :product-id="product.id" :images="product.images" @changed="reloadProduct" />
        <StockPanel
          v-if="product.inventory && auth.can('inventory.manage')"
          :product-id="product.id"
          :inventory="product.inventory"
          @changed="reloadProduct"
        />

        <section class="rounded-card border border-danger/40 bg-surface p-5" aria-labelledby="danger-heading">
          <h2 id="danger-heading" class="font-display text-lg font-semibold text-danger">Delete this product</h2>
          <p class="mt-1 text-sm text-muted">
            It disappears from the shop and from customers' carts. Past orders keep their own copy of what was bought.
            To only hide it for a while, set the status to Draft or Archived instead.
          </p>
          <BaseButton class="mt-3 !bg-danger" @click="confirmingDelete = true">Delete…</BaseButton>
        </section>

        <BaseModal :open="confirmingDelete" title="Delete this product?" @close="confirmingDelete = false">
          <div class="space-y-3 p-5 text-sm">
            <AlertMessage v-if="deleteError">{{ deleteError }}</AlertMessage>
            <p>
              <span class="font-semibold">{{ product.name }}</span> will be removed from the shop.
            </p>
          </div>
          <template #footer>
            <div class="flex justify-end gap-3">
              <BaseButton variant="ghost" @click="confirmingDelete = false">Keep it</BaseButton>
              <BaseButton class="!bg-danger" :loading="deleting" @click="destroy">Delete product</BaseButton>
            </div>
          </template>
        </BaseModal>
      </template>
    </div>
  </AdminPage>
</template>
