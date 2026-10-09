<script setup>
import { ref } from 'vue'
import AdminPage from '@/components/admin/AdminPage.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useAdminList } from '@/composables/useAdminList'
import { api } from '@/lib/api'
import { firstError } from '@/lib/errors'
import { whenFull } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const list = useAdminList('/api/admin/users', { q: '', role: '', active: '' })

// The built-in roles. (The API checks every value, and rejects a role that does not exist.)
const ROLES = [
  { value: 'customer', label: 'Customer', hint: 'Shops and orders. No admin access.' },
  { value: 'staff', label: 'Staff', hint: 'Fulfils orders, manages the catalog and stock, sees reports.' },
  { value: 'admin', label: 'Admin', hint: 'Everything: refunds, payments, users, coupons and the audit log.' },
]
const STATUS_FILTER = [
  { value: '1', label: 'Active' },
  { value: '0', label: 'Deactivated' },
]

const isMe = (user) => user.id === auth.user?.id
const notice = ref('')

// ---------- roles
const rolesFor = ref(null)
const chosen = ref([])
const busy = ref(false)
const dialogError = ref('')

function openRoles(user) {
  rolesFor.value = user
  chosen.value = [...user.roles]
  dialogError.value = ''
}

async function saveRoles() {
  busy.value = true
  dialogError.value = ''
  try {
    await api.put(`/api/admin/users/${rolesFor.value.id}/roles`, { roles: chosen.value })
    notice.value = `${rolesFor.value.name}'s roles were updated.`
    rolesFor.value = null
    await list.reload()
  } catch (e) {
    dialogError.value = firstError(e)
  } finally {
    busy.value = false
  }
}

// ---------- activation
const toggling = ref(null)
const toggleError = ref('')

async function toggle() {
  busy.value = true
  toggleError.value = ''
  try {
    await api.put(`/api/admin/users/${toggling.value.id}/status`, { is_active: !toggling.value.is_active })
    notice.value = `${toggling.value.name} was ${toggling.value.is_active ? 'deactivated and signed out' : 'activated'}.`
    toggling.value = null
    await list.reload()
  } catch (e) {
    toggleError.value = firstError(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AdminPage title="Users" subtitle="Who has an account, and what each person is allowed to do.">
    <form
      class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_14rem_14rem]"
      role="search"
      @submit.prevent="list.apply"
    >
      <BaseInput v-model="list.params.q" label="Search" placeholder="Name or email" />
      <FormSelect v-model="list.params.role" label="Role" :options="ROLES" placeholder="Any role" />
      <FormSelect v-model="list.params.active" label="Account" :options="STATUS_FILTER" placeholder="Any" />
    </form>

    <AlertMessage v-if="notice" type="success" class="mb-4">{{ notice }}</AlertMessage>
    <AlertMessage v-if="list.error.value" class="mb-4">{{ list.error.value }}</AlertMessage>
    <div
      v-if="!list.loaded.value && list.loading.value"
      class="h-64 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading users"
    />
    <EmptyState
      v-else-if="list.loaded.value && !list.rows.value.length"
      title="No users match"
      text="Try a different search or filter."
    />
    <div
      v-else
      class="relative overflow-x-auto rounded-card border border-line bg-surface"
      :class="{ 'opacity-60 transition': list.loading.value }"
    >
      <table class="admin-table" data-testid="users-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Roles</th>
            <th class="text-right">Orders</th>
            <th>Last sign-in</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in list.rows.value" :key="user.id">
            <td>
              <p class="font-medium">
                {{ user.name }}<span v-if="isMe(user)" class="ml-1.5 text-xs text-muted">(you)</span>
              </p>
              <p class="text-xs text-muted">{{ user.email }}</p>
              <p v-if="!user.is_active" class="mt-0.5 text-xs font-medium text-danger">Deactivated</p>
            </td>
            <td>
              <span
                v-for="role in user.roles"
                :key="role"
                class="mr-1 inline-flex rounded-full bg-elevated px-2 py-0.5 text-xs"
                >{{ role }}</span
              >
            </td>
            <td class="text-right tabular-nums">{{ user.orders_count }}</td>
            <td class="whitespace-nowrap text-xs text-muted">
              {{ user.last_login_at ? whenFull(user.last_login_at) : 'Never' }}
            </td>
            <td class="whitespace-nowrap text-right">
              <button
                type="button"
                class="text-sm text-accent hover:underline disabled:text-muted disabled:no-underline"
                :disabled="isMe(user)"
                :aria-label="`Change roles of ${user.name}`"
                @click="openRoles(user)"
              >
                Roles
              </button>
              <button
                type="button"
                class="ml-3 text-sm hover:underline disabled:text-muted disabled:no-underline"
                :class="user.is_active ? 'text-danger' : 'text-accent'"
                :disabled="isMe(user)"
                :aria-label="`${user.is_active ? 'Deactivate' : 'Activate'} ${user.name}`"
                @click="((toggling = user), (toggleError = ''))"
              >
                {{ user.is_active ? 'Deactivate' : 'Activate' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="list.loaded.value" class="mt-3 text-center text-xs text-muted">
      {{ list.meta.value.total }} user{{ list.meta.value.total === 1 ? '' : 's' }}
    </p>
    <div class="mt-4">
      <PaginationBar :page="list.meta.value.current_page" :last-page="list.meta.value.last_page" @change="list.load" />
    </div>

    <BaseModal :open="rolesFor !== null" :title="`Roles for ${rolesFor?.name ?? ''}`" @close="rolesFor = null">
      <form id="roles-form" class="space-y-3 p-5 text-sm" @submit.prevent="saveRoles">
        <AlertMessage v-if="dialogError">{{ dialogError }}</AlertMessage>
        <label v-for="role in ROLES" :key="role.value" class="flex items-start gap-3 rounded-xl border border-line p-3">
          <input v-model="chosen" type="checkbox" :value="role.value" class="mt-0.5 size-4 accent-accent" />
          <span
            ><span class="font-medium">{{ role.label }}</span
            ><span class="block text-xs text-muted">{{ role.hint }}</span></span
          >
        </label>
        <p class="text-xs text-muted">Changes apply on their next click. The change is written to the audit log.</p>
      </form>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="rolesFor = null">Cancel</BaseButton>
          <BaseButton type="submit" form="roles-form" :loading="busy" :disabled="!chosen.length">Save roles</BaseButton>
        </div>
      </template>
    </BaseModal>

    <BaseModal
      :open="toggling !== null"
      :title="toggling?.is_active ? 'Deactivate this user?' : 'Activate this user?'"
      @close="toggling = null"
    >
      <div class="space-y-3 p-5 text-sm">
        <AlertMessage v-if="toggleError">{{ toggleError }}</AlertMessage>
        <p v-if="toggling?.is_active">
          <span class="font-semibold">{{ toggling.name }}</span> is signed out straight away and cannot sign in again
          until you activate the account. Their orders are kept.
        </p>
        <p v-else>
          <span class="font-semibold">{{ toggling?.name }}</span> will be able to sign in again.
        </p>
      </div>
      <template #footer>
        <div class="flex justify-end gap-3">
          <BaseButton variant="ghost" @click="toggling = null">Not now</BaseButton>
          <BaseButton :class="toggling?.is_active ? '!bg-danger' : ''" :loading="busy" @click="toggle">{{
            toggling?.is_active ? 'Deactivate' : 'Activate'
          }}</BaseButton>
        </div>
      </template>
    </BaseModal>
  </AdminPage>
</template>
