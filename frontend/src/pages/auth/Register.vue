<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { parseApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const form = reactive({ name: '', email: '', phone: '', password: '', password_confirmation: '' })
const errors = ref({})
const message = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  errors.value = {}
  message.value = ''
  try {
    await auth.register({ ...form })
    router.push({ name: 'home' })
  } catch (error) {
    const parsed = parseApiError(error)
    errors.value = parsed.errors
    message.value = Object.keys(parsed.errors).length ? '' : parsed.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="font-display text-2xl font-semibold">Create your account</h1>
    <p class="mt-1 text-sm text-muted">Save builds, track orders and check out faster.</p>

    <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <AlertMessage v-if="message">{{ message }}</AlertMessage>
      <BaseInput v-model="form.name" label="Full name" autocomplete="name" :error="errors.name?.[0]" required />
      <BaseInput
        v-model="form.email"
        label="Email"
        type="email"
        autocomplete="email"
        :error="errors.email?.[0]"
        required
      />
      <BaseInput
        v-model="form.phone"
        label="Phone (optional)"
        type="tel"
        autocomplete="tel"
        placeholder="012-3456789"
        :error="errors.phone?.[0]"
      />
      <BaseInput
        v-model="form.password"
        label="Password"
        type="password"
        autocomplete="new-password"
        :error="errors.password?.[0]"
        required
      />
      <p class="-mt-2 text-xs text-muted">At least 8 characters, with letters and numbers.</p>
      <BaseInput
        v-model="form.password_confirmation"
        label="Confirm password"
        type="password"
        autocomplete="new-password"
        required
      />
      <BaseButton type="submit" block :loading="loading">Create account</BaseButton>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
      Already registered?
      <RouterLink to="/login" class="font-medium text-accent hover:underline">Log in</RouterLink>
    </p>
  </div>
</template>
