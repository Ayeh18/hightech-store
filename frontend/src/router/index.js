import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/*
 * Route meta flags:
 *   requiresAuth - must be logged in
 *   guestOnly    - logged-in users are redirected away (login/register)
 *   permission   - user must have this permission key (e.g. "admin.access")
 *   layout       - "auth" for the centred card layout (default: main shell)
 * Pages are lazy-loaded (() => import(...)) so each one becomes its own JS file (code splitting).
 */
// Meta for an admin page: signed in, holding this permission, shown inside the admin layout.
const admin = (permission) => ({ requiresAuth: true, permission, layout: 'admin' })

const routes = [
  { path: '/', name: 'home', component: () => import('@/pages/Home.vue') },
  { path: '/builder', name: 'builder', component: () => import('@/pages/PCBuilder.vue') },
  {
    path: '/builder/:id',
    name: 'builder-edit',
    component: () => import('@/pages/PCBuilder.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/checkout',
    name: 'checkout',
    component: () => import('@/pages/Checkout.vue'),
    meta: { requiresAuth: true },
  },
  { path: '/orders', name: 'orders', component: () => import('@/pages/Orders.vue'), meta: { requiresAuth: true } },
  {
    path: '/orders/:id',
    name: 'order',
    component: () => import('@/pages/OrderDetail.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/addresses',
    name: 'addresses',
    component: () => import('@/pages/Addresses.vue'),
    meta: { requiresAuth: true },
  },
  {
    // The pretend payment company's page (local development only; the server answers 404 where it is switched off).
    path: '/fake-gateway/:reference',
    name: 'fake-gateway',
    component: () => import('@/pages/FakeGateway.vue'),
    meta: { layout: 'gateway' },
  },
  { path: '/cart', name: 'cart', component: () => import('@/pages/Cart.vue'), meta: { requiresAuth: true } },
  {
    path: '/my-builds',
    name: 'my-builds',
    component: () => import('@/pages/MyBuilds.vue'),
    meta: { requiresAuth: true },
  },
  { path: '/products', name: 'products', component: () => import('@/pages/Products.vue') },
  { path: '/products/:slug', name: 'product', component: () => import('@/pages/ProductDetail.vue') },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/pages/auth/Login.vue'),
    meta: { guestOnly: true, layout: 'auth' },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/pages/auth/Register.vue'),
    meta: { guestOnly: true, layout: 'auth' },
  },
  // ---------- Admin. Every page needs a permission (the API re-checks it) and uses the admin layout.
  {
    path: '/admin',
    name: 'admin',
    component: () => import('@/pages/admin/AdminHome.vue'),
    meta: admin('admin.access'),
  },
  {
    path: '/admin/orders',
    name: 'admin-orders',
    component: () => import('@/pages/admin/Orders.vue'),
    meta: admin('orders.manage'),
  },
  {
    path: '/admin/orders/:id',
    name: 'admin-order',
    component: () => import('@/pages/admin/OrderDetail.vue'),
    meta: admin('orders.manage'),
  },
  {
    path: '/admin/refunds',
    name: 'admin-refunds',
    component: () => import('@/pages/admin/Refunds.vue'),
    meta: admin('refunds.manage'),
  },
  {
    path: '/admin/payments',
    name: 'admin-payments',
    component: () => import('@/pages/admin/Payments.vue'),
    meta: admin('payments.view'),
  },
  {
    path: '/admin/payments/:id',
    name: 'admin-payment',
    component: () => import('@/pages/admin/PaymentDetail.vue'),
    meta: admin('payments.view'),
  },
  {
    path: '/admin/products',
    name: 'admin-products',
    component: () => import('@/pages/admin/Products.vue'),
    meta: admin('products.manage'),
  },
  {
    path: '/admin/products/new',
    name: 'admin-product-new',
    component: () => import('@/pages/admin/ProductForm.vue'),
    meta: admin('products.manage'),
  },
  {
    path: '/admin/products/:id',
    name: 'admin-product-edit',
    component: () => import('@/pages/admin/ProductForm.vue'),
    meta: admin('products.manage'),
  },
  {
    path: '/admin/coupons',
    name: 'admin-coupons',
    component: () => import('@/pages/admin/Coupons.vue'),
    meta: admin('coupons.manage'),
  },
  {
    path: '/admin/compatibility',
    name: 'admin-compatibility',
    component: () => import('@/pages/admin/CompatibilityRules.vue'),
    meta: admin('compatibility.manage'),
  },
  {
    path: '/admin/users',
    name: 'admin-users',
    component: () => import('@/pages/admin/Users.vue'),
    meta: admin('users.manage'),
  },
  {
    path: '/admin/catalog',
    name: 'admin-catalog',
    component: () => import('@/pages/admin/Catalog.vue'),
    meta: admin('categories.manage'),
  },
  {
    path: '/admin/reports',
    name: 'admin-reports',
    component: () => import('@/pages/admin/Reports.vue'),
    meta: admin('reports.view'),
  },
  {
    path: '/admin/audit',
    name: 'admin-audit',
    component: () => import('@/pages/admin/AuditLogs.vue'),
    meta: admin('audit.view'),
  },
  {
    path: '/admin/dashboard',
    name: 'admin-dashboard',
    component: () => import('@/pages/admin/Dashboard.vue'),
    meta: admin('reports.view'),
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFound.vue') },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

const SITE = 'HighTech Store'
const TITLES = {
  home: SITE,
  builder: `PC Builder · ${SITE}`,
  'builder-edit': `PC Builder · ${SITE}`,
  'my-builds': `My builds · ${SITE}`,
  cart: `Your cart · ${SITE}`,
  checkout: `Checkout · ${SITE}`,
  orders: `My orders · ${SITE}`,
  'fake-gateway': 'FakePay · test payment page',
  addresses: `My addresses · ${SITE}`,
  login: `Log in · ${SITE}`,
  register: `Sign up · ${SITE}`,
  admin: `Admin · ${SITE}`,
  'admin-dashboard': `Dashboard · Admin · ${SITE}`,
  'admin-products': `Products · Admin · ${SITE}`,
  'admin-product-new': `New product · Admin · ${SITE}`,
  'admin-product-edit': `Product · Admin · ${SITE}`,
  'admin-coupons': `Coupons · Admin · ${SITE}`,
  'admin-compatibility': `Compatibility rules · Admin · ${SITE}`,
  'admin-users': `Users · Admin · ${SITE}`,
  'admin-catalog': `Brands & categories · Admin · ${SITE}`,
  'admin-reports': `Sales reports · Admin · ${SITE}`,
  'admin-audit': `Audit log · Admin · ${SITE}`,
  'admin-orders': `Orders · Admin · ${SITE}`,
  'admin-refunds': `Refunds · Admin · ${SITE}`,
  'admin-payments': `Payments · Admin · ${SITE}`,
  'admin-payment': `Payment · Admin · ${SITE}`,
  'not-found': `Not found · ${SITE}`,
}
// Pages that know their own title (shop, product) set it themselves once their data has loaded.
router.afterEach((to) => {
  if (TITLES[to.name]) document.title = TITLES[to.name]
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.init()

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'home' }
  }
  if ((to.meta.requiresAuth || to.meta.permission) && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.permission && !auth.can(to.meta.permission)) {
    return { name: 'home' }
  }
})

export default router
