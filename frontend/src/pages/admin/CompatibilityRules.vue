<script setup>
import { onMounted, ref } from 'vue'
import AdminPage from '@/components/admin/AdminPage.vue'
import FormCheckbox from '@/components/admin/FormCheckbox.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { api, parseApiError } from '@/lib/api'
import { firstError } from '@/lib/errors'

/*
 * The compatibility rules behind the PC builder, tunable without a deployment: switch a rule off, make it a warning instead
 * of a blocker, or change its numbers (such as how much spare power a PSU needs). Each change is saved when you press Save on
 * that rule, and the server writes it to the audit log.
 */
const rules = ref([])
const loading = ref(true)
const error = ref('')
const drafts = ref({}) // rule key -> { is_enabled, severity, params: { name: text } }
const saving = ref('')
const messages = ref({}) // rule key -> { ok?, error?, fields? }

const SEVERITIES = [
  { value: 'error', label: 'Blocks the build' },
  { value: 'warning', label: 'Only a warning' },
]

function draftOf(rule) {
  return {
    is_enabled: rule.is_enabled,
    severity: rule.severity,
    params: Object.fromEntries(Object.entries(rule.params).map(([k, v]) => [k, String(v)])),
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    rules.value = (await api.get('/api/compatibility-rules')).data.data
    drafts.value = Object.fromEntries(rules.value.map((r) => [r.key, draftOf(r)]))
  } catch (e) {
    error.value = firstError(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

const changed = (rule) => JSON.stringify(drafts.value[rule.key]) !== JSON.stringify(draftOf(rule))

async function save(rule) {
  saving.value = rule.key
  messages.value = { ...messages.value, [rule.key]: {} }
  const draft = drafts.value[rule.key]
  try {
    const params = Object.fromEntries(Object.entries(draft.params).map(([k, v]) => [k, Number(v)]))
    const { data } = await api.put(`/api/compatibility-rules/${rule.key}`, {
      is_enabled: draft.is_enabled,
      severity: draft.severity,
      params,
    })
    rules.value = rules.value.map((r) => (r.key === rule.key ? data.data : r))
    drafts.value = { ...drafts.value, [rule.key]: draftOf(data.data) }
    messages.value = { ...messages.value, [rule.key]: { ok: 'Saved.' } }
  } catch (e) {
    const parsed = parseApiError(e)
    messages.value = {
      ...messages.value,
      [rule.key]: { error: Object.keys(parsed.errors).length ? '' : parsed.message, fields: parsed.errors },
    }
  } finally {
    saving.value = ''
  }
}

const reset = (rule) =>
  (drafts.value[rule.key] = {
    ...draftOf(rule),
    severity: rule.default_severity,
    is_enabled: true,
    params: Object.fromEntries(Object.entries(rule.default_params).map(([k, v]) => [k, String(v)])),
  })
</script>

<template>
  <AdminPage
    title="Compatibility rules"
    subtitle="What the PC builder checks, and how strictly. Changes apply to new builds straight away."
  >
    <AlertMessage v-if="error"
      >{{ error }} <button type="button" class="underline" @click="load">Try again</button></AlertMessage
    >
    <div
      v-else-if="loading"
      class="h-64 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading the rules"
    />

    <ul v-else class="space-y-3" data-testid="rules">
      <li
        v-for="rule in rules"
        :key="rule.key"
        class="rounded-card border border-line bg-surface p-5"
        :data-testid="`rule-${rule.key}`"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 max-w-2xl">
            <h2 class="font-display text-base font-semibold">{{ rule.name }}</h2>
            <p class="mt-1 text-sm text-muted">{{ rule.description }}</p>
          </div>
          <FormCheckbox v-model="drafts[rule.key].is_enabled" label="Checked" />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label :for="`sev-${rule.key}`" class="mb-1.5 block text-sm font-medium">When it fails</label>
            <select
              :id="`sev-${rule.key}`"
              v-model="drafts[rule.key].severity"
              class="w-full rounded-xl border border-line bg-bg px-3 py-2.5 text-sm focus:border-accent focus:outline-none"
            >
              <option v-for="option in SEVERITIES" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
          <BaseInput
            v-for="(_, name) in drafts[rule.key].params"
            :key="name"
            v-model="drafts[rule.key].params[name]"
            :label="name.replaceAll('_', ' ')"
            :error="messages[rule.key]?.fields?.[`params.${name}`]?.[0] ?? ''"
          />
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
          <BaseButton
            variant="secondary"
            class="!px-4 !py-2"
            :loading="saving === rule.key"
            :disabled="!changed(rule)"
            @click="save(rule)"
            >Save</BaseButton
          >
          <button type="button" class="text-sm text-muted hover:text-ink" @click="reset(rule)">
            Back to the defaults
          </button>
          <span v-if="messages[rule.key]?.ok" class="text-sm text-success" role="status">{{
            messages[rule.key].ok
          }}</span>
          <span v-if="messages[rule.key]?.error" class="text-sm text-danger" role="alert">{{
            messages[rule.key].error
          }}</span>
        </div>
      </li>
    </ul>
  </AdminPage>
</template>
