<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { parseApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const form = reactive({ email: '', password: '' })
const errors = ref({})
const message = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  errors.value = {}
  message.value = ''
  try {
    await auth.login({ ...form })
    // Only follow same-site redirects ("/admin"), never "//evil.com".
    const redirect = String(route.query.redirect ?? '')
    router.push(redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : { name: 'home' })
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
    <h1 class="font-display text-2xl font-semibold">Welcome back</h1>
    <p class="mt-1 text-sm text-muted">Log in to manage your builds and orders.</p>

    <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <AlertMessage v-if="auth.sessionEnded" type="warning">
        You were signed out because your session ended. Please log in again.
      </AlertMessage>
      <AlertMessage v-if="message">{{ message }}</AlertMessage>
      <BaseInput
        v-model="form.email"
        label="Email"
        type="email"
        autocomplete="email"
        :error="errors.email?.[0]"
        required
      />
      <BaseInput
        v-model="form.password"
        label="Password"
        type="password"
        autocomplete="current-password"
        :error="errors.password?.[0]"
        required
      />
      <BaseButton type="submit" block :loading="loading">Log in</BaseButton>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
      New here?
      <RouterLink to="/register" class="font-medium text-accent hover:underline">Create an account</RouterLink>
    </p>
  </div>
</template>
