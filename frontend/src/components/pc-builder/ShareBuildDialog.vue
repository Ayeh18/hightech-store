<script setup>
import { computed, ref, watch } from 'vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { parseApiError } from '@/lib/api'
import { useBuildsStore } from '@/stores/builds'

/*
 * Share a saved build. A build is private until its owner creates a link here; the link can be switched off again at
 * any time, after which it never works again.
 */
const props = defineProps({
  open: { type: Boolean, required: true },
  build: { type: Object, default: null },
})
const emit = defineEmits(['close'])

const store = useBuildsStore()
const busy = ref(false)
const error = ref('')
const copied = ref(false)
const field = ref(null)

// The API gives the path; this website's own address goes in front of it.
const link = computed(() => (props.build?.share ? `${window.location.origin}${props.build.share.path}` : ''))

watch(
  () => props.open,
  () => {
    error.value = ''
    copied.value = false
  },
)

async function run(action) {
  busy.value = true
  error.value = ''
  copied.value = false
  try {
    await action()
  } catch (e) {
    error.value = parseApiError(e).message
  } finally {
    busy.value = false
  }
}

const create = () => run(() => store.share(props.build.id))
const stop = () => run(() => store.stopSharing(props.build.id))

async function copy() {
  try {
    await navigator.clipboard.writeText(link.value)
    copied.value = true
  } catch {
    // No clipboard access (an old browser, or permission refused): select the link so it can be copied by hand.
    field.value?.select()
  }
}
</script>

<template>
  <BaseModal :open="open" :title="`Share “${build?.name ?? ''}”`" @close="emit('close')">
    <div class="space-y-4 p-5 text-sm">
      <AlertMessage v-if="error">{{ error }}</AlertMessage>

      <template v-if="build?.share">
        <p class="text-muted">
          Anyone with this link can see this build and copy it into their own builder. They cannot change yours, and the
          page does not say who made it.
        </p>
        <div>
          <label for="share-link" class="mb-1.5 block text-sm font-medium text-ink">Link</label>
          <div class="flex gap-2">
            <input
              id="share-link"
              ref="field"
              :value="link"
              readonly
              class="min-w-0 flex-1 rounded-xl border border-line bg-bg px-4 py-2.5 text-sm text-ink focus:border-accent focus:outline-none"
              @focus="$event.target.select()"
            />
            <BaseButton class="shrink-0" @click="copy">{{ copied ? 'Copied ✓' : 'Copy' }}</BaseButton>
          </div>
          <p class="mt-1.5 text-xs text-muted" aria-live="polite">
            {{
              copied ? 'The link is on your clipboard.' : 'It always shows the build as it is now, at today’s prices.'
            }}
          </p>
        </div>
      </template>

      <p v-else class="text-muted">
        This build is private. Create a link to show it to a friend: they will see its parts and today's prices, and can
        copy it into their own builder. You can switch the link off at any time.
      </p>
    </div>

    <template #footer>
      <div class="flex flex-wrap justify-between gap-3">
        <BaseButton v-if="build?.share" variant="ghost" class="hover:!text-danger" :disabled="busy" @click="stop"
          >Stop sharing</BaseButton
        >
        <span v-else />
        <div class="flex gap-3">
          <BaseButton variant="ghost" @click="emit('close')">{{ build?.share ? 'Done' : 'Cancel' }}</BaseButton>
          <BaseButton v-if="!build?.share" :loading="busy" @click="create">Create a link</BaseButton>
        </div>
      </div>
    </template>
  </BaseModal>
</template>
