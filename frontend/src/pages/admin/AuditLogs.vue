<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useAdminList } from '@/composables/useAdminList'
import { api } from '@/lib/api'
import { humanizeAction, whenFull } from '@/lib/format'

const list = useAdminList('/api/admin/audit-logs', { action: '', q: '', subject: '', from: '', to: '' })
const actions = ref([])

// The kinds of thing that get audited. (The API accepts any plain word here.)
const SUBJECTS = [
  'Order',
  'Payment',
  'Refund',
  'Product',
  'User',
  'Coupon',
  'Brand',
  'Category',
  'CompatibilityRule',
  'SpecDefinition',
].map((v) => ({ value: v, label: v.replace(/([a-z])([A-Z])/g, '$1 $2') }))

onMounted(async () => {
  try {
    actions.value = (await api.get('/api/admin/audit-logs/actions')).data.data.map((a) => ({
      value: a,
      label: humanizeAction(a),
    }))
  } catch {
    /* the list still works without the action filter */
  }
})

/** Where to look at the thing an entry is about, when there is a page for it. */
function subjectLink(subject) {
  if (!subject) return null
  if (subject.type === 'Order') return { name: 'admin-order', params: { id: subject.id } }
  if (subject.type === 'Product') return { name: 'admin-product-edit', params: { id: subject.id } }
  if (subject.type === 'Payment') return { name: 'admin-payment', params: { id: subject.id } }

  return null
}

const changes = (log) => ({ before: log.old_values ?? {}, after: log.new_values ?? {} })
const show = (value) => (value !== null && typeof value === 'object' ? JSON.stringify(value) : String(value))
const hasDetails = (log) => Boolean(log.old_values || log.new_values)
</script>

<template>
  <AdminPage
    title="Audit log"
    subtitle="Who did what, and when. Entries can be read here but never changed or removed. Passwords and secrets are never recorded."
  >
    <form class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" role="search" @submit.prevent="list.apply">
      <FormSelect v-model="list.params.action" label="What happened" :options="actions" placeholder="Anything" />
      <BaseInput v-model="list.params.q" label="Who (name or email)" />
      <FormSelect v-model="list.params.subject" label="About" :options="SUBJECTS" placeholder="Anything" />
      <div>
        <label for="audit-from" class="mb-1.5 block text-sm font-medium">From</label>
        <input
          id="audit-from"
          v-model="list.params.from"
          type="date"
          class="w-full rounded-xl border border-line bg-bg px-3 py-2.5 text-sm focus:border-accent focus:outline-none"
        />
      </div>
      <div>
        <label for="audit-to" class="mb-1.5 block text-sm font-medium">To</label>
        <input
          id="audit-to"
          v-model="list.params.to"
          type="date"
          class="w-full rounded-xl border border-line bg-bg px-3 py-2.5 text-sm focus:border-accent focus:outline-none"
        />
      </div>
    </form>

    <AlertMessage v-if="list.error.value" class="mb-4">{{ list.error.value }}</AlertMessage>
    <div
      v-if="!list.loaded.value && list.loading.value"
      class="h-64 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading the audit log"
    />
    <EmptyState
      v-else-if="list.loaded.value && !list.rows.value.length"
      title="Nothing recorded"
      text="No entries match these filters."
    />
    <div
      v-else
      class="overflow-x-auto rounded-card border border-line bg-surface"
      :class="{ 'opacity-60 transition': list.loading.value }"
    >
      <table class="admin-table" data-testid="audit-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Who</th>
            <th>What</th>
            <th>About</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="log in list.rows.value" :key="log.id" class="align-top">
            <td class="whitespace-nowrap text-xs text-muted">
              {{ whenFull(log.at) }}
              <p v-if="log.ip_address" class="mt-0.5">{{ log.ip_address }}</p>
            </td>
            <td>
              <template v-if="log.actor"
                ><p>{{ log.actor.name }}</p>
                <p class="text-xs text-muted">{{ log.actor.email }}</p></template
              >
              <span v-else class="text-muted">System</span>
            </td>
            <td>
              <p class="font-medium">{{ humanizeAction(log.action) }}</p>
              <details v-if="hasDetails(log)" class="mt-1 text-xs text-muted">
                <summary class="cursor-pointer hover:text-ink">Details</summary>
                <dl class="mt-1 space-y-0.5">
                  <div v-for="(value, key) in changes(log).after" :key="key">
                    <dt class="inline text-ink">{{ key }}:</dt>
                    <dd class="inline">
                      <template v-if="key in changes(log).before">
                        <s>{{ show(changes(log).before[key]) }}</s> →
                      </template>
                      {{ show(value) }}
                    </dd>
                  </div>
                  <div v-for="(value, key) in changes(log).before" :key="`old-${key}`">
                    <template v-if="!(key in changes(log).after)"
                      ><dt class="inline text-ink">{{ key }}:</dt>
                      <dd class="inline">{{ show(value) }} (before)</dd></template
                    >
                  </div>
                </dl>
              </details>
            </td>
            <td>
              <template v-if="log.subject">
                <RouterLink
                  v-if="subjectLink(log.subject)"
                  :to="subjectLink(log.subject)"
                  class="text-accent hover:underline"
                  >{{ log.subject.type }}</RouterLink
                >
                <span v-else>{{ log.subject.type }}</span>
                <p class="max-w-[12rem] truncate font-mono text-[11px] text-muted" :title="log.subject.id">
                  {{ log.subject.id }}
                </p>
              </template>
              <span v-else class="text-muted">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="list.loaded.value" class="mt-3 text-center text-xs text-muted">
      {{ list.meta.value.total }} entr{{ list.meta.value.total === 1 ? 'y' : 'ies' }}
    </p>
    <div class="mt-4">
      <PaginationBar :page="list.meta.value.current_page" :last-page="list.meta.value.last_page" @change="list.load" />
    </div>
  </AdminPage>
</template>
