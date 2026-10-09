import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, parseApiError } from '@/lib/api'

/*
 * The checkout form: which address, which coupon, an optional note.
 *
 * The browser never calculates the order. Every total on the page comes from POST /api/checkout/preview, and the real
 * order is built by the server from the cart, not from anything shown here. A random Idempotency-Key is sent with
 * "Place order": if the request is repeated (double click, retry after a network error) the server hands back the same
 * order instead of creating a second one.
 */
const newKey = () =>
  (globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`).replace(
    /[^A-Za-z0-9_-]/g,
    '',
  )

export const useCheckoutStore = defineStore('checkout', () => {
  const addressId = ref(null)
  const couponCode = ref('')
  const notes = ref('')
  const quote = ref(null)
  const quoting = ref(false)
  const placing = ref(false)
  const error = ref('')
  const couponError = ref('')
  let idempotencyKey = newKey()
  let sequence = 0

  const canPlace = computed(() => Boolean(quote.value?.can_place) && !placing.value)

  /** Ask the server what the order would cost with the current address and coupon. */
  async function refreshQuote() {
    const mine = ++sequence
    quoting.value = true
    try {
      const { data } = await api.post('/api/checkout/preview', {
        address_id: addressId.value,
        coupon_code: couponCode.value.trim() || null,
      })
      if (mine !== sequence) return // a newer question was asked meanwhile
      quote.value = data.data
      couponError.value = data.data.coupon.error ?? ''
      error.value = ''
    } catch (e) {
      if (mine === sequence) error.value = parseApiError(e).message
    } finally {
      if (mine === sequence) quoting.value = false
    }
  }

  const selectAddress = (id) => {
    addressId.value = id
    return refreshQuote()
  }

  const applyCoupon = (code) => {
    couponCode.value = code
    return refreshQuote()
  }

  /** Place the order. Returns the order; throws with the server's reasons if it is refused. */
  async function place() {
    placing.value = true
    error.value = ''
    try {
      const { data } = await api.post(
        '/api/checkout',
        {
          address_id: addressId.value,
          coupon_code: couponCode.value.trim() || null,
          notes: notes.value.trim() || null,
        },
        { headers: { 'Idempotency-Key': idempotencyKey } },
      )
      reset() // the order exists now: the next checkout is a new attempt with a new key
      return data.data
    } catch (e) {
      const parsed = parseApiError(e)
      couponError.value = parsed.errors.coupon_code?.[0] ?? ''
      throw e
    } finally {
      placing.value = false
    }
  }

  function reset() {
    couponCode.value = ''
    notes.value = ''
    quote.value = null
    couponError.value = ''
    error.value = ''
    idempotencyKey = newKey()
  }

  return {
    addressId,
    couponCode,
    notes,
    quote,
    quoting,
    placing,
    error,
    couponError,
    canPlace,
    refreshQuote,
    selectAddress,
    applyCoupon,
    place,
    reset,
  }
})
