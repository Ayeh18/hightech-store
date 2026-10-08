<script setup>
import { computed } from 'vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { formatSen } from '@/lib/money'
import BuildProgress from './BuildProgress.vue'

/*
 * The right-hand panel: progress, an itemised price table, the total, problems and the main actions.
 * `build` is the pcBuilder store, so the total here always matches the rows on the left.
 */
const props = defineProps({
  build: { type: Object, required: true },
  isLoggedIn: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'clear'])

const lines = computed(() => props.build.rows.filter((row) => row.selected))
const lineTotal = (row) => formatSen(row.selected.product.effective_price.amount * row.selected.quantity)
// Only problems without a slot are listed here; slot problems are shown on their own row.
const generalIssues = computed(() => props.build.issues.filter((issue) => !issue.slot))
const blockingCount = computed(() => props.build.issues.filter((i) => i.severity === 'error').length)
</script>

<template>
  <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="summary-heading">
    <h2 id="summary-heading" class="font-display text-lg font-semibold">Build summary</h2>

    <div class="mt-4"><BuildProgress :filled="build.requiredFilled" :total="build.requiredSlots.length" /></div>

    <p v-if="build.missingRequired.length && !build.isEmpty" class="mt-3 text-xs text-muted">
      Still needed: {{ build.missingRequired.join(', ') }}
    </p>

    <div
      v-if="!lines.length"
      class="mt-6 rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-muted"
    >
      Choose your first part and the total will appear here.
    </div>

    <table v-else class="mt-5 w-full text-sm">
      <caption class="sr-only">
        Selected parts and prices
      </caption>
      <tbody class="divide-y divide-line">
        <tr v-for="row in lines" :key="row.slot">
          <th scope="row" class="py-2.5 pr-3 text-left align-top font-normal text-muted">
            {{ row.label
            }}<span v-if="row.selected.quantity > 1" class="ml-1 text-xs">×{{ row.selected.quantity }}</span>
          </th>
          <td class="py-2.5 text-right align-top font-medium tabular-nums">{{ lineTotal(row) }}</td>
        </tr>
      </tbody>
    </table>

    <div class="mt-4 flex items-end justify-between border-t border-line pt-4">
      <span class="text-sm text-muted">Estimated total</span>
      <span class="font-display text-3xl font-bold tracking-tight tabular-nums" data-testid="total">{{
        build.totalFormatted
      }}</span>
    </div>

    <p
      class="mt-1.5 flex items-center justify-end gap-1.5 text-xs"
      :class="build.verified ? 'text-success' : 'text-muted'"
      aria-live="polite"
    >
      <template v-if="build.isEmpty" />
      <template v-else-if="build.validateError"><span aria-hidden="true">⚠</span> Could not verify prices</template>
      <template v-else-if="build.verified"><span aria-hidden="true">✓</span> Price confirmed by the store</template>
      <template v-else>Confirming prices…</template>
    </p>

    <div class="mt-4 space-y-2.5" aria-live="polite">
      <AlertMessage v-if="build.pricesChanged" type="warning"
        >Some prices changed since you added them. The total above is up to date.</AlertMessage
      >
      <AlertMessage v-if="build.validateError" type="error">{{ build.validateError }}</AlertMessage>
      <AlertMessage
        v-for="issue in generalIssues"
        :key="issue.code + issue.message"
        :type="issue.severity === 'error' ? 'error' : 'warning'"
        >{{ issue.message }}</AlertMessage
      >
      <AlertMessage v-if="blockingCount && !generalIssues.length" type="error">
        {{ blockingCount === 1 ? '1 part needs your attention' : `${blockingCount} parts need your attention` }} before
        you can buy this build.
      </AlertMessage>
    </div>

    <div class="mt-5 space-y-2.5">
      <BaseButton block :disabled="build.isEmpty" :loading="build.saving" @click="emit('save')">
        {{ build.buildId ? 'Save changes' : isLoggedIn ? 'Save build' : 'Log in to save' }}
      </BaseButton>
      <BaseButton block variant="ghost" :disabled="build.isEmpty" @click="emit('clear')">Start over</BaseButton>
    </div>
  </section>
</template>
