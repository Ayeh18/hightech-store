<script setup>
import { reactive, watch } from 'vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { MALAYSIAN_STATES } from '@/lib/malaysia'

/** Create or edit a delivery address. The server validates everything; `errors` shows what it did not like. */
const props = defineProps({
  address: { type: Object, default: null }, // when editing
  errors: { type: Object, default: () => ({}) }, // { field: ['message'] } from the API
  message: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  submitLabel: { type: String, default: 'Save address' },
  showDefault: { type: Boolean, default: true },
  cancellable: { type: Boolean, default: false },
})
const emit = defineEmits(['submit', 'cancel'])

const blank = () => ({
  label: '',
  recipient_name: '',
  phone: '',
  line1: '',
  line2: '',
  city: '',
  state: '',
  postcode: '',
  is_default: false,
})
const form = reactive(blank())

watch(
  () => props.address,
  (address) =>
    Object.assign(form, blank(), address ? { ...address, line2: address.line2 ?? '', label: address.label ?? '' } : {}),
  { immediate: true },
)

const first = (field) => props.errors[field]?.[0] ?? ''

function submit() {
  emit('submit', {
    label: form.label.trim() || null,
    recipient_name: form.recipient_name.trim(),
    phone: form.phone.trim(),
    line1: form.line1.trim(),
    line2: form.line2.trim() || null,
    city: form.city.trim(),
    state: form.state,
    postcode: form.postcode.trim(),
    ...(props.showDefault ? { is_default: form.is_default } : {}),
  })
}
</script>

<template>
  <form class="space-y-4" novalidate @submit.prevent="submit">
    <AlertMessage v-if="message">{{ message }}</AlertMessage>

    <div class="grid gap-4 sm:grid-cols-2">
      <BaseInput
        v-model="form.recipient_name"
        label="Full name"
        autocomplete="name"
        :error="first('recipient_name')"
        required
      />
      <BaseInput
        v-model="form.phone"
        label="Phone"
        type="tel"
        autocomplete="tel"
        placeholder="012-3456789"
        :error="first('phone')"
        required
      />
    </div>
    <BaseInput
      v-model="form.line1"
      label="Address line 1"
      autocomplete="address-line1"
      :error="first('line1')"
      required
    />
    <BaseInput
      v-model="form.line2"
      label="Address line 2 (optional)"
      autocomplete="address-line2"
      :error="first('line2')"
    />

    <div class="grid gap-4 sm:grid-cols-3">
      <BaseInput v-model="form.city" label="City" autocomplete="address-level2" :error="first('city')" required />
      <div>
        <label for="address-state" class="mb-1.5 block text-sm font-medium text-ink">State</label>
        <select
          id="address-state"
          v-model="form.state"
          autocomplete="address-level1"
          class="w-full rounded-xl border bg-bg px-4 py-2.5 text-sm text-ink transition focus:border-accent focus:outline-none"
          :class="first('state') ? 'border-danger' : 'border-line'"
          :aria-invalid="first('state') ? 'true' : undefined"
          required
        >
          <option value="" disabled>Choose…</option>
          <option v-for="state in MALAYSIAN_STATES" :key="state" :value="state">{{ state }}</option>
        </select>
        <p v-if="first('state')" class="mt-1.5 text-xs text-danger" role="alert">{{ first('state') }}</p>
      </div>
      <BaseInput
        v-model="form.postcode"
        label="Postcode"
        autocomplete="postal-code"
        placeholder="47810"
        :error="first('postcode')"
        required
      />
    </div>

    <BaseInput v-model="form.label" label="Label (optional)" placeholder="Home, Office…" :error="first('label')" />

    <label v-if="showDefault" class="flex cursor-pointer items-center gap-3 text-sm">
      <input v-model="form.is_default" type="checkbox" class="size-4 accent-accent" />
      Use as my default address
    </label>

    <div class="flex flex-wrap justify-end gap-3 pt-1">
      <BaseButton v-if="cancellable" variant="ghost" @click="emit('cancel')">Cancel</BaseButton>
      <BaseButton type="submit" :loading="loading">{{ submitLabel }}</BaseButton>
    </div>
  </form>
</template>
