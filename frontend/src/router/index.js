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
  {
    path: '/admin',
    name: 'admin',
    component: () => import('@/pages/admin/Dashboard.vue'),
    meta: { requiresAuth: true, permission: 'admin.access' },
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
