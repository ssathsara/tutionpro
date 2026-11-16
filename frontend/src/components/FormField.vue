<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { FieldDef, SelectOption } from '../types'
import { fetchList, toOptions } from '../composables/useOptions'
import SearchSelect from './SearchSelect.vue'

const props = defineProps<{ field: FieldDef; error?: string }>()
const model = defineModel<any>()

const loaded = ref<SelectOption[]>([])
watch(() => props.field.optionsEndpoint, (ep) => {
  if (!ep) return
  fetchList(ep).then((rows) => { loaded.value = toOptions(rows, props.field.valueKey, props.field.labelKey) }).catch(() => {})
}, { immediate: true })

const options = computed(() => props.field.options ?? loaded.value)
const id = computed(() => 'f-' + props.field.key)

function toggle(v: string | number) {
  const arr: any[] = Array.isArray(model.value) ? [...model.value] : []
  const i = arr.indexOf(v)
  if (i >= 0) arr.splice(i, 1); else arr.push(v)
  model.value = arr
}
function onFile(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  model.value = f ?? null
}
</script>

<template>
  <div class="field" :class="{ full: field.type === 'textarea' || field.type === 'multiselect' || (field.type === 'search' && field.multiple) }">
    <label v-if="field.type !== 'checkbox'" :for="id">{{ field.label }}<span v-if="field.required" class="req"> *</span></label>

    <select v-if="field.type === 'select'" :id="id" v-model="model" class="input" :class="{ invalid: error }">
      <option :value="null">{{ field.placeholder ?? '— Select —' }}</option>
      <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>

    <div v-else-if="field.type === 'multiselect'" class="check-list">
      <label v-for="o in options" :key="o.value" class="checkbox">
        <input type="checkbox" :checked="Array.isArray(model) && model.includes(o.value)" @change="toggle(o.value)" />
        {{ o.label }}
      </label>
      <span v-if="!options.length" class="tiny">Nothing to select yet.</span>
    </div>

    <SearchSelect v-else-if="field.type === 'search'" v-model="model" :input-id="id" :endpoint="field.searchEndpoint!"
      :multiple="field.multiple" :label-key="field.labelKey" :placeholder="field.placeholder ?? 'Type a name or admission number…'" :invalid="!!error" />

    <textarea v-else-if="field.type === 'textarea'" :id="id" v-model="model" class="input" :class="{ invalid: error }" :placeholder="field.placeholder" />

    <label v-else-if="field.type === 'checkbox'" class="checkbox">
      <input :id="id" v-model="model" type="checkbox" /> {{ field.label }}
    </label>

    <input v-else-if="field.type === 'file'" :id="id" type="file" class="input" @change="onFile" />

    <input v-else :id="id" v-model="model" :type="field.type ?? 'text'" class="input" :class="{ invalid: error }"
      :placeholder="field.placeholder" :step="field.type === 'number' ? 'any' : undefined" />

    <span v-if="error" class="error">{{ error }}</span>
    <span v-else-if="field.help" class="help">{{ field.help }}</span>
  </div>
</template>

<style scoped>
.req { color: var(--danger); }
</style>
