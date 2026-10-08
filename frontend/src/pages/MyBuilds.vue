<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import SaveBuildDialog from '@/components/pc-builder/SaveBuildDialog.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { parseApiError } from '@/lib/api'
import { useBuildsStore } from '@/stores/builds'
import { useCartStore } from '@/stores/cart'
import { usePcBuilderStore } from '@/stores/pcBuilder'

const store = useBuildsStore()
const builder = usePcBuilderStore()
const router = useRouter()

const renaming = ref(null)
const deleting = ref(null)
const busy = ref(false)
const actionError = ref('')
const cart = useCartStore()
const addingId = ref(null) // the build being added to the cart
const cartAdded = ref('') // its name, for the confirmation
const cartProblem = ref({ name: '', reasons: [] })
const renameError = ref('')

onMounted(() => store.fetchBuilds())

const tones = {
  ok: 'bg-success/15 text-success',
  warning: 'bg-warning/15 text-warning',
  error: 'bg-danger/15 text-danger',
}

/** One word that tells the customer what state a saved build is in. The server decides all of it. */
function badge(build) {
  if (build.compatibility_status === 'incompatible') return { label: 'Incompatible', tone: 'error' }
  if (!build.complete) return { label: 'Incomplete', tone: 'warning' }
  if (!build.purchasable) return { label: 'Needs attention', tone: 'error' }
  if (build.compatibility_status === 'warning') return { label: 'Has warnings', tone: 'warning' }
  return { label: 'Ready to buy', tone: 'ok' }
}

const when = (iso) =>
  new Intl.DateTimeFormat('en-MY', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))

function edit(build) {
  // Opening a saved build replaces any unsaved draft, so start from a clean state.
  builder.clear()
  router.push({ name: 'builder-edit', params: { id: build.id } })
}

async function run(action) {
  busy.value = true
  actionError.value = ''
  try {
    await action()
  } catch (e) {
    actionError.value = parseApiError(e).message
  } finally {
    busy.value = false
  }
}

const duplicate = (build) => run(() => store.duplicate(build.id))

/** Add a saved PC to the cart. The server checks it again (complete, in stock, compatible) and explains any refusal. */
async function addToCart(build) {
  cartAdded.value = ''
  cartProblem.value = { name: '', reasons: [] }
  addingId.value = build.id
  try {
    await cart.addBuild({ buildId: build.id })
    cartAdded.value = build.name
  } catch (e) {
    const parsed = parseApiError(e)
    cartProblem.value = {
      name: build.name,
      reasons: parsed.errors.build ?? [Object.values(parsed.errors).flat()[0] ?? parsed.message],
    }
  } finally {
    addingId.value = null
  }
}

async function confirmRename(name) {
  renameError.value = ''
  busy.value = true
  try {
    await store.rename(renaming.value.id, name)
    renaming.value = null
  } catch (e) {
    const parsed = parseApiError(e)
    renameError.value = parsed.errors.name?.[0] ?? parsed.message
  } finally {
    busy.value = false
  }
}

