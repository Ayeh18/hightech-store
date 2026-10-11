<script setup>
import { reactive, ref } from 'vue'
import AdminPage from '@/components/admin/AdminPage.vue'
import FormCheckbox from '@/components/admin/FormCheckbox.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useAdminList } from '@/composables/useAdminList'
import { api, parseApiError } from '@/lib/api'
import { firstError } from '@/lib/errors'
import { fromShopInput, toShopInput, whenFull } from '@/lib/format'
import { bpsToInput, parsePercent, parseRinggit, senToInput } from '@/lib/money'

const list = useAdminList('/api/admin/coupons', { q: '' })

const STATUS = {
  active: ['Active', 'bg-success/15 text-success'],
  scheduled: ['Not started', 'bg-warning/15 text-warning'],
  expired: ['Expired', 'bg-line text-muted'],
  used_up: ['Used up', 'bg-line text-muted'],
  inactive: ['Switched off', 'bg-line text-muted'],
}
const TYPES = [
  { value: 'percent', label: 'Percentage off' },
  { value: 'fixed', label: 'Fixed amount off' },
]

const emptyForm = () => ({
  code: '',
  description: '',
  type: 'percent',
  value: '',
  min_order: '',
  max_discount: '',
  starts_at: '',
  ends_at: '',
  usage_limit: '',
  per_user_limit: '1',
  is_active: true,
})
const dialog = ref(false)
const editing = ref(null) // the coupon being edited, or null for a new one
const form = reactive(emptyForm())
const errors = ref({})
const generalError = ref('')
const saving = ref(false)
const notice = ref('')

const error = (field) => errors.value[field]?.[0] ?? ''

function openNew() {
  editing.value = null
  Object.assign(form, emptyForm())
  errors.value = {}
  generalError.value = ''
  dialog.value = true
}

function openEdit(coupon) {
  editing.value = coupon
  Object.assign(form, {
    code: coupon.code,
    description: coupon.description ?? '',
    type: coupon.type,
    value: coupon.type === 'percent' ? bpsToInput(coupon.value) : senToInput(coupon.value),
    min_order: coupon.min_order_amount.amount ? senToInput(coupon.min_order_amount.amount) : '',
    max_discount: coupon.max_discount_amount ? senToInput(coupon.max_discount_amount.amount) : '',
    starts_at: toShopInput(coupon.starts_at),
    ends_at: toShopInput(coupon.ends_at),
    // Text, like everything else a person types into the form.
    usage_limit: String(coupon.usage_limit ?? ''),
    per_user_limit: String(coupon.per_user_limit ?? ''),
    is_active: coupon.is_active,
  })
  errors.value = {}
  generalError.value = ''
  dialog.value = true
}

/** The boxes -> what the API wants (a percentage in basis points, money in sen, times as exact moments), or what is wrong. */
function buildPayload() {
  const problems = {}
  const whole = (field, text, label) => {
    const value = String(text ?? '').trim()
    if (value === '') return null
    if (!/^[1-9]\d*$/.test(value)) problems[field] = [`${label} must be a whole number of 1 or more.`]
    return Number(value)
  }

  const percent = form.type === 'percent'
  const value = percent ? parsePercent(form.value) : parseRinggit(form.value)
  if (value === null || value < 1)
    problems.value = [
      percent
        ? 'Enter a percentage from 0.01 to 100, for example 10.'
        : 'Enter an amount in ringgit, for example 50.00',
    ]

  let minOrder = 0
  if (form.min_order.trim() !== '') {
    minOrder = parseRinggit(form.min_order)
    if (minOrder === null) problems.min_order_amount = ['Enter an amount in ringgit, for example 1000.00']
  }

  let maxDiscount = null
  if (percent && form.max_discount.trim() !== '') {
    maxDiscount = parseRinggit(form.max_discount)
    if (maxDiscount === null || maxDiscount < 1)
      problems.max_discount_amount = ['Enter an amount in ringgit, for example 200.00']
  }

  errors.value = problems
  if (Object.keys(problems).length) return null

  return {
    code: form.code.trim(),
    description: form.description.trim() || null,
    type: form.type,
    value,
    min_order_amount: minOrder,
    max_discount_amount: maxDiscount,
    starts_at: fromShopInput(form.starts_at),
    ends_at: fromShopInput(form.ends_at),
    usage_limit: whole('usage_limit', form.usage_limit, 'The limit'),
    per_user_limit: whole('per_user_limit', form.per_user_limit, 'The limit'),
    is_active: form.is_active,
  }
}

