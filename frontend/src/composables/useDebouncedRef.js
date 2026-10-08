import { onBeforeUnmount, ref, watch } from 'vue'

/** A ref whose `debounced` twin only updates after the user stops changing it for `delay` ms. */
export function useDebouncedRef(initial, delay = 350) {
  const value = ref(initial)
  const debounced = ref(initial)
  let timer

  watch(value, (next) => {
    clearTimeout(timer)
    timer = setTimeout(() => (debounced.value = next), delay)
  })
  onBeforeUnmount(() => clearTimeout(timer))

  return { value, debounced }
}
