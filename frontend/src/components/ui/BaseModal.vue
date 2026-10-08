<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

/*
 * An accessible dialog: Escape closes it, Tab stays inside it, the page behind does not scroll,
 * and focus returns to the button that opened it.
 */
const props = defineProps({
  open: { type: Boolean, required: true },
  title: { type: String, required: true },
  size: { type: String, default: 'md', validator: (v) => ['md', 'lg', 'xl'].includes(v) },
})
const emit = defineEmits(['close'])

const panel = ref(null)
let opener = null

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.stopPropagation()
    emit('close')
    return
  }
  if (event.key !== 'Tab' || !panel.value) return

  const items = [...panel.value.querySelectorAll(FOCUSABLE)]
  if (!items.length) return
  const first = items[0]
  const last = items[items.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(
  () => props.open,
  async (open) => {
    if (open) {
      opener = document.activeElement
      document.body.style.overflow = 'hidden'
      await nextTick()
      // data-autofocus may sit on a wrapper (e.g. BaseInput's root), so look for the input inside it.
      const marked = panel.value?.querySelector('[data-autofocus]')
      const target = marked?.matches('input, button, select, textarea')
        ? marked
        : marked?.querySelector('input, textarea, select')
      ;(target ?? panel.value?.querySelector(FOCUSABLE) ?? panel.value)?.focus()
    } else {
      document.body.style.overflow = ''
      opener?.focus?.()
    }
  },
)
onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

const widths = { md: 'max-w-md', lg: 'max-w-3xl', xl: 'max-w-6xl' }
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-6"
      @keydown="onKeydown"
    >
      <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" aria-hidden="true" @click="emit('close')" />
      <div
        ref="panel"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        tabindex="-1"
        class="relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-card border border-line bg-surface shadow-2xl focus:outline-none sm:rounded-card"
        :class="widths[size]"
      >
        <header class="flex items-center justify-between gap-4 border-b border-line px-5 py-4">
          <h2 class="font-display text-lg font-semibold">{{ title }}</h2>
          <button
            type="button"
            class="rounded-lg p-1.5 text-muted transition hover:bg-elevated hover:text-ink"
            aria-label="Close dialog"
            @click="emit('close')"
          >
            <svg
              viewBox="0 0 24 24"
              class="size-5"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path d="M6 6l12 12M18 6 6 18" />
            </svg>
          </button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto"><slot /></div>
        <footer v-if="$slots.footer" class="border-t border-line px-5 py-4"><slot name="footer" /></footer>
      </div>
    </div>
  </Teleport>
</template>
