<script setup>
import { ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import QuantityStepper from '@/components/ui/QuantityStepper.vue'
import { parseApiError } from '@/lib/api'
import { MAX_PRODUCT_QUANTITY } from '@/lib/cartLimits'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

/** Quantity + "Add to cart" for one product. The server decides whether it really fits (stock, limits). */
const props = defineProps({ product: { type: Object, required: true } })

const auth = useAuthStore()
const cart = useCartStore()
const router = useRouter()
const route = useRoute()

const quantity = ref(1)
const added = ref(false)
const error = ref('')

async function add() {
  error.value = ''
  added.value = false

  if (!auth.isAuthenticated) {
    router.push({ name: 'login', query: { redirect: route.fullPath } })
    return
  }

  try {
    await cart.addProduct(props.product.id, quantity.value)
    added.value = true
  } catch (e) {
    const parsed = parseApiError(e)
    error.value = Object.values(parsed.errors).flat()[0] ?? parsed.message
  }
}
</script>

<template>
  <div class="mt-6">
    <div class="flex flex-wrap items-center gap-3">
      <QuantityStepper
        v-model="quantity"
        :max="MAX_PRODUCT_QUANTITY"
        :disabled="!product.in_stock || cart.busy"
        label="Quantity"
      />
      <BaseButton :disabled="!product.in_stock" :loading="cart.busy" @click="add">
        {{ product.in_stock ? (auth.isAuthenticated ? 'Add to cart' : 'Log in to add to cart') : 'Out of stock' }}
      </BaseButton>
    </div>

    <div class="mt-3 space-y-2" aria-live="polite">
      <AlertMessage v-if="added" type="success">
        Added to your cart.
        <RouterLink :to="{ name: 'cart' }" class="font-medium underline">View cart</RouterLink>
      </AlertMessage>
      <AlertMessage v-if="error">{{ error }}</AlertMessage>
    </div>
  </div>
</template>
