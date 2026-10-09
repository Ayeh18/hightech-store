<script setup>
import { onMounted, ref } from 'vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { api, parseApiError } from '@/lib/api'
import { firstError } from '@/lib/errors'

/** Brands: add, rename, remove. A brand that still has products cannot be removed (the server says so). */
const brands = ref([])
const loading = ref(true)
const error = ref('')
const notice = ref('')

const dialog = ref(false)
const editing = ref(null)
const name = ref('')
const fieldErrors = ref({})
const saving = ref(false)
const generalError = ref('')

const deleting = ref(null)
const deleteError = ref('')

async function load() {
  loading.value = true
  try {
    brands.value = (await api.get('/api/brands')).data.data
  } catch (e) {
    error.value = firstError(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

function open(brand = null) {
  editing.value = brand
  name.value = brand?.name ?? ''
  fieldErrors.value = {}
  generalError.value = ''
  dialog.value = true
}

async function save() {
  saving.value = true
  fieldErrors.value = {}
  generalError.value = ''
  try {
    if (editing.value) await api.put(`/api/brands/${editing.value.slug}`, { name: name.value.trim() })
    else await api.post('/api/brands', { name: name.value.trim() })
    notice.value = `${name.value.trim()} saved.`
    dialog.value = false
    await load()
  } catch (e) {
    const parsed = parseApiError(e)
    fieldErrors.value = parsed.errors
    generalError.value = Object.keys(parsed.errors).length ? '' : parsed.message
  } finally {
    saving.value = false
  }
}

async function destroy() {
  deleteError.value = ''
  try {
    await api.delete(`/api/brands/${deleting.value.slug}`)
    notice.value = `${deleting.value.name} deleted.`
    deleting.value = null
    await load()
  } catch (e) {
    deleteError.value = firstError(e)
  }
}
</script>

<template>
  <section aria-labelledby="brands-heading">
    <div class="mb-3 flex items-center justify-between">
      <h2 id="brands-heading" class="font-display text-lg font-semibold">Brands</h2>
      <BaseButton class="!px-4 !py-2" @click="open()">New brand</BaseButton>
    </div>
    <AlertMessage v-if="notice" type="success" class="mb-3">{{ notice }}</AlertMessage>
    <AlertMessage v-if="error" class="mb-3">{{ error }}</AlertMessage>
    <div
      v-if="loading"
      class="h-40 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading brands"
    />
    <div v-else class="relative overflow-x-auto rounded-card border border-line bg-surface">
      <table class="admin-table" data-testid="brands-table">
        <thead>
          <tr>
            <th>Brand</th>
            <th class="text-right">Products on sale</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!brands.length">
            <td colspan="3" class="text-muted">No brands yet.</td>
          </tr>
          <tr v-for="brand in brands" :key="brand.id">
            <td>
              {{ brand.name }}
              <p class="font-mono text-[11px] text-muted">{{ brand.slug }}</p>
            </td>
            <td class="text-right tabular-nums">{{ brand.products_count }}</td>
            <td class="whitespace-nowrap text-right">
              <button
                type="button"
                class="text-sm text-accent hover:underline"
                :aria-label="`Rename ${brand.name}`"
                @click="open(brand)"
              >
                Rename
              </button>
              <button
                type="button"
                class="ml-3 text-sm text-danger hover:underline"
                :aria-label="`Delete ${brand.name}`"
                @click="((deleting = brand), (deleteError = ''))"
              >
                Delete
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <BaseModal :open="dialog" :title="editing ? `Rename ${editing.name}` : 'New brand'" @close="dialog = false">
      <form id="brand-form" class="space-y-3 p-5 text-sm" novalidate @submit.prevent="save">
        <AlertMessage v-if="generalError">{{ generalError }}</AlertMessage>
        <BaseInput v-model="name" label="Name" required :error="fieldErrors.name?.[0] ?? fieldErrors.slug?.[0] ?? ''" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="dialog = false">Cancel</BaseButton>
          <BaseButton type="submit" form="brand-form" :loading="saving" :disabled="!name.trim()">Save</BaseButton>
        </div>
      </template>
    </BaseModal>

    <BaseModal :open="deleting !== null" title="Delete this brand?" @close="deleting = null">
      <div class="space-y-3 p-5 text-sm">
        <AlertMessage v-if="deleteError">{{ deleteError }}</AlertMessage>
        <p>
          <span class="font-semibold">{{ deleting?.name }}</span> will be removed. A brand that still has products
          cannot be deleted.
        </p>
      </div>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="deleting = null">Keep it</BaseButton>
          <BaseButton class="!bg-danger" @click="destroy">Delete</BaseButton>
        </div>
      </template>
    </BaseModal>
  </section>
</template>
