<script setup>
import { onMounted, ref } from 'vue'
import AddressCard from '@/components/account/AddressCard.vue'
import AddressForm from '@/components/account/AddressForm.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { parseApiError } from '@/lib/api'
import { useAddressStore } from '@/stores/addresses'

const store = useAddressStore()

const editing = ref(null) // an address, or {} for a new one
const deleting = ref(null)
const saving = ref(false)
const errors = ref({})
const message = ref('')
const actionError = ref('')

onMounted(() => store.fetch())

function open(address = {}) {
  errors.value = {}
  message.value = ''
  editing.value = address
}

async function save(payload) {
  saving.value = true
  errors.value = {}
  message.value = ''
  try {
    if (editing.value.id) await store.update(editing.value.id, payload)
    else await store.create(payload)
    editing.value = null
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.errors
    message.value = Object.keys(parsed.errors).length ? '' : parsed.message
  } finally {
    saving.value = false
  }
}

async function makeDefault(address) {
  actionError.value = ''
  try {
    await store.update(address.id, { is_default: true })
  } catch (e) {
    actionError.value = parseApiError(e).message
  }
}

async function confirmDelete() {
  saving.value = true
  try {
    await store.remove(deleting.value.id)
    deleting.value = null
  } catch (e) {
    actionError.value = parseApiError(e).message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight">My addresses</h1>
        <p class="mt-1 text-sm text-muted">
          Where we can deliver. Orders keep their own copy of the address, so editing one here never changes an order
          you already placed.
        </p>
      </div>
      <BaseButton @click="open()">Add address</BaseButton>
    </header>

    <AlertMessage v-if="actionError" class="mb-4">{{ actionError }}</AlertMessage>

    <ul
      v-if="store.loading && !store.loaded"
      class="grid animate-pulse gap-4 sm:grid-cols-2"
      aria-busy="true"
      aria-label="Loading your addresses"
    >
      <li v-for="n in 2" :key="n" class="h-40 rounded-card border border-line bg-surface" />
    </ul>

    <div v-else-if="store.error" role="alert">
      <EmptyState title="We couldn't load your addresses" :text="store.error"
        ><BaseButton @click="store.fetch()">Try again</BaseButton></EmptyState
      >
    </div>

    <EmptyState
      v-else-if="!store.addresses.length"
      title="No saved addresses"
      text="Add one now and checkout will be quicker next time."
    >
      <BaseButton @click="open()">Add address</BaseButton>
    </EmptyState>

    <ul v-else class="grid gap-4 sm:grid-cols-2">
      <li
        v-for="address in store.addresses"
        :key="address.id"
        class="flex flex-col rounded-card border border-line bg-surface p-5"
      >
        <AddressCard :address="address" class="flex-1" />
        <div class="mt-4 flex flex-wrap gap-2">
          <BaseButton variant="secondary" class="!px-3.5 !py-1.5" @click="open(address)">Edit</BaseButton>
          <BaseButton v-if="!address.is_default" variant="ghost" class="!px-3 !py-1.5" @click="makeDefault(address)"
            >Make default</BaseButton
          >
          <BaseButton variant="ghost" class="!px-3 !py-1.5 hover:!text-danger" @click="deleting = address"
            >Delete</BaseButton
          >
        </div>
      </li>
    </ul>

    <BaseModal :open="editing !== null" :title="editing?.id ? 'Edit address' : 'Add address'" @close="editing = null">
      <div class="p-5">
        <AddressForm
          :address="editing?.id ? editing : null"
          :errors="errors"
          :message="message"
          :loading="saving"
          cancellable
          @submit="save"
          @cancel="editing = null"
        />
      </div>
    </BaseModal>

    <BaseModal :open="deleting !== null" title="Delete this address?" @close="deleting = null">
      <div class="p-5 text-sm">
        <AddressCard v-if="deleting" :address="deleting" />
        <p class="mt-3 text-muted">Your past orders are not affected.</p>
      </div>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="deleting = null">Keep it</BaseButton>
          <BaseButton class="!bg-danger" :loading="saving" @click="confirmDelete">Delete</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
