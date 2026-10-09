<script setup>
import { ref, watch } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'

/** A coupon code field. The server decides whether the code is valid; `applied` and `error` come from its answer. */
const props = defineProps({
  appliedCode: { type: String, default: null },
  error: { type: String, default: '' },
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['apply', 'remove'])

const code = ref('')
watch(
  () => props.appliedCode,
  (applied) => {
    if (applied) code.value = ''
  },
)

const submit = () => code.value.trim() && emit('apply', code.value.trim())
</script>

<template>
  <div>
    <div
      v-if="appliedCode"
      class="flex items-center justify-between gap-3 rounded-xl border border-success/40 bg-success/10 px-4 py-3 text-sm"
    >
      <p class="text-success">
        <span aria-hidden="true">✓</span> <span class="font-semibold">{{ appliedCode }}</span> applied
      </p>
      <button
        type="button"
        class="text-muted underline-offset-2 transition hover:text-ink hover:underline"
        @click="emit('remove')"
      >
        Remove
      </button>
    </div>

    <form v-else class="flex gap-2" novalidate @submit.prevent="submit">
      <div class="min-w-0 flex-1">
        <label for="coupon-code" class="sr-only">Coupon code</label>
        <input
          id="coupon-code"
          v-model="code"
          type="text"
          autocomplete="off"
          maxlength="40"
          placeholder="Coupon code"
          class="w-full rounded-xl border bg-bg px-4 py-2.5 text-sm uppercase text-ink placeholder:normal-case placeholder:text-muted/70 focus:border-accent focus:outline-none"
          :class="error ? 'border-danger' : 'border-line'"
          :aria-invalid="error ? 'true' : undefined"
          aria-describedby="coupon-error"
        />
      </div>
      <BaseButton type="submit" variant="secondary" :disabled="!code.trim()" :loading="busy">Apply</BaseButton>
    </form>
    <p v-if="error && !appliedCode" id="coupon-error" class="mt-1.5 text-xs text-danger" role="alert">{{ error }}</p>
  </div>
</template>
