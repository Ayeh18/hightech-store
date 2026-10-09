<script setup>
import { onMounted, reactive, ref } from 'vue'
import FormCheckbox from '@/components/admin/FormCheckbox.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import FormTextarea from '@/components/admin/FormTextarea.vue'
import SpecDefinitionsDialog from '@/components/admin/SpecDefinitionsDialog.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { api, parseApiError } from '@/lib/api'
import { firstError } from '@/lib/errors'

/** Categories (Processors, Graphics cards...), including hidden ones, and each one's specification boxes. */
const props = defineProps({ canEdit: { type: Boolean, default: true } })

const categories = ref([])
const slots = ref([])
const loading = ref(true)
const error = ref('')
const notice = ref('')

const dialog = ref(false)
const editing = ref(null)
const form = reactive({ name: '', description: '', builder_slot: '', sort_order: '0', is_active: true })
const errors = ref({})
const generalError = ref('')
const saving = ref(false)

const deleting = ref(null)
const deleteError = ref('')
const specsFor = ref(null)

async function load() {
  loading.value = true
  try {
    const [c, s] = await Promise.all([api.get('/api/categories'), api.get('/api/pc-builder/components')])
    categories.value = c.data.data
    slots.value = s.data.data.map((slot) => ({ value: slot.slot, label: slot.label }))
  } catch (e) {
    error.value = firstError(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

function open(category = null) {
  editing.value = category
  Object.assign(form, {
    name: category?.name ?? '',
    description: category?.description ?? '',
    builder_slot: category?.builder_slot ?? '',
    sort_order: String(category?.sort_order ?? 0),
    is_active: category?.is_active ?? true,
  })
  errors.value = {}
  generalError.value = ''
  dialog.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  generalError.value = ''
  const body = {
    name: form.name.trim(),
    description: form.description.trim() || null,
    builder_slot: form.builder_slot || null,
    sort_order: Number(form.sort_order) || 0,
    is_active: form.is_active,
  }
  try {
    if (editing.value) await api.put(`/api/categories/${editing.value.slug}`, body)
    else await api.post('/api/categories', body)
    notice.value = `${body.name} saved.`
    dialog.value = false
    await load()
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.errors
    generalError.value = Object.keys(parsed.errors).length ? '' : parsed.message
  } finally {
    saving.value = false
  }
}

async function destroy() {
  deleteError.value = ''
  try {
    await api.delete(`/api/categories/${deleting.value.slug}`)
    notice.value = `${deleting.value.name} deleted.`
    deleting.value = null
    await load()
  } catch (e) {
    deleteError.value = firstError(e)
  }
}

const err = (field) => errors.value[field]?.[0] ?? ''
</script>

<template>
  <section aria-labelledby="categories-heading">
    <div class="mb-3 flex items-center justify-between">
      <h2 id="categories-heading" class="font-display text-lg font-semibold">Categories</h2>
      <BaseButton v-if="props.canEdit" class="!px-4 !py-2" @click="open()">New category</BaseButton>
    </div>
    <AlertMessage v-if="notice" type="success" class="mb-3">{{ notice }}</AlertMessage>
    <AlertMessage v-if="error" class="mb-3">{{ error }}</AlertMessage>
    <div
      v-if="loading"
      class="h-40 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading categories"
    />
    <div v-else class="relative overflow-x-auto rounded-card border border-line bg-surface">
      <table class="admin-table" data-testid="categories-table">
        <thead>
          <tr>
            <th>Category</th>
            <th>PC builder slot</th>
            <th class="text-right">Products on sale</th>
            <th>Shown</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!categories.length">
            <td colspan="5" class="text-muted">No categories yet.</td>
          </tr>
          <tr v-for="category in categories" :key="category.id">
            <td>
              {{ category.name }}
              <p class="font-mono text-[11px] text-muted">{{ category.slug }}</p>
            </td>
            <td class="text-muted">{{ slots.find((s) => s.value === category.builder_slot)?.label ?? '—' }}</td>
            <td class="text-right tabular-nums">{{ category.products_count }}</td>
            <td>
              <span :class="category.is_active ? 'text-success' : 'text-muted'">{{
                category.is_active ? 'Yes' : 'Hidden'
              }}</span>
            </td>
            <td class="whitespace-nowrap text-right">
              <button
                type="button"
                class="text-sm text-accent hover:underline"
                :aria-label="`Specifications of ${category.name}`"
                @click="specsFor = category"
              >
                Specs
              </button>
              <template v-if="props.canEdit">
                <button
                  type="button"
                  class="ml-3 text-sm text-accent hover:underline"
                  :aria-label="`Edit ${category.name}`"
                  @click="open(category)"
                >
                  Edit
                </button>
                <button
                  type="button"
                  class="ml-3 text-sm text-danger hover:underline"
                  :aria-label="`Delete ${category.name}`"
                  @click="((deleting = category), (deleteError = ''))"
                >
                  Delete
                </button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <SpecDefinitionsDialog :category="specsFor" @close="specsFor = null" />

    <BaseModal
      :open="dialog"
      :title="editing ? `Edit ${editing.name}` : 'New category'"
      size="lg"
      @close="dialog = false"
    >
      <form id="category-form" class="grid gap-4 p-5 text-sm sm:grid-cols-2" novalidate @submit.prevent="save">
        <AlertMessage v-if="generalError" class="sm:col-span-2">{{ generalError }}</AlertMessage>
        <BaseInput v-model="form.name" label="Name" required :error="err('name') || err('slug')" />
        <FormSelect
          v-model="form.builder_slot"
          label="Slot in the PC builder"
          :options="slots"
          placeholder="None (not a PC part)"
          :error="err('builder_slot')"
        />
        <div class="sm:col-span-2">
          <FormTextarea
            v-model="form.description"
            label="Description"
            :rows="2"
            :maxlength="2000"
            :error="err('description')"
          />
        </div>
        <BaseInput v-model="form.sort_order" label="Order in the menu" :error="err('sort_order')" />
        <div class="self-end"><FormCheckbox v-model="form.is_active" label="Shown in the shop" /></div>
      </form>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="dialog = false">Cancel</BaseButton>
          <BaseButton type="submit" form="category-form" :loading="saving" :disabled="!form.name.trim()"
            >Save</BaseButton
          >
        </div>
      </template>
    </BaseModal>

    <BaseModal :open="deleting !== null" title="Delete this category?" @close="deleting = null">
      <div class="space-y-3 p-5 text-sm">
        <AlertMessage v-if="deleteError">{{ deleteError }}</AlertMessage>
        <p>
          <span class="font-semibold">{{ deleting?.name }}</span> and its specifications will be removed. A category
          that still has products cannot be deleted.
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
