<script setup lang="ts">
import { ref, watch } from 'vue'
import type { FieldDef, SelectOption } from '../types'
import { fetchList, toOptions } from '../composables/useOptions'

/** Renders <option> elements for a filter field (static or endpoint-backed). */
const props = defineProps<{ field: FieldDef }>()
const opts = ref<SelectOption[]>(props.field.options ?? [])

watch(() => props.field.optionsEndpoint, (ep) => {
  if (ep) fetchList(ep).then((r) => { opts.value = toOptions(r, props.field.valueKey, props.field.labelKey) }).catch(() => {})
}, { immediate: true })
</script>

<template>
  <option v-for="o in opts" :key="o.value" :value="o.value">{{ o.label }}</option>
</template>
