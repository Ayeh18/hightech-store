<script setup>
defineProps({ issues: { type: Array, default: () => [] } })
</script>

<template>
  <ul v-if="issues.length" class="mt-3 space-y-1.5">
    <li
      v-for="issue in issues"
      :key="issue.code + issue.message"
      class="flex items-start gap-2 text-sm"
      :class="issue.severity === 'error' ? 'text-danger' : 'text-warning'"
      role="alert"
    >
      <span aria-hidden="true">{{ issue.severity === 'error' ? '✗' : '⚠' }}</span>
      <span>{{ issue.message }}</span>
    </li>
  </ul>
</template>
