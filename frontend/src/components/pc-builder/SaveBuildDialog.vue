<script setup>
import { ref, watch } from 'vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

/** Asks for a name. Used for saving a new build and for renaming a saved one. */
const props = defineProps({
  open: { type: Boolean, required: true },
  title: { type: String, default: 'Name your build' },
  confirmLabel: { type: String, default: 'Save build' },
  initialName: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  fieldError: { type: String, default: '' },
})
const emit = defineEmits(['close', 'confirm'])

const name = ref('')
watch(
  () => props.open,
  (open) => {
    if (open) name.value = props.initialName
  },
  { immediate: true },
)

const submit = () => name.value.trim() && emit('confirm', name.value.trim())
</script>

<template>
  <BaseModal :open="open" :title="title" @close="emit('close')">
    <form id="name-form" class="space-y-4 p-5" novalidate @submit.prevent="submit">
      <AlertMessage v-if="error">{{ error }}</AlertMessage>
      <BaseInput
        v-model="name"
        label="Build name"
        placeholder="Gaming PC 2026"
        data-autofocus
        :error="fieldError"
        required
      />
    </form>
    <template #footer>
      <div class="flex justify-end gap-3">
        <BaseButton variant="ghost" @click="emit('close')">Cancel</BaseButton>
        <BaseButton type="submit" form="name-form" :loading="loading" :disabled="!name.trim()">{{
          confirmLabel
        }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
