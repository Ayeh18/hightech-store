<script setup>
import BaseButton from '@/components/ui/BaseButton.vue'
import { shopDate } from '@/lib/format'

/**
 * The "from ... to ..." boxes above a report, with shortcuts for the last 7, 30 and 90 days. The dates are the shop's
 * own days ("2026-10-20"). `submit` means "show this period": the page checks the dates and asks the API.
 */
const from = defineModel('from', { type: String, required: true })
const to = defineModel('to', { type: String, required: true })
defineProps({
  loading: { type: Boolean, default: false },
  action: { type: String, default: 'Show report' },
})
const emit = defineEmits(['submit'])

function lastDays(days) {
  from.value = shopDate(days - 1)
  to.value = shopDate(0)
  emit('submit')
}

const box = 'rounded-xl border border-line bg-bg px-3 py-2.5 text-sm focus:border-accent focus:outline-none'
</script>

<template>
  <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="emit('submit')">
    <div>
      <label for="period-from" class="mb-1.5 block text-sm font-medium">From</label>
      <input id="period-from" v-model="from" type="date" :class="box" />
    </div>
    <div>
      <label for="period-to" class="mb-1.5 block text-sm font-medium">To</label>
      <input id="period-to" v-model="to" type="date" :class="box" />
    </div>
    <BaseButton type="submit" :loading="loading">{{ action }}</BaseButton>
    <div class="flex gap-1 text-sm">
      <button
        v-for="days in [7, 30, 90]"
        :key="days"
        type="button"
        class="rounded-lg px-2.5 py-2 text-muted hover:bg-elevated hover:text-ink"
        @click="lastDays(days)"
      >
        Last {{ days }} days
      </button>
    </div>
  </form>
</template>
