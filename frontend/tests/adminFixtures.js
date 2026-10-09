// Shared helpers for the admin page tests (not a test file itself).
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

export const money = (sen) => ({
  amount: sen,
  currency: 'MYR',
  formatted: `RM ${(sen / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
})
export const status = (value, label = value) => ({ value, label })
export const at = (day, hour = 9) =>
  `2026-10-${String(day).padStart(2, '0')}T${String(hour).padStart(2, '0')}:00:00+00:00`

export const ALL_PERMISSIONS = [
  'admin.access',
  'reports.view',
  'orders.manage',
  'refunds.manage',
  'payments.view',
  'products.manage',
  'categories.manage',
  'brands.manage',
  'inventory.manage',
  'compatibility.manage',
  'coupons.manage',
  'users.manage',
  'audit.view',
]
export const STAFF_PERMISSIONS = [
  'admin.access',
  'reports.view',
  'orders.manage',
  'products.manage',
  'categories.manage',
  'brands.manage',
  'inventory.manage',
]

/** A fresh store with someone signed in who holds these permissions. */
export function signIn(permissions = ALL_PERMISSIONS, roles = ['admin']) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'Admin Tester', email: 'admin@example.com', roles, permissions }
  return auth
}

const stub = { template: '<div />' }

/** A router that knows every admin page name by its address, so links render. The page under test sits at `routePath`. */
export function adminRouter(component, routePath) {
  const names = {
    'admin-orders': '/admin/orders',
    'admin-order': '/admin/orders/:id',
    'admin-products': '/admin/products',
    'admin-product-edit': '/admin/products/:id',
    'admin-product-new': '/admin/products/new',
    'admin-payments': '/admin/payments',
    'admin-payment': '/admin/payments/:id',
    'admin-refunds': '/admin/refunds',
    'admin-users': '/admin/users',
    'admin-dashboard': '/admin/dashboard',
    'admin-coupons': '/admin/coupons',
  }
  const routes = Object.entries(names)
    .filter(([, path]) => path !== routePath)
    .map(([name, path]) => ({ path, name, component: stub }))

  return createRouter({
    history: createMemoryHistory(),
    routes: [...routes, { path: routePath, name: 'page', component }, { path: '/', name: 'home', component: stub }],
  })
}

let mounted = []
export async function mountAdmin(component, path, routePath = path) {
  const router = adminRouter(component, routePath)
  router.push(path)
  await router.isReady()
  const wrapper = mount(component, { global: { plugins: [router] }, attachTo: document.body })
  mounted.push(wrapper)
  await flushPromises()
  return { wrapper, router }
}

export function cleanup() {
  mounted.forEach((w) => w.unmount())
  mounted = []
  document.body.innerHTML = ''
}

export const button = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label)
export const dialog = () => document.body.querySelector('[role=dialog]')
export const dialogButton = (label) =>
  [...(dialog()?.querySelectorAll('button') ?? [])].find((b) => b.textContent.trim() === label)

/** Types into a box inside the open dialog (found by its label text). */
export async function typeInDialog(label, value) {
  const field = [...dialog().querySelectorAll('label')].find((l) => l.textContent.trim().startsWith(label))
  const input = dialog().querySelector(`#${field.getAttribute('for')}`)
  input.value = value
  input.dispatchEvent(new Event(input.tagName === 'SELECT' ? 'change' : 'input'))
  await flushPromises()
}

export async function submitDialog() {
  dialog()
    .querySelector('form')
    .dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

/** The input (or select, or textarea) that belongs to the label starting with this text. */
export function field(wrapper, label) {
  const found = wrapper.findAll('label').find((l) => l.text().startsWith(label))
  if (!found) throw new Error(`No label starting with "${label}"`)
  return wrapper.find(`#${found.attributes('for')}`)
}
