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
