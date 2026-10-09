<script setup>
import { reactive, ref, watch } from 'vue'
import FormCheckbox from '@/components/admin/FormCheckbox.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import FormTextarea from '@/components/admin/FormTextarea.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { api, parseApiError } from '@/lib/api'
import { firstError } from '@/lib/errors'

/**
 * The specification boxes of one category (socket, memory type, wattage...). Products, the shop's filters and the PC builder's
 * compatibility checks are all driven by these, so a new kind of spec needs no code. A spec's key and type cannot change once
 * created, because products already hold values under them.
 */
const props = defineProps({ category: { type: Object, default: null } })
const emit = defineEmits(['close'])

const TYPES = [
  { value: 'string', label: 'Text' },
  { value: 'number', label: 'Number' },
  { value: 'enum', label: 'One of a list' },
  { value: 'list', label: 'Several of a list' },
]
const definitions = ref([])
const loading = ref(false)
const error = ref('')
const editing = ref(null) // an existing definition, 'new', or null
const errors = ref({})
const saving = ref(false)
const confirmDelete = ref(null)

const blank = () => ({
  key: '',
  label: '',
  type: 'string',
  unit: '',
  options: '',
  is_required: false,
  is_filterable: false,
  show_on_card: false,
  sort_order: '0',
})
const form = reactive(blank())
const hasOptions = () => ['enum', 'list'].includes(form.type)

async function load() {
  loading.value = true
  error.value = ''
  try {
    definitions.value = (await api.get(`/api/categories/${props.category.slug}`)).data.data.spec_definitions ?? []
  } catch (e) {
    error.value = firstError(e)
  } finally {
    loading.value = false
  }
}

watch(
  () => props.category,
  (category) => {
    editing.value = null
    confirmDelete.value = null
    if (category) load()
  },
)

function edit(definition) {
  editing.value = definition
  errors.value = {}
  Object.assign(form, {
    key: definition.key,
    label: definition.label,
    type: definition.type,
    unit: definition.unit ?? '',
    options: (definition.options ?? []).join('\n'),
    is_required: definition.is_required,
    is_filterable: definition.is_filterable,
    show_on_card: definition.show_on_card,
    sort_order: String(definition.sort_order),
  })
}

function add() {
  editing.value = 'new'
  errors.value = {}
  Object.assign(form, blank())
}

async function save() {
  saving.value = true
  errors.value = {}
  const options = form.options
    .split('\n')
    .map((o) => o.trim())
    .filter(Boolean)
  const body = {
    label: form.label.trim(),
    unit: form.unit.trim() || null,
    options: hasOptions() ? options : null,
    is_required: form.is_required,
    is_filterable: form.is_filterable,
    show_on_card: form.show_on_card,
    sort_order: Number(form.sort_order) || 0,
  }
  try {
    if (editing.value === 'new')
      await api.post(`/api/categories/${props.category.slug}/specs`, { ...body, key: form.key.trim(), type: form.type })
    else await api.put(`/api/spec-definitions/${editing.value.id}`, body)
    editing.value = null
    await load()
  } catch (e) {
    errors.value = parseApiError(e).errors
    if (!Object.keys(errors.value).length) error.value = firstError(e)
  } finally {
    saving.value = false
  }
}

async function remove() {
  try {
    await api.delete(`/api/spec-definitions/${confirmDelete.value.id}`)
    confirmDelete.value = null
    await load()
  } catch (e) {
    error.value = firstError(e)
    confirmDelete.value = null
  }
}

const err = (field) => errors.value[field]?.[0] ?? errors.value[`${field}.0`]?.[0] ?? ''
</script>

