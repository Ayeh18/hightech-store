<script setup>
import { computed, useId } from 'vue'

/*
 * "✓ All components are compatible" / "⚠ Warnings" / "✗ Compatibility issues found", with the reasons.
 * The verdict always comes from the server; this component only displays it.
 */
const props = defineProps({
  status: { type: String, default: null }, // compatible | warning | incompatible | null (not checked yet)
  issues: { type: Array, default: () => [] },
  metrics: { type: Object, default: null },
  partCount: { type: Number, default: 0 },
  stale: { type: Boolean, default: false },
  slotLabels: { type: Object, default: () => ({}) }, // { cpu: 'CPU', ... }
})

const headingId = useId()
const errors = computed(() => props.issues.filter((i) => i.severity === 'error'))

const view = computed(() => {
  if (props.partCount === 0)
    return {
      tone: 'idle',
      icon: '·',
      title: 'Compatibility',
      text: 'Add parts and we will check that they work together.',
    }
  if (!props.status) return { tone: 'idle', icon: '…', title: 'Checking compatibility', text: 'Checking your parts…' }
  if (props.status === 'incompatible') {
    return {
      tone: 'error',
      icon: '✗',
      title: 'Compatibility issues found',
      text: `${errors.value.length} ${errors.value.length === 1 ? 'problem' : 'problems'} to fix before you can buy this build.`,
    }
  }
  if (props.status === 'warning') {
    return {
      tone: 'warning',
      icon: '⚠',
      title: 'Compatible, with warnings',
      text: 'Nothing is broken, but a few things are worth a look.',
    }
  }
  return props.partCount < 2
    ? {
        tone: 'ok',
        icon: '✓',
        title: 'No conflicts so far',
        text: 'Add more parts and we will check them against each other.',
      }
    : { tone: 'ok', icon: '✓', title: 'All components are compatible', text: 'Everything you picked works together.' }
})

const tones = {
  idle: 'border-line bg-surface text-muted',
  ok: 'border-success/40 bg-success/10 text-success',
  warning: 'border-warning/40 bg-warning/10 text-warning',
  error: 'border-danger/40 bg-danger/10 text-danger',
}

const labels = (slots) => slots.map((s) => props.slotLabels[s] ?? s).join(' · ')
</script>

<template>
  <section
    class="rounded-card border p-5 transition"
    :class="[tones[view.tone], stale ? 'opacity-70' : '']"
    :aria-labelledby="headingId"
    aria-live="polite"
    data-testid="compatibility"
  >
    <div class="flex items-start gap-3">
      <span
        class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full border border-current text-sm font-bold"
        aria-hidden="true"
        >{{ view.icon }}</span
      >
      <div class="min-w-0">
        <h2 :id="headingId" class="font-display text-lg font-semibold text-ink">{{ view.title }}</h2>
        <p class="mt-0.5 text-sm" :class="view.tone === 'idle' ? 'text-muted' : 'text-ink/80'">
          {{ view.text }}<span v-if="stale" class="ml-1 text-xs text-muted">Updating…</span>
        </p>
      </div>
    </div>

    <!-- Capped in height (and keyboard-scrollable) so the build summary below always stays within reach. -->
    <ol
      v-if="issues.length"
      class="mt-4 max-h-64 space-y-3 overflow-y-auto pr-1 focus-visible:outline-2 focus-visible:outline-accent"
      tabindex="0"
      aria-label="Compatibility problems"
    >
      <li
        v-for="(issue, index) in issues"
        :key="issue.rule + index"
        class="rounded-xl border border-line bg-bg/60 p-3 text-sm"
      >
        <p class="flex items-start gap-2 text-ink">
          <span :class="issue.severity === 'error' ? 'text-danger' : 'text-warning'" aria-hidden="true">{{
            issue.severity === 'error' ? '✗' : '⚠'
          }}</span>
          <span
            ><span class="sr-only">{{ issue.severity === 'error' ? 'Problem: ' : 'Warning: ' }}</span
            >{{ issue.message }}</span
          >
        </p>
        <p class="mt-1.5 pl-6 text-xs text-muted">{{ labels(issue.slots) }}</p>
      </li>
    </ol>

    <dl
      v-if="metrics?.estimated_power_watts"
      class="mt-4 grid grid-cols-2 gap-3 border-t border-line pt-4 text-sm text-ink"
    >
      <div>
        <dt class="text-xs text-muted">Estimated power draw</dt>
        <dd class="font-semibold tabular-nums">{{ metrics.estimated_power_watts }} W</dd>
      </div>
      <div>
        <dt class="text-xs text-muted">Recommended PSU</dt>
        <dd class="font-semibold tabular-nums">{{ metrics.recommended_psu_watts }} W+</dd>
      </div>
    </dl>
  </section>
</template>
