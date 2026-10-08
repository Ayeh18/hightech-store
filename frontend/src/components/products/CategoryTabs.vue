<script setup>
defineProps({
  categories: { type: Array, required: true },
  modelValue: { type: String, default: '' },
})
defineEmits(['update:modelValue'])
</script>

<template>
  <nav class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Product categories">
    <ul class="flex gap-2 whitespace-nowrap pb-1">
      <li>
        <button
          type="button"
          class="rounded-full border px-4 py-2 text-sm transition"
          :class="
            modelValue === ''
              ? 'border-accent bg-accent-soft font-semibold text-accent'
              : 'border-line bg-surface text-muted hover:text-ink'
          "
          :aria-current="modelValue === '' ? 'true' : undefined"
          @click="$emit('update:modelValue', '')"
        >
          All
        </button>
      </li>
      <li v-for="category in categories" :key="category.slug">
        <button
          type="button"
          class="rounded-full border px-4 py-2 text-sm transition"
          :class="
            modelValue === category.slug
              ? 'border-accent bg-accent-soft font-semibold text-accent'
              : 'border-line bg-surface text-muted hover:text-ink'
          "
          :aria-current="modelValue === category.slug ? 'true' : undefined"
          @click="$emit('update:modelValue', category.slug)"
        >
          {{ category.name }}
          <span class="ml-1 text-xs opacity-60">{{ category.products_count }}</span>
        </button>
      </li>
    </ul>
  </nav>
</template>
