<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import CartBuildLine from '@/components/cart/CartBuildLine.vue'
import CartProductLine from '@/components/cart/CartProductLine.vue'
import CartSummary from '@/components/cart/CartSummary.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { parseApiError } from '@/lib/api'
import { useCartStore } from '@/stores/cart'

const cart = useCartStore()
const actionError = ref('')

// Always ask the server again when the page opens: prices, stock and compatibility may have changed since the last visit.
onMounted(() => cart.fetch())

async function run(action) {
  actionError.value = ''
  try {
    await action()
  } catch (e) {
    const parsed = parseApiError(e)
    actionError.value = Object.values(parsed.errors).flat()[0] ?? parsed.message
  }
}

const setQuantity = (line, quantity) => run(() => cart.updateQuantity(line.id, quantity))
const remove = (line) => run(() => cart.remove(line.id))
const clear = () => run(() => cart.clear())
const acceptPrices = () => run(() => cart.acceptPrices())
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <header class="mb-6">
      <h1 class="font-display text-3xl font-semibold tracking-tight">Your cart</h1>
      <p class="mt-1 text-sm text-muted">
        Prices, stock and compatibility are re-checked every time you open this page.
      </p>
    </header>

    <div
      v-if="cart.loading && !cart.loaded"
      class="grid animate-pulse gap-6 lg:grid-cols-[1fr_22rem]"
      aria-busy="true"
      aria-label="Loading your cart"
    >
      <div class="space-y-4"><div v-for="n in 3" :key="n" class="h-32 rounded-card bg-surface" /></div>
      <div class="h-64 rounded-card bg-surface" />
    </div>

    <div v-else-if="cart.error && !cart.loaded" role="alert">
      <EmptyState title="We couldn't load your cart" :text="cart.error"
        ><BaseButton @click="cart.fetch()">Try again</BaseButton></EmptyState
      >
    </div>

    <EmptyState
      v-else-if="!cart.items.length"
      title="Your cart is empty"
      text="Add parts from the shop, or build a whole PC and add it in one go."
    >
      <div class="flex flex-wrap justify-center gap-3">
        <RouterLink :to="{ name: 'products' }"><BaseButton>Browse parts</BaseButton></RouterLink>
        <RouterLink :to="{ name: 'builder' }"><BaseButton variant="secondary">Build a PC</BaseButton></RouterLink>
      </div>
    </EmptyState>

    <div v-else class="grid items-start gap-6 lg:grid-cols-[1fr_22rem]">
      <div class="space-y-4">
        <AlertMessage v-if="actionError">{{ actionError }}</AlertMessage>

        <template v-for="line in cart.items" :key="line.id">
          <CartBuildLine
            v-if="line.kind === 'build'"
            :line="line"
            :busy="cart.busy"
            @quantity="setQuantity(line, $event)"
            @remove="remove(line)"
          />
          <CartProductLine
            v-else
            :line="line"
            :busy="cart.busy"
            @quantity="setQuantity(line, $event)"
            @remove="remove(line)"
          />
        </template>
      </div>

      <aside class="lg:sticky lg:top-24" aria-label="Order summary">
        <CartSummary
          :subtotal="cart.subtotal"
          :item-count="cart.itemCount"
          :checkout-ready="cart.checkoutReady"
          :has-price-changes="cart.hasPriceChanges"
          :busy="cart.busy"
          @accept-prices="acceptPrices"
          @clear="clear"
        />
      </aside>
    </div>
  </div>
</template>
