<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import SearchBox from '@/components/layout/SearchBox.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

const auth = useAuthStore()
const cart = useCartStore()
const router = useRouter()
const mainLinks = [
  { name: 'products', label: 'Shop' },
  { name: 'builder', label: 'PC Builder' },
]
const menuOpen = ref(false)
const menuRoot = ref(null)

async function logout() {
  menuOpen.value = false
  await auth.logout()
  router.push({ name: 'home' })
}

// Close the account menu when clicking elsewhere.
const onClickOutside = (event) => {
  if (menuRoot.value && !menuRoot.value.contains(event.target)) menuOpen.value = false
}
onMounted(() => document.addEventListener('click', onClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside))
</script>

<template>
  <header class="sticky top-0 z-40 border-b border-line bg-bg/85 backdrop-blur">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6 md:flex-nowrap">
      <RouterLink to="/" class="flex items-center gap-2.5" aria-label="HighTech Store home">
        <span class="grid size-9 place-items-center rounded-xl bg-accent-soft text-accent" aria-hidden="true">
          <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="6" y="6" width="12" height="12" rx="1.5" />
            <path d="M9 3v3M12 3v3M15 3v3M9 18v3M12 18v3M15 18v3M3 9h3M3 12h3M3 15h3M18 9h3M18 12h3M18 15h3" />
          </svg>
        </span>
        <span class="font-display text-lg font-semibold tracking-tight"
          >HighTech <span class="text-accent">Store</span></span
        >
      </RouterLink>

      <nav class="order-last flex w-full items-center gap-1 md:order-none md:w-auto" aria-label="Main">
        <RouterLink
          v-for="link in mainLinks"
          :key="link.name"
          :to="{ name: link.name }"
          class="rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink"
          active-class="!text-ink"
        >
          {{ link.label }}
        </RouterLink>
      </nav>

      <div class="order-last w-full md:order-none md:max-w-md md:flex-1"><SearchBox /></div>

      <nav class="ml-auto flex items-center gap-2" aria-label="Account">
        <RouterLink
          :to="{ name: 'cart' }"
          class="relative grid size-10 place-items-center rounded-xl border border-line bg-surface text-muted transition hover:border-accent/60 hover:text-ink"
          :aria-label="cart.itemCount ? `Cart, ${cart.itemCount} ${cart.itemCount === 1 ? 'item' : 'items'}` : 'Cart'"
        >
          <svg
            viewBox="0 0 24 24"
            class="size-5"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            aria-hidden="true"
          >
            <path d="M3 4h2l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.2a1.5 1.5 0 0 0 1.5-1.1L20 8H6.2" />
            <circle cx="9.5" cy="19.5" r="1.3" />
            <circle cx="17" cy="19.5" r="1.3" />
          </svg>
          <span
            v-if="cart.itemCount"
            class="absolute -right-1.5 -top-1.5 grid min-w-5 place-items-center rounded-full bg-accent px-1 text-[11px] font-bold leading-5 text-white"
            data-testid="cart-count"
            aria-hidden="true"
            >{{ cart.itemCount > 99 ? '99+' : cart.itemCount }}</span
          >
        </RouterLink>

        <template v-if="auth.isAuthenticated">
          <RouterLink
            v-if="auth.can('admin.access')"
            to="/admin"
            class="hidden rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink sm:block"
          >
            Admin
          </RouterLink>

          <div ref="menuRoot" class="relative">
            <button
              type="button"
              class="flex items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2 text-sm font-medium transition hover:border-accent/60"
              :aria-expanded="menuOpen"
              aria-haspopup="menu"
              @click="menuOpen = !menuOpen"
            >
              <span
                class="grid size-6 place-items-center rounded-full bg-accent text-xs font-bold text-white"
                aria-hidden="true"
              >
                {{ auth.user.name.charAt(0).toUpperCase() }}
              </span>
              <span class="max-w-32 truncate">{{ auth.user.name }}</span>
            </button>

            <div
              v-if="menuOpen"
              role="menu"
              class="absolute right-0 mt-2 w-56 rounded-xl border border-line bg-surface p-1.5 shadow-2xl"
            >
              <p class="truncate px-3 py-2 text-xs text-muted">{{ auth.user.email }}</p>
              <RouterLink
                :to="{ name: 'my-builds' }"
                role="menuitem"
                class="block rounded-lg px-3 py-2 text-sm hover:bg-elevated"
                @click="menuOpen = false"
              >
                My builds
              </RouterLink>
              <RouterLink
                v-if="auth.can('admin.access')"
                to="/admin"
                role="menuitem"
                class="block rounded-lg px-3 py-2 text-sm hover:bg-elevated"
                @click="menuOpen = false"
              >
                Admin dashboard
              </RouterLink>
              <button
                type="button"
                role="menuitem"
                class="block w-full rounded-lg px-3 py-2 text-left text-sm text-danger hover:bg-elevated"
                @click="logout"
              >
                Log out
              </button>
            </div>
          </div>
        </template>

        <template v-else>
          <RouterLink to="/login"><BaseButton variant="ghost">Log in</BaseButton></RouterLink>
          <RouterLink to="/register"><BaseButton>Sign up</BaseButton></RouterLink>
        </template>
      </nav>
    </div>
  </header>
</template>
