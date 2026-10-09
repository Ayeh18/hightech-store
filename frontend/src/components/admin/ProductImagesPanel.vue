<script setup>
import { ref } from 'vue'
import FormCheckbox from '@/components/admin/FormCheckbox.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { api } from '@/lib/api'
import { firstError } from '@/lib/errors'

/**
 * The photos of a product: upload, choose the main one, remove. The server re-saves each upload as a safe WebP and
 * enforces the limits; the notes below are only so nobody is surprised.
 */
const props = defineProps({ productId: { type: Number, required: true }, images: { type: Array, required: true } })
const emit = defineEmits(['changed'])

const MAX_IMAGES = 8
const file = ref(null)
const alt = ref('')
const primary = ref(false)
const busy = ref(false)
const error = ref('')
const input = ref(null)

async function upload() {
  if (!file.value) return
  busy.value = true
  error.value = ''
  try {
    const form = new FormData()
    form.append('image', file.value)
    if (alt.value.trim()) form.append('alt', alt.value.trim())
    if (primary.value) form.append('is_primary', '1')
    await api.post(`/api/products/${props.productId}/images`, form)
    file.value = null
    alt.value = ''
    primary.value = false
    if (input.value) input.value.value = ''
    emit('changed')
  } catch (e) {
    error.value = firstError(e)
  } finally {
    busy.value = false
  }
}

async function remove(image) {
  busy.value = true
  error.value = ''
  try {
    await api.delete(`/api/products/${props.productId}/images/${image.id}`)
    emit('changed')
  } catch (e) {
    error.value = firstError(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="images-heading">
    <h2 id="images-heading" class="font-display text-lg font-semibold">Photos</h2>
    <p class="mt-1 text-xs text-muted">
      Up to {{ MAX_IMAGES }}. JPG, PNG or WebP, at least 200×200 pixels, up to 3 MB. The first one is shown in the shop.
    </p>

    <AlertMessage v-if="error" class="mt-3">{{ error }}</AlertMessage>

    <ul v-if="images.length" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" data-testid="image-list">
      <li v-for="image in images" :key="image.id" class="overflow-hidden rounded-xl border border-line bg-bg">
        <img :src="image.url" :alt="image.alt ?? ''" class="aspect-square w-full object-contain" loading="lazy" />
        <div class="flex items-center justify-between gap-2 p-2 text-xs">
          <span :class="image.is_primary ? 'font-medium text-accent' : 'text-muted'">{{
            image.is_primary ? 'Main photo' : 'Photo'
          }}</span>
          <button
            type="button"
            class="text-danger hover:underline disabled:opacity-50"
            :disabled="busy"
            :aria-label="`Remove photo ${image.alt ?? image.id}`"
            @click="remove(image)"
          >
            Remove
          </button>
        </div>
      </li>
    </ul>
    <p v-else class="mt-4 text-sm text-muted">No photos yet.</p>

    <form v-if="images.length < MAX_IMAGES" class="mt-5 space-y-3 border-t border-line pt-4" @submit.prevent="upload">
      <div>
        <label for="image-file" class="mb-1.5 block text-sm font-medium">Add a photo</label>
        <input
          id="image-file"
          ref="input"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-elevated file:px-3 file:py-2 file:text-sm file:text-ink"
          @change="file = $event.target.files[0] ?? null"
        />
      </div>
      <BaseInput v-model="alt" label="Describe it (for screen readers)" placeholder="Front view of the graphics card" />
      <FormCheckbox v-model="primary" label="Make this the main photo" />
      <BaseButton type="submit" variant="secondary" :loading="busy" :disabled="!file">Upload</BaseButton>
    </form>
  </section>
</template>
