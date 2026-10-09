<script setup>
import { computed, ref } from 'vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { api } from '@/lib/api'
import { firstError } from '@/lib/errors'

/**
 * Stock for one product: what there is, what is promised to unpaid orders, and what can still be sold. Staff add or remove
 * units with a reason; every change is written to the stock history and the audit log by the server, and stock can never go below zero.
 */
const props = defineProps({ productId: { type: Number, required: true }, inventory: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const REASONS = [
  { value: 'restock', label: 'Restock (new units arrived)' },
  { value: 'return', label: 'Return (units came back)' },
  { value: 'correction', label: 'Correction (the count was wrong)' },
  { value: 'adjustment', label: 'Other adjustment (damaged, lost…)' },
]

const change = ref('')
const reason = ref('restock')
const note = ref('')
const busy = ref(false)
const error = ref('')
const done = ref('')

const amount = computed(() => (/^[+-]?\d+$/.test(change.value.trim()) ? Number(change.value.trim()) : null))

async function adjust() {
  if (amount.value === null || amount.value === 0) {
    error.value = 'Enter a whole number of units, like 10 to add or -2 to remove.'
    return
  }
  busy.value = true
  error.value = ''
  done.value = ''
  try {
    const { data } = await api.post(`/api/products/${props.productId}/inventory/adjustments`, {
      change: amount.value,
      reason: reason.value,
      ...(note.value.trim() ? { note: note.value.trim() } : {}),
    })
    done.value = `Stock is now ${data.data.stock}.`
    change.value = ''
    note.value = ''
    emit('changed', data.data)
  } catch (e) {
    error.value = firstError(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="stock-heading">
    <h2 id="stock-heading" class="font-display text-lg font-semibold">Stock</h2>
    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4" data-testid="stock-numbers">
      <div>
        <dt class="text-xs text-muted">In the warehouse</dt>
        <dd class="mt-1 text-xl font-semibold tabular-nums">{{ inventory.stock }}</dd>
      </div>
      <div>
        <dt class="text-xs text-muted">Held for unpaid orders</dt>
        <dd class="mt-1 text-xl font-semibold tabular-nums">{{ inventory.reserved }}</dd>
      </div>
      <div>
        <dt class="text-xs text-muted">Can be sold</dt>
        <dd
          class="mt-1 text-xl font-semibold tabular-nums"
          :class="inventory.available <= inventory.low_stock_threshold ? 'text-warning' : ''"
        >
          {{ inventory.available }}
        </dd>
      </div>
      <div>
        <dt class="text-xs text-muted">Warn me at</dt>
        <dd class="mt-1 text-xl font-semibold tabular-nums">{{ inventory.low_stock_threshold }}</dd>
      </div>
    </dl>

    <form class="mt-5 grid gap-3 border-t border-line pt-4 sm:grid-cols-2" @submit.prevent="adjust">
      <AlertMessage v-if="error" class="sm:col-span-2">{{ error }}</AlertMessage>
      <AlertMessage v-if="done" type="success" class="sm:col-span-2">{{ done }}</AlertMessage>
      <BaseInput v-model="change" label="Change (units)" placeholder="10 or -2" />
      <FormSelect v-model="reason" label="Why" :options="REASONS" />
      <div class="sm:col-span-2">
        <BaseInput v-model="note" label="Note (optional)" placeholder="Supplier invoice 4821" />
      </div>
      <div class="sm:col-span-2">
        <BaseButton type="submit" variant="secondary" :loading="busy" :disabled="!change.trim()"
          >Adjust stock</BaseButton
        >
      </div>
    </form>
  </section>
</template>
