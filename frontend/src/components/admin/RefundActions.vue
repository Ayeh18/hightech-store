<script setup>
import { ref } from 'vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import FormTextarea from '@/components/admin/FormTextarea.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { api } from '@/lib/api'
import { firstError } from '@/lib/errors'

/**
 * The buttons for one refund, shown to people who may decide refunds:
 *   REQUESTED -> Approve / Decline      FAILED (or stuck) -> Retry
 * It asks the server, and tells the page (`changed`) when something happened so the page can reload.
 * Note that the payment company can say no even to an approval: the refund then comes back as FAILED, which is shown, not hidden.
 */
const props = defineProps({ refund: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const dialog = ref(null) // 'approve' | 'reject'
const restock = ref('')
const note = ref('')
const busy = ref(false)
const error = ref('')
const result = ref('')

const RESTOCK = [
  { value: 'true', label: 'Yes: put the goods back on the shelf' },
  { value: 'false', label: 'No: the goods are not coming back' },
]

function open(kind) {
  dialog.value = kind
  restock.value = ''
  note.value = ''
  error.value = ''
}

async function send(path, body = {}) {
  busy.value = true
  error.value = ''
  result.value = ''
  try {
    const { data } = await api.post(`/api/admin/refunds/${props.refund.id}/${path}`, body)
    dialog.value = null
    // A refund the payment company refused is a normal answer, not an error: say so plainly.
    if (data.data.status.value === 'FAILED')
      result.value = `The payment company did not complete it: ${data.data.failure_reason}. You can retry.`
    emit('changed', data.data)
  } catch (e) {
    error.value = firstError(e)
  } finally {
    busy.value = false
  }
}

const approve = () =>
  send('approve', {
    ...(restock.value === '' ? {} : { restock: restock.value === 'true' }),
    ...(note.value.trim() ? { note: note.value.trim() } : {}),
  })
const reject = () => send('reject', { note: note.value.trim() })
const retry = () => send('retry')
</script>

<template>
  <div class="space-y-2" data-testid="refund-actions">
    <AlertMessage v-if="result" type="warning">{{ result }}</AlertMessage>
    <AlertMessage v-if="error && !dialog">{{ error }}</AlertMessage>

    <div v-if="refund.status.value === 'REQUESTED'" class="flex flex-wrap gap-2">
      <BaseButton class="!px-4 !py-2" @click="open('approve')">Approve…</BaseButton>
      <BaseButton variant="secondary" class="!px-4 !py-2" @click="open('reject')">Decline…</BaseButton>
    </div>
    <BaseButton
      v-else-if="refund.status.value === 'FAILED'"
      variant="secondary"
      class="!px-4 !py-2"
      :loading="busy"
      @click="retry"
    >
      Retry the refund
    </BaseButton>

    <BaseModal :open="dialog === 'approve'" title="Approve this refund" @close="dialog = null">
      <form id="approve-refund" class="space-y-4 p-5 text-sm" @submit.prevent="approve">
        <AlertMessage v-if="error">{{ error }}</AlertMessage>
        <p>
          <span class="font-semibold">{{ refund.amount.formatted }}</span> goes back to the customer's original payment
          method. This cannot be undone.
        </p>
        <FormSelect
          v-model="restock"
          label="Do the goods go back on the shelf?"
          :options="RESTOCK"
          placeholder="Let the system decide (yes, unless the order was shipped)"
        />
        <FormTextarea v-model="note" label="Note for the record (optional)" :rows="2" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="dialog = null">Not now</BaseButton>
          <BaseButton type="submit" form="approve-refund" :loading="busy">Approve and refund</BaseButton>
        </div>
      </template>
    </BaseModal>

    <BaseModal :open="dialog === 'reject'" title="Decline this refund" @close="dialog = null">
      <form id="reject-refund" class="space-y-4 p-5 text-sm" @submit.prevent="reject">
        <AlertMessage v-if="error">{{ error }}</AlertMessage>
        <p>The customer will be emailed your reason, and their order goes back to how it was.</p>
        <FormTextarea
          v-model="note"
          label="Reason (the customer will read this)"
          :rows="3"
          placeholder="For example: the item shows signs of use"
        />
      </form>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="dialog = null">Not now</BaseButton>
          <BaseButton
            type="submit"
            form="reject-refund"
            class="!bg-danger"
            :loading="busy"
            :disabled="note.trim().length < 3"
            >Decline</BaseButton
          >
        </div>
      </template>
    </BaseModal>
  </div>
</template>