async function confirmDelete() {
  await run(() => store.remove(deleting.value.id))
  if (!actionError.value) deleting.value = null
}
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight">My builds</h1>
        <p class="mt-1 text-sm text-muted">Your saved PCs. Prices and stock are always shown as they are today.</p>
      </div>
      <RouterLink :to="{ name: 'builder' }" @click="builder.clear()"><BaseButton>New build</BaseButton></RouterLink>
    </header>

    <AlertMessage v-if="actionError" class="mb-4">{{ actionError }}</AlertMessage>
    <AlertMessage v-if="cartAdded" type="success" class="mb-4">
      “{{ cartAdded }}” was added to your cart.
      <RouterLink :to="{ name: 'cart' }" class="font-medium underline">View cart</RouterLink>
    </AlertMessage>
    <AlertMessage v-if="cartProblem.reasons.length" class="mb-4">
      <p class="font-medium">“{{ cartProblem.name }}” can't be added to your cart yet:</p>
      <ul class="mt-1 list-disc space-y-0.5 pl-5">
        <li v-for="reason in cartProblem.reasons" :key="reason">{{ reason }}</li>
      </ul>
    </AlertMessage>

    <ul
      v-if="store.loading && !store.loaded"
      class="grid animate-pulse gap-4 sm:grid-cols-2 xl:grid-cols-3"
      aria-busy="true"
      aria-label="Loading your builds"
    >
      <li v-for="n in 3" :key="n" class="h-56 rounded-card border border-line bg-surface" />
    </ul>

    <div v-else-if="store.error" role="alert">
      <EmptyState title="We couldn't load your builds" :text="store.error"
        ><BaseButton @click="store.fetchBuilds()">Try again</BaseButton></EmptyState
      >
    </div>

    <EmptyState
      v-else-if="!store.builds.length"
      title="No saved builds yet"
      text="Build a PC, save it, and it will wait for you here."
    >
      <RouterLink :to="{ name: 'builder' }"><BaseButton>Start building</BaseButton></RouterLink>
    </EmptyState>

    <ul v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <li
        v-for="build in store.builds"
        :key="build.id"
        class="flex flex-col rounded-card border border-line bg-surface p-5"
      >
        <div class="flex items-start justify-between gap-3">
          <h2 class="font-display text-lg font-semibold leading-snug">{{ build.name }}</h2>
          <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="tones[badge(build).tone]">
            {{ badge(build).label }}
          </span>
        </div>

        <ul class="mt-3 flex-1 space-y-1 text-sm text-muted">
          <li v-for="part in build.parts.slice(0, 4)" :key="part.slot" class="truncate">
            <span class="uppercase">{{ part.slot }}</span> · {{ part.name }}
          </li>
          <li v-if="build.parts.length > 4" class="text-xs">+ {{ build.parts.length - 4 }} more</li>
        </ul>

        <div class="mt-4 flex items-end justify-between border-t border-line pt-4">
          <div>
            <p class="font-display text-xl font-bold tabular-nums">{{ build.subtotal.formatted }}</p>
            <p class="text-xs text-muted">Edited {{ when(build.updated_at) }}</p>
          </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
          <BaseButton
            class="!px-4 !py-2"
            :disabled="!build.purchasable || addingId !== null"
            :loading="addingId === build.id"
            :title="build.purchasable ? '' : 'Complete the build and fix any problems to add it to your cart.'"
            @click="addToCart(build)"
            >Add to cart</BaseButton
          >
          <BaseButton variant="secondary" class="!px-4 !py-2" @click="edit(build)">Edit</BaseButton>
          <BaseButton variant="secondary" class="!px-4 !py-2" :disabled="busy" @click="duplicate(build)"
            >Duplicate</BaseButton
          >
          <BaseButton variant="ghost" class="!px-3 !py-2" @click="((renameError = ''), (renaming = build))"
            >Rename</BaseButton
          >
          <BaseButton
            variant="ghost"
            class="!px-3 !py-2 hover:!text-danger"
            @click="((actionError = ''), (deleting = build))"
            >Delete</BaseButton
          >
        </div>
      </li>
    </ul>

    <SaveBuildDialog
      :open="renaming !== null"
      title="Rename build"
      confirm-label="Rename"
      :initial-name="renaming?.name ?? ''"
      :loading="busy"
      :field-error="renameError"
      @close="renaming = null"
      @confirm="confirmRename"
    />

    <BaseModal :open="deleting !== null" title="Delete this build?" @close="deleting = null">
      <div class="space-y-3 p-5 text-sm">
        <AlertMessage v-if="actionError">{{ actionError }}</AlertMessage>
        <p>
          <span class="font-semibold">{{ deleting?.name }}</span> will be deleted permanently. The parts themselves are
          not affected.
        </p>
      </div>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="deleting = null">Keep it</BaseButton>
          <BaseButton class="!bg-danger" :loading="busy" @click="confirmDelete">Delete build</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
