<script setup>
import { ref } from 'vue'
import AddressCard from '@/components/account/AddressCard.vue'
import AddressForm from '@/components/account/AddressForm.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { parseApiError } from '@/lib/api'
import { useAddressStore } from '@/stores/addresses'

/** Choose where to deliver: one of the saved addresses, or add a new one without leaving the checkout. */
const props = defineProps({ modelValue: { type: Number, default: null } })
const emit = defineEmits(['update:modelValue'])

const store = useAddressStore()
const adding = ref(false)
const saving = ref(false)
const errors = ref({})
const message = ref('')

async function save(payload) {
  saving.value = true
  errors.value = {}
  message.value = ''
  try {
    const created = await store.create(payload)
    emit('update:modelValue', created.id)
    adding.value = false
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.errors
    message.value = Object.keys(parsed.errors).length ? '' : parsed.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <ul
      v-if="store.addresses.length && !adding"
      class="grid gap-3 sm:grid-cols-2"
      role="radiogroup"
      aria-label="Delivery address"
    >
      <li v-for="address in store.addresses" :key="address.id">
        <button
          type="button"
          role="radio"
          :aria-checked="address.id === props.modelValue"
          class="h-full w-full rounded-xl border p-4 text-left transition"
          :class="
            address.id === props.modelValue
              ? 'border-accent bg-accent-soft/40'
              : 'border-line bg-bg hover:border-accent/50'
          "
          @click="emit('update:modelValue', address.id)"
        >
          <AddressCard :address="address" selectable :selected="address.id === props.modelValue" />
        </button>
      </li>
    </ul>

    <div v-if="!adding && store.addresses.length" class="mt-3">
      <BaseButton variant="secondary" class="!px-4 !py-2" @click="adding = true">Add a new address</BaseButton>
    </div>

    <div v-if="adding || !store.addresses.length" class="rounded-xl border border-line bg-bg p-4 sm:p-5">
      <p v-if="!store.addresses.length" class="mb-4 text-sm text-muted">
        You have no saved addresses yet. Add where we should deliver.
      </p>
      <AddressForm
        :errors="errors"
        :message="message"
        :loading="saving"
        submit-label="Save and deliver here"
        :show-default="false"
        :cancellable="store.addresses.length > 0"
        @submit="save"
        @cancel="adding = false"
      />
    </div>
  </div>
</template>
