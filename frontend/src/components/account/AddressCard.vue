<script setup>
defineProps({
  address: { type: Object, required: true },
  selectable: { type: Boolean, default: false },
  selected: { type: Boolean, default: false },
})
</script>

<template>
  <div class="text-sm" :class="selectable ? 'flex items-start gap-3' : ''">
    <span
      v-if="selectable"
      class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border"
      :class="selected ? 'border-accent bg-accent' : 'border-line'"
      aria-hidden="true"
    >
      <span v-if="selected" class="size-2 rounded-full bg-white" />
    </span>
    <div class="min-w-0">
      <p class="font-medium text-ink">
        {{ address.recipient_name }}
        <span v-if="address.label" class="ml-1.5 rounded-md bg-elevated px-1.5 py-0.5 text-xs font-normal text-muted">{{
          address.label
        }}</span>
        <span
          v-if="address.is_default"
          class="ml-1.5 rounded-md bg-accent-soft px-1.5 py-0.5 text-xs font-normal text-accent"
          >Default</span
        >
      </p>
      <p class="mt-0.5 text-muted">{{ address.phone }}</p>
      <p class="mt-1 text-muted">
        {{ address.line1 }}<template v-if="address.line2">, {{ address.line2 }}</template
        ><br />
        {{ address.postcode }} {{ address.city }}, {{ address.state }}
      </p>
    </div>
  </div>
</template>
