<script setup>
import { computed, ref, watch } from 'vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { api, parseApiError } from '@/lib/api'
import { parseRinggit } from '@/lib/money'

/*
 * "Suggest a build for me": ask for a budget and what the PC is for, show the build the shop suggests with a reason for
 * each part, and hand it to the builder if the customer wants it.
 *
 * The browser decides nothing here. The choices on the form come from the API, and so does the build: its parts, its
 * prices, whether it is compatible and whether it fits the budget. This component only shows what came back.
 */
const props = defineProps({
  open: { type: Boolean, required: true },
  // How many parts the builder holds now, so we can warn before replacing them.
  currentParts: { type: Number, default: 0 },
})
const emit = defineEmits(['close', 'use'])

const options = ref(null)
const optionsError = ref('')

const budget = ref('')
const purpose = ref('gaming')
const priority = ref('balanced')
const resolution = ref('1080p')
const games = ref('')

const loading = ref(false)
const error = ref('')
const fieldErrors = ref({})
const result = ref(null)

// A screen only matters when the PC has a graphics card to drive it: not for an office PC.
const asksForScreen = computed(() => purpose.value !== 'work')
const gameTitles = computed(() =>
  games.value
    .split(',')
    .map((title) => title.trim())
    .filter(Boolean),
)
const reasons = computed(() => Object.fromEntries((result.value?.reasons ?? []).map((r) => [r.slot, r.reason])))
const warnings = computed(() => result.value?.build.compatibility.issues ?? [])

async function loadOptions() {
  if (options.value) return
  optionsError.value = ''
  try {
    options.value = (await api.get('/api/pc-builder/recommendations/options')).data.data
  } catch (e) {
    optionsError.value = parseApiError(e).message
  }
}

watch(
  () => props.open,
  (open) => {
    if (!open) return
    result.value = null
    error.value = ''
    fieldErrors.value = {}
    loadOptions()
  },
  { immediate: true },
)

async function suggest() {
  error.value = ''
  fieldErrors.value = {}

  const sen = parseRinggit(budget.value)
  if (sen === null) {
    fieldErrors.value = { budget: 'Enter your budget in ringgit, for example 4000.' }
    return
  }
  if (gameTitles.value.length > options.value.max_games) {
    fieldErrors.value = { games: `Name at most ${options.value.max_games} games.` }
    return
  }

  loading.value = true
  try {
    const body = { budget: sen, purpose: purpose.value, priority: priority.value }
    if (asksForScreen.value) body.resolution = resolution.value
    if (options.value.uses_ai && gameTitles.value.length) body.games = gameTitles.value
    result.value = (await api.post('/api/pc-builder/recommendations', body)).data.data
  } catch (e) {
    const parsed = parseApiError(e)
    const first = (field) => Object.entries(parsed.errors).find(([key]) => key === field || key.startsWith(`${field}.`))
    fieldErrors.value = {
      budget: first('budget')?.[1][0] ?? '',
      games: first('games')?.[1][0] ?? '',
    }
    if (!fieldErrors.value.budget && !fieldErrors.value.games) error.value = parsed.message
  } finally {
    loading.value = false
  }
}

function useBuild() {
  // Only for the shop's own statistics: if it fails, the customer still gets their build.
  api.post(`/api/pc-builder/recommendations/${result.value.id}/accept`).catch(() => {})
  emit('use', result.value.build)
}

const choice =
  'flex cursor-pointer items-center justify-center rounded-xl border px-3 py-2.5 text-center text-sm transition has-[:checked]:border-accent has-[:checked]:bg-accent-soft has-[:checked]:text-ink has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-accent'
</script>

