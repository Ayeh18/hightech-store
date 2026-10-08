<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import ComponentSelector from '@/components/pc-builder/ComponentSelector.vue'
import ComponentSlotRow from '@/components/pc-builder/ComponentSlotRow.vue'
import SaveBuildDialog from '@/components/pc-builder/SaveBuildDialog.vue'
import BuildSummary from '@/components/pc-builder/BuildSummary.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { parseApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { usePcBuilderStore } from '@/stores/pcBuilder'

const builder = usePcBuilderStore()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const loading = ref(true)
const loadError = ref('')
const choosing = ref(null) // slot key of the open selector
const saveOpen = ref(false)
const saveAsNew = ref(false)
const saveError = ref('')
const saveFieldError = ref('')
const savedName = ref('')

const choosingRow = computed(() => builder.rows.find((row) => row.slot === choosing.value) ?? null)

async function init() {
  loading.value = true
  loadError.value = ''
  try {
    await builder.loadSlots()
    if (route.params.id) {
      await builder.loadBuild(route.params.id)
    } else {
      builder.restoreDraft()
    }
  } catch (e) {
    loadError.value = e.response?.status === 404 ? 'We could not find that saved build.' : parseApiError(e).message
  } finally {
    loading.value = false
  }
}

onMounted(init)
watch(() => route.params.id, init)
onBeforeUnmount(() => (choosing.value = null))

function chooseFor(slot) {
  savedName.value = ''
  choosing.value = slot
}

function onSelect(product) {
  builder.select(choosing.value, product)
  choosing.value = null
}

function requestSave({ asNew = false } = {}) {
  if (!auth.isAuthenticated) {
    // The draft is stored in the browser, so the build is still here after logging in.
    router.push({ name: 'login', query: { redirect: route.fullPath } })
    return
  }
  saveAsNew.value = asNew
  saveError.value = ''
  saveFieldError.value = ''

  if (builder.buildId && !asNew) {
    persist(builder.buildName) // editing: save straight away, the name stays
  } else {
    saveOpen.value = true
  }
}

async function persist(name) {
  saveError.value = ''
  saveFieldError.value = ''
  try {
    if (saveAsNew.value) builder.detach()
    const saved = await builder.save(name)
    saveOpen.value = false
    savedName.value = saved.name
    if (route.name !== 'builder-edit') router.replace({ name: 'builder-edit', params: { id: saved.id } })
  } catch (e) {
    const parsed = parseApiError(e)
    saveFieldError.value = parsed.errors.name?.[0] ?? ''
    saveError.value = saveFieldError.value ? '' : (Object.values(parsed.errors).flat()[0] ?? parsed.message)
    saveOpen.value = true
  }
}

function startOver() {
  builder.clear()
  savedName.value = ''
  if (route.name === 'builder-edit') router.replace({ name: 'builder' })
}
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8 pb-28 sm:px-6 lg:pb-8">
    <header class="mb-6">
      <h1 class="font-display text-3xl font-semibold tracking-tight">Build your PC</h1>
      <p class="mt-1 text-sm text-muted">
        <template v-if="builder.buildId"
          >Editing <span class="font-medium text-ink">{{ builder.buildName }}</span> ·
          <button
            type="button"
            class="text-accent underline-offset-2 hover:underline"
            @click="requestSave({ asNew: true })"
          >
            Save as a new build
          </button>
        </template>
        <template v-else
          >Pick a part for each slot. Your total updates as you go, and nothing is lost if you leave this
          page.</template
        >
      </p>
    </header>

    <div
      v-if="loading"
      class="grid animate-pulse gap-6 lg:grid-cols-[1fr_22rem]"
      aria-busy="true"
      aria-label="Loading the builder"
    >
      <div class="space-y-4"><div v-for="n in 5" :key="n" class="h-28 rounded-card bg-surface" /></div>
      <div class="h-80 rounded-card bg-surface" />
    </div>

    <div v-else-if="loadError" role="alert">
      <EmptyState title="We couldn't open the builder" :text="loadError">
        <div class="flex gap-3">
          <BaseButton @click="init">Try again</BaseButton>
          <RouterLink :to="{ name: 'builder' }"
            ><BaseButton variant="secondary">Start a new build</BaseButton></RouterLink
          >
        </div>
      </EmptyState>
    </div>

    <div v-else-if="!builder.slots.length">
      <EmptyState
        title="The builder isn't set up yet"
        text="No component categories are available right now. Please check back soon."
      />
    </div>

    <div v-else class="grid items-start gap-6 lg:grid-cols-[1fr_22rem]">
      <div class="space-y-4">
        <AlertMessage v-if="savedName" type="success">
          Saved as “{{ savedName }}”.
          <RouterLink :to="{ name: 'my-builds' }" class="font-medium underline">View my builds</RouterLink>
        </AlertMessage>

        <ComponentSlotRow
          v-for="row in builder.rows"
          :key="row.slot"
          :row="row"
          :issues="builder.issuesFor(row.slot)"
          @choose="chooseFor(row.slot)"
          @remove="builder.remove(row.slot)"
          @quantity="builder.setQuantity(row.slot, $event)"
        />
      </div>

      <aside class="lg:sticky lg:top-24" aria-label="Build summary">
        <BuildSummary :build="builder" :is-logged-in="auth.isAuthenticated" @save="requestSave()" @clear="startOver" />
      </aside>
    </div>

    <!-- Small screens: the total stays visible while you scroll through the slots. -->
    <div
      v-if="!loading && builder.slots.length && !builder.isEmpty"
      class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-bg/95 px-4 py-3 backdrop-blur lg:hidden"
    >
      <div class="mx-auto flex max-w-7xl items-center justify-between gap-4">
        <div>
          <p class="text-xs text-muted">
            {{ builder.requiredFilled }}/{{ builder.requiredSlots.length }} required parts
          </p>
          <p class="font-display text-xl font-bold tabular-nums">{{ builder.totalFormatted }}</p>
        </div>
        <BaseButton :loading="builder.saving" @click="requestSave()">{{
          builder.buildId ? 'Save changes' : auth.isAuthenticated ? 'Save build' : 'Log in to save'
        }}</BaseButton>
      </div>
    </div>

    <ComponentSelector :open="choosing !== null" :row="choosingRow" @close="choosing = null" @select="onSelect" />

    <SaveBuildDialog
      :open="saveOpen"
      :loading="builder.saving"
      :initial-name="saveAsNew ? `${builder.buildName} (copy)`.trim() : builder.buildName"
      :error="saveError"
      :field-error="saveFieldError"
      @close="saveOpen = false"
      @confirm="persist"
    />
  </div>
</template>