<template>
  <BaseModal
    :open="category !== null"
    :title="`Specifications: ${category?.name ?? ''}`"
    size="xl"
    @close="emit('close')"
  >
    <div class="space-y-4 p-5 text-sm">
      <AlertMessage v-if="error">{{ error }}</AlertMessage>

      <template v-if="editing === null">
        <p class="text-muted">
          These are the boxes on a product of this category. Mark one "filter" to offer it in the shop's filters, or "on
          card" to show it on product cards.
        </p>
        <div v-if="loading" class="h-24 animate-pulse rounded-xl bg-elevated" aria-busy="true" />
        <div v-else class="relative overflow-x-auto rounded-xl border border-line">
          <table class="admin-table" data-testid="spec-table">
            <thead>
              <tr>
                <th>Label</th>
                <th>Type</th>
                <th>Options</th>
                <th>Flags</th>
                <th><span class="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!definitions.length">
                <td colspan="5" class="text-muted">No specifications yet.</td>
              </tr>
              <tr v-for="definition in definitions" :key="definition.id">
                <td>
                  {{ definition.label }}
                  <p class="font-mono text-[11px] text-muted">{{ definition.key }}</p>
                </td>
                <td class="text-muted">
                  {{ TYPES.find((t) => t.value === definition.type)?.label
                  }}<span v-if="definition.unit"> · {{ definition.unit }}</span>
                </td>
                <td class="max-w-[14rem] truncate text-xs text-muted" :title="(definition.options ?? []).join(', ')">
                  {{ (definition.options ?? []).join(', ') || '—' }}
                </td>
                <td class="text-xs text-muted">
                  {{
                    [
                      definition.is_required && 'required',
                      definition.is_filterable && 'filter',
                      definition.show_on_card && 'on card',
                    ]
                      .filter(Boolean)
                      .join(' · ') || '—'
                  }}
                </td>
                <td class="whitespace-nowrap text-right">
                  <button
                    type="button"
                    class="text-accent hover:underline"
                    :aria-label="`Edit ${definition.label}`"
                    @click="edit(definition)"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    class="ml-3 text-danger hover:underline"
                    :aria-label="`Delete ${definition.label}`"
                    @click="confirmDelete = definition"
                  >
                    Delete
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <BaseButton variant="secondary" class="!px-4 !py-2" @click="add">Add a specification</BaseButton>

        <AlertMessage v-if="confirmDelete" type="warning">
          <p class="font-medium">Delete “{{ confirmDelete.label }}”?</p>
          <p class="mt-0.5">The values products hold for it are removed too. This cannot be undone.</p>
          <div class="mt-2 flex gap-2">
            <BaseButton class="!bg-danger !px-3 !py-1.5" @click="remove">Delete</BaseButton
            ><BaseButton variant="ghost" class="!px-3 !py-1.5" @click="confirmDelete = null">Keep it</BaseButton>
          </div>
        </AlertMessage>
      </template>

      <form v-else id="spec-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
        <BaseInput v-model="form.label" label="Label" required placeholder="Socket" :error="err('label')" />
        <BaseInput
          v-model="form.key"
          label="Key (letters, numbers and _ only)"
          required
          :disabled="editing !== 'new'"
          placeholder="socket"
          :error="err('key')"
        />
        <FormSelect
          v-model="form.type"
          label="Type"
          :options="TYPES"
          :disabled="editing !== 'new'"
          :error="err('type')"
        />
        <BaseInput v-model="form.unit" label="Unit (optional)" placeholder="W, mm, GB" :error="err('unit')" />
        <div v-if="hasOptions()" class="sm:col-span-2">
          <FormTextarea
            v-model="form.options"
            label="The choices, one per line"
            :rows="4"
            :maxlength="5000"
            :error="err('options')"
          />
        </div>
        <BaseInput v-model="form.sort_order" label="Order on the page" :error="err('sort_order')" />
        <div class="space-y-2 sm:col-span-2">
          <FormCheckbox v-model="form.is_required" label="Required" hint="A product cannot be saved without it." />
          <FormCheckbox v-model="form.is_filterable" label="Offer as a filter in the shop" />
          <FormCheckbox v-model="form.show_on_card" label="Show on product cards" />
        </div>
        <p v-if="editing !== 'new'" class="text-xs text-muted sm:col-span-2">
          The key and the type cannot change, because products already hold values under them.
        </p>
      </form>
    </div>
    <template #footer>
      <div class="flex justify-end gap-3">
        <template v-if="editing === null"
          ><BaseButton variant="ghost" @click="emit('close')">Close</BaseButton></template
        >
        <template v-else>
          <BaseButton variant="ghost" @click="editing = null">Back to the list</BaseButton>
          <BaseButton
            type="submit"
            form="spec-form"
            :loading="saving"
            :disabled="!form.label.trim() || (editing === 'new' && !form.key.trim())"
            >Save</BaseButton
          >
        </template>
      </div>
    </template>
  </BaseModal>
</template>
