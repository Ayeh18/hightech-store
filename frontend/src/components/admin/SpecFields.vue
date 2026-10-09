<script setup>
import FormSelect from '@/components/admin/FormSelect.vue'
import BaseInput from '@/components/ui/BaseInput.vue'

/**
 * The specification boxes of one category, drawn from its definitions (so a new category or spec needs no code here).
 * `modelValue` is { key: value }: text for string/number/enum, a list of chosen options for "list".
 * Errors come from the server as { 'specs.socket': ['...'] }.
 */
const props = defineProps({
  definitions: { type: Array, required: true },
  modelValue: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])

const set = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value })
const error = (key) => props.errors[`specs.${key}`]?.[0] ?? ''

function toggle(key, option, checked) {
  const current = props.modelValue[key] ?? []
  set(key, checked ? [...current, option] : current.filter((o) => o !== option))
}
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2" data-testid="spec-fields">
    <template v-for="definition in definitions" :key="definition.key">
      <fieldset v-if="definition.type === 'list'" class="sm:col-span-2">
        <legend class="mb-1.5 text-sm font-medium">
          {{ definition.label }}<span v-if="definition.is_required" class="text-danger"> *</span>
        </legend>
        <div class="flex flex-wrap gap-x-5 gap-y-2">
          <label v-for="option in definition.options" :key="option" class="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              class="size-4 accent-accent"
              :checked="(modelValue[definition.key] ?? []).includes(option)"
              @change="toggle(definition.key, option, $event.target.checked)"
            />
            {{ option }}
          </label>
        </div>
        <p v-if="error(definition.key)" class="mt-1.5 text-xs text-danger" role="alert">{{ error(definition.key) }}</p>
      </fieldset>

      <FormSelect
        v-else-if="definition.type === 'enum'"
        :model-value="modelValue[definition.key] ?? ''"
        :label="`${definition.label}${definition.is_required ? ' *' : ''}`"
        :options="definition.options.map((o) => ({ value: o, label: o }))"
        placeholder="Choose…"
        :error="error(definition.key)"
        @update:model-value="set(definition.key, $event)"
      />

      <BaseInput
        v-else
        :model-value="String(modelValue[definition.key] ?? '')"
        :label="`${definition.label}${definition.unit ? ` (${definition.unit})` : ''}${definition.is_required ? ' *' : ''}`"
        :error="error(definition.key)"
        @update:model-value="set(definition.key, $event)"
      />
    </template>
  </div>
</template>
