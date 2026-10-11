<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import CompatibilityStatus from '@/components/pc-builder/CompatibilityStatus.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { api, parseApiError } from '@/lib/api'
import { usePcBuilderStore } from '@/stores/pcBuilder'

/*
 * A build someone shared, opened from its link. Read-only: the visitor can look, and copy it into their own builder.
 * Everything shown (parts, prices, stock, compatibility) is the shop's answer for right now, not what the owner saw.
 */
const route = useRoute()
const router = useRouter()
const builder = usePcBuilderStore()

const build = ref(null)
const loading = ref(true)
const gone = ref(false)
const error = ref('')

const available = computed(() => build.value?.items.filter((item) => item.product) ?? [])
const unavailable = computed(() => (build.value?.items.length ?? 0) - available.value.length)
const slotLabels = computed(() => Object.fromEntries((build.value?.items ?? []).map((i) => [i.slot, i.slot_label])))

async function load() {
  loading.value = true
  gone.value = false
  error.value = ''
  build.value = null
  try {
    build.value = (await api.get(`/api/shared-builds/${route.params.token}`)).data.data
  } catch (e) {
    if (e.response?.status === 404) gone.value = true
    else error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => route.params.token, load)

// A copy for the visitor: it lands in their own builder as a new, unsaved build. The shared build is not touched.
function openInBuilder() {
  builder.applyBuild(build.value)
  router.push({ name: 'builder' })
}
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
    <div v-if="loading" class="animate-pulse space-y-4" aria-busy="true" aria-label="Loading the shared build">
      <div class="h-9 w-2/3 rounded bg-surface" />
      <div class="h-72 rounded-card bg-surface" />
    </div>

    <EmptyState
      v-else-if="gone"
      title="This link is no longer available"
      text="The build was deleted, or its owner stopped sharing it. You can still build your own."
    >
      <RouterLink :to="{ name: 'builder' }"><BaseButton>Open the PC builder</BaseButton></RouterLink>
    </EmptyState>

    <div v-else-if="error" role="alert">
      <EmptyState title="We couldn't open this build" :text="error">
        <BaseButton @click="load">Try again</BaseButton>
      </EmptyState>
    </div>

    <template v-else>
      <header class="mb-6">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted">A shared PC build</p>
        <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight">{{ build.name }}</h1>
        <p class="mt-1 text-sm text-muted">Prices and stock are shown as they are right now.</p>
      </header>

      <div class="space-y-4">
        <AlertMessage v-if="unavailable > 0" type="warning">
          {{ unavailable === 1 ? 'One part' : `${unavailable} parts` }} of this build
          {{ unavailable === 1 ? 'is' : 'are' }} no longer sold here, so it is not complete any more.
        </AlertMessage>

        <CompatibilityStatus
          :status="build.compatibility.status"
          :issues="build.compatibility.issues"
          :metrics="build.compatibility.metrics"
          :part-count="available.length"
          :slot-labels="slotLabels"
        />

        <ul class="divide-y divide-line rounded-card border border-line bg-surface" data-testid="shared-parts">
          <li
            v-for="item in build.items"
            :key="item.slot"
            class="flex items-center justify-between gap-4 p-4"
            :data-slot="item.slot"
          >
            <div class="min-w-0">
              <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ item.slot_label }}</p>
              <template v-if="item.product">
                <RouterLink
                  :to="{ name: 'product', params: { slug: item.product.slug } }"
                  class="mt-0.5 block font-medium hover:text-accent"
                  >{{ item.product.name }}</RouterLink
                >
                <p v-if="!item.purchasable" class="mt-0.5 text-xs text-danger">Out of stock right now</p>
              </template>
              <p v-else class="mt-0.5 text-muted">No longer available</p>
            </div>
            <p v-if="item.product" class="shrink-0 text-right font-semibold tabular-nums">
              <span v-if="item.quantity > 1" class="mr-1 text-xs font-normal text-muted">{{ item.quantity }} ×</span>
              {{ item.line_total.formatted }}
            </p>
          </li>
        </ul>

        <div class="flex flex-wrap items-center justify-between gap-4 rounded-card border border-line bg-surface p-5">
          <div>
            <p class="text-sm text-muted">Total today</p>
            <p class="font-display text-3xl font-bold tabular-nums" data-testid="shared-total">
              {{ build.subtotal.formatted }}
            </p>
          </div>
          <BaseButton :disabled="!available.length" @click="openInBuilder">Open in the builder</BaseButton>
        </div>
        <p class="text-xs text-muted">
          Opening it makes your own copy. You can change any part, save it or buy it; the original is not affected.
        </p>
      </div>
    </template>
  </div>
</template>