<template>
  <BaseModal
    :open="open"
    :title="result ? 'Suggested build' : 'Suggest a build for me'"
    size="lg"
    @close="emit('close')"
  >
    <!-- The brief -->
    <form v-if="!result" id="recommend-form" class="space-y-5 p-5" novalidate @submit.prevent="suggest">
      <AlertMessage v-if="optionsError">{{ optionsError }}</AlertMessage>
      <AlertMessage v-if="error">{{ error }}</AlertMessage>

      <div v-if="!options && !optionsError" class="h-64 animate-pulse rounded-xl bg-elevated" aria-busy="true" />

      <template v-if="options">
        <div>
          <BaseInput
            v-model="budget"
            label="Your budget (RM)"
            placeholder="4000"
            data-autofocus
            required
            :error="fieldErrors.budget"
          />
          <p class="mt-1.5 text-xs text-muted">
            For the PC itself. From {{ options.budget.min.formatted }} to {{ options.budget.max.formatted }}.
          </p>
        </div>

        <fieldset>
          <legend class="mb-1.5 text-sm font-medium text-ink">What is it for?</legend>
          <div class="grid gap-2 sm:grid-cols-3">
            <label v-for="option in options.purposes" :key="option.value" :class="[choice, 'border-line text-muted']">
              <input v-model="purpose" type="radio" name="purpose" :value="option.value" class="sr-only" />
              {{ option.label }}
            </label>
          </div>
        </fieldset>

        <fieldset v-if="asksForScreen">
          <legend class="mb-1.5 text-sm font-medium text-ink">Your screen</legend>
          <div class="grid grid-cols-3 gap-2">
            <label
              v-for="option in options.resolutions"
              :key="option.value"
              :class="[choice, 'border-line text-muted']"
            >
              <input v-model="resolution" type="radio" name="resolution" :value="option.value" class="sr-only" />
              {{ option.label }}
            </label>
          </div>
        </fieldset>

        <fieldset>
          <legend class="mb-1.5 text-sm font-medium text-ink">What matters most?</legend>
          <div class="grid grid-cols-3 gap-2">
            <label v-for="option in options.priorities" :key="option.value" :class="[choice, 'border-line text-muted']">
              <input v-model="priority" type="radio" name="priority" :value="option.value" class="sr-only" />
              {{ option.label }}
            </label>
          </div>
        </fieldset>

        <div v-if="options.uses_ai && purpose === 'gaming'">
          <BaseInput
            v-model="games"
            label="Games you play (optional)"
            placeholder="Valorant, Cyberpunk 2077"
            :error="fieldErrors.games"
          />
          <p class="mt-1.5 text-xs text-muted">Up to {{ options.max_games }}, separated by commas.</p>
        </div>
      </template>
    </form>

    <!-- The suggestion -->
    <div v-else class="space-y-4 p-5" data-testid="recommendation">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-muted">
            {{ result.brief.purpose.label }} · {{ result.brief.priority.label }}
          </p>
          <p class="mt-1 font-display text-3xl font-semibold tabular-nums" data-testid="recommendation-total">
            {{ result.build.subtotal.formatted }}
          </p>
          <p class="text-sm text-muted">
            of your {{ result.brief.budget.formatted }} budget<template v-if="result.remaining.amount > 0">
              · {{ result.remaining.formatted }} left</template
            >
          </p>
        </div>
        <span
          class="rounded-full border border-line bg-elevated px-3 py-1 text-xs font-medium text-muted"
          data-testid="recommendation-source"
        >
          {{ result.source === 'ai' ? 'Chosen by AI, checked by the store' : "Chosen by the store's rules" }}
        </span>
      </div>

      <ul class="divide-y divide-line rounded-xl border border-line">
        <li v-for="item in result.build.items" :key="item.slot" class="p-3.5" :data-slot="item.slot">
          <div class="flex items-baseline justify-between gap-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ item.slot_label }}</p>
            <p class="shrink-0 text-sm font-semibold tabular-nums">{{ item.line_total.formatted }}</p>
          </div>
          <p class="mt-0.5 font-medium">{{ item.product.name }}</p>
          <p v-if="reasons[item.slot]" class="mt-1 text-sm text-muted">{{ reasons[item.slot] }}</p>
        </li>
      </ul>

      <ul v-if="result.notes.length" class="space-y-1.5 text-sm text-muted" data-testid="recommendation-notes">
        <li v-for="note in result.notes" :key="note" class="flex gap-2"><span aria-hidden="true">•</span>{{ note }}</li>
      </ul>

      <AlertMessage v-if="warnings.length" type="warning">
        <p class="font-medium">Worth knowing</p>
        <ul class="mt-1 list-disc pl-5">
          <li v-for="issue in warnings" :key="issue.rule">{{ issue.message }}</li>
        </ul>
      </AlertMessage>
      <p v-else class="text-sm text-success">✓ Every part fits together, checked by the store.</p>

      <p v-if="currentParts > 0" class="text-sm text-warning" data-testid="replace-warning">
        Using this build replaces the {{ currentParts }} {{ currentParts === 1 ? 'part' : 'parts' }} you have chosen.
      </p>
    </div>

    <template #footer>
      <div v-if="!result" class="flex justify-end gap-3">
        <BaseButton variant="ghost" @click="emit('close')">Cancel</BaseButton>
        <BaseButton type="submit" form="recommend-form" :loading="loading" :disabled="!options || !budget.trim()">
          Suggest a build
        </BaseButton>
      </div>
      <div v-else class="flex flex-wrap justify-end gap-3">
        <BaseButton variant="ghost" @click="result = null">Change my answers</BaseButton>
        <BaseButton @click="useBuild">Use this build</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
