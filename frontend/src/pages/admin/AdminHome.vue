<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { firstAdminPage } from '@/lib/adminNav'
import { useAuthStore } from '@/stores/auth'

// "/admin" is only a doorway: go to the first page this person is allowed to use.
const router = useRouter()
const auth = useAuthStore()

onMounted(() => router.replace(firstAdminPage(auth.can) ?? { name: 'home' }))
</script>

<template>
  <p class="text-sm text-muted" aria-busy="true">Opening the admin area…</p>
</template>