async function save() {
  generalError.value = ''
  const payload = buildPayload()
  if (!payload) return
  if (Object.keys(errors.value).length) return

  saving.value = true
  try {
    if (editing.value) await api.put(`/api/admin/coupons/${editing.value.id}`, payload)
    else await api.post('/api/admin/coupons', payload)
    notice.value = editing.value ? `${payload.code.toUpperCase()} saved.` : `${payload.code.toUpperCase()} created.`
    dialog.value = false
    await list.reload()
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.errors
    generalError.value = Object.keys(parsed.errors).length ? '' : parsed.message
  } finally {
    saving.value = false
  }
}

// ---------- delete
const deleting = ref(null)
const deleteError = ref('')
const deleteBusy = ref(false)

async function destroy() {
  deleteBusy.value = true
  deleteError.value = ''
  try {
    await api.delete(`/api/admin/coupons/${deleting.value.id}`)
    notice.value = `${deleting.value.code} deleted.`
    deleting.value = null
    await list.reload()
  } catch (e) {
    deleteError.value = firstError(e)
  } finally {
    deleteBusy.value = false
  }
}
</script>

<template>
  <AdminPage title="Coupons" subtitle="Discount codes customers can type at checkout.">
    <template #actions><BaseButton @click="openNew">New coupon</BaseButton></template>

    <div class="mb-4 max-w-sm"><BaseInput v-model="list.params.q" label="Search by code" /></div>
    <AlertMessage v-if="notice" type="success" class="mb-4">{{ notice }}</AlertMessage>
    <AlertMessage v-if="list.error.value" class="mb-4">{{ list.error.value }}</AlertMessage>

    <div
      v-if="!list.loaded.value && list.loading.value"
      class="h-48 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading coupons"
    />
    <EmptyState
      v-else-if="list.loaded.value && !list.rows.value.length"
      title="No coupons yet"
      text="Create one to offer a discount code."
      ><BaseButton @click="openNew">New coupon</BaseButton></EmptyState
    >
    <div
      v-else
      class="relative overflow-x-auto rounded-card border border-line bg-surface"
      :class="{ 'opacity-60 transition': list.loading.value }"
    >
      <table class="admin-table" data-testid="coupons-table">
        <thead>
          <tr>
            <th>Code</th>
            <th>Discount</th>
            <th>Minimum order</th>
            <th>Valid</th>
            <th class="text-right">Used</th>
            <th>Status</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="coupon in list.rows.value" :key="coupon.id">
            <td>
              <p class="font-mono font-semibold">{{ coupon.code }}</p>
              <p v-if="coupon.description" class="text-xs text-muted">{{ coupon.description }}</p>
            </td>
            <td class="tabular-nums">
              {{ coupon.value_display }}
              <p v-if="coupon.max_discount_amount" class="text-xs text-muted">
                up to {{ coupon.max_discount_amount.formatted }}
              </p>
            </td>
            <td class="tabular-nums text-muted">
              {{ coupon.min_order_amount.amount ? coupon.min_order_amount.formatted : 'None' }}
            </td>
            <td class="text-xs text-muted">
              {{ coupon.starts_at ? whenFull(coupon.starts_at) : 'Any time' }}<br />→
              {{ coupon.ends_at ? whenFull(coupon.ends_at) : 'No end' }}
            </td>
            <td class="text-right tabular-nums">
              {{ coupon.used_count }}<span class="text-muted"> / {{ coupon.usage_limit ?? '∞' }}</span>
            </td>
            <td>
              <span
                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                :class="STATUS[coupon.status][1]"
                >{{ STATUS[coupon.status][0] }}</span
              >
            </td>
            <td class="whitespace-nowrap text-right">
              <button
                type="button"
                class="text-sm text-accent hover:underline"
                :aria-label="`Edit ${coupon.code}`"
                @click="openEdit(coupon)"
              >
                Edit
              </button>
              <button
                type="button"
                class="ml-3 text-sm text-danger hover:underline"
                :aria-label="`Delete ${coupon.code}`"
                @click="((deleting = coupon), (deleteError = ''))"
              >
                Delete
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="mt-4">
      <PaginationBar :page="list.meta.value.current_page" :last-page="list.meta.value.last_page" @change="list.load" />
    </div>

    <BaseModal
      :open="dialog"
      :title="editing ? `Edit ${editing.code}` : 'New coupon'"
      size="lg"
      @close="dialog = false"
    >
      <form id="coupon-form" class="grid gap-4 p-5 text-sm sm:grid-cols-2" novalidate @submit.prevent="save">
        <AlertMessage v-if="generalError" class="sm:col-span-2">{{ generalError }}</AlertMessage>
        <BaseInput v-model="form.code" label="Code" required placeholder="WELCOME10" :error="error('code')" />
        <BaseInput v-model="form.description" label="Description (for your team)" :error="error('description')" />
        <FormSelect v-model="form.type" label="Type" :options="TYPES" :error="error('type')" />
        <BaseInput
          v-model="form.value"
          :label="form.type === 'percent' ? 'Percentage off (%)' : 'Amount off (RM)'"
          required
          :placeholder="form.type === 'percent' ? '10' : '50.00'"
          :error="error('value')"
        />
        <BaseInput
          v-model="form.min_order"
          label="Minimum order (RM), optional"
          placeholder="1000.00"
          :error="error('min_order_amount')"
        />
        <BaseInput
          v-if="form.type === 'percent'"
          v-model="form.max_discount"
          label="Never take off more than (RM), optional"
          placeholder="200.00"
          :error="error('max_discount_amount')"
        />
        <div>
          <label for="coupon-starts" class="mb-1.5 block text-sm font-medium">Starts (Malaysian time), optional</label>
          <input
            id="coupon-starts"
            v-model="form.starts_at"
            type="datetime-local"
            class="w-full rounded-xl border border-line bg-bg px-3 py-2.5 text-sm focus:border-accent focus:outline-none"
          />
          <p v-if="error('starts_at')" class="mt-1.5 text-xs text-danger" role="alert">{{ error('starts_at') }}</p>
        </div>
        <div>
          <label for="coupon-ends" class="mb-1.5 block text-sm font-medium">Ends (Malaysian time), optional</label>
          <input
            id="coupon-ends"
            v-model="form.ends_at"
            type="datetime-local"
            class="w-full rounded-xl border border-line bg-bg px-3 py-2.5 text-sm focus:border-accent focus:outline-none"
          />
          <p v-if="error('ends_at')" class="mt-1.5 text-xs text-danger" role="alert">{{ error('ends_at') }}</p>
        </div>
        <BaseInput
          v-model="form.usage_limit"
          label="Total uses allowed, optional"
          placeholder="Unlimited"
          :error="error('usage_limit')"
        />
        <BaseInput
          v-model="form.per_user_limit"
          label="Uses per customer, optional"
          placeholder="Unlimited"
          :error="error('per_user_limit')"
        />
        <div class="sm:col-span-2">
          <FormCheckbox
            v-model="form.is_active"
            label="Switched on"
            hint="Switch it off to stop it working without deleting it."
          />
        </div>
      </form>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="dialog = false">Cancel</BaseButton>
          <BaseButton type="submit" form="coupon-form" :loading="saving">{{
            editing ? 'Save coupon' : 'Create coupon'
          }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <BaseModal :open="deleting !== null" title="Delete this coupon?" @close="deleting = null">
      <div class="space-y-3 p-5 text-sm">
        <AlertMessage v-if="deleteError">{{ deleteError }}</AlertMessage>
        <p>
          <span class="font-mono font-semibold">{{ deleting?.code }}</span> will stop working. A coupon that has already
          been used on an order cannot be deleted: switch it off instead.
        </p>
      </div>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="deleting = null">Keep it</BaseButton>
          <BaseButton class="!bg-danger" :loading="deleteBusy" @click="destroy">Delete</BaseButton>
        </div>
      </template>
    </BaseModal>
  </AdminPage>
</template>
