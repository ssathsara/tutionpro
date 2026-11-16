<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../api/client'

/**
 * Search-as-you-type picker for large lists (e.g. thousands of students).
 * Queries `endpoint?search=…&per_page=20`; saved values are resolved with `endpoint?ids=1,2`.
 */
const props = withDefaults(defineProps<{
  endpoint: string
  multiple?: boolean
  labelKey?: string | ((row: any) => string)
  placeholder?: string
  invalid?: boolean
  inputId?: string
}>(), { multiple: false, labelKey: 'name', placeholder: 'Type to search…' })
const model = defineModel<any>()

interface Opt { value: number; label: string }
const label = (r: any) => (typeof props.labelKey === 'function' ? props.labelKey(r) : r[props.labelKey])
const query = ref('')
const results = ref<Opt[]>([])
const selected = ref<Opt[]>([])
const open = ref(false)
const loading = ref(false)
const active = ref(0)

const selectedIds = computed<number[]>(() =>
  props.multiple ? (Array.isArray(model.value) ? model.value : []) : (model.value ? [model.value] : []))

// Resolve labels for values set from outside (editing a saved record).
watch(selectedIds, async (ids) => {
  const missing = ids.filter((id) => !selected.value.some((s) => s.value === id))
  selected.value = selected.value.filter((s) => ids.includes(s.value))
  if (!missing.length) return
  try {
    const { data } = await api.get(props.endpoint, { params: { ids: missing.join(','), per_page: missing.length } })
    const rows = Array.isArray(data) ? data : data.data
    selected.value.push(...rows.map((r: any) => ({ value: r.id, label: label(r) })))
  } catch { /* labels stay blank */ }
}, { immediate: true })

let timer: ReturnType<typeof setTimeout> | undefined
let seq = 0
watch(query, (q) => {
  clearTimeout(timer)
  timer = setTimeout(() => search(q), 250)
})
onBeforeUnmount(() => clearTimeout(timer))

async function search(q: string) {
  const mine = ++seq
  loading.value = true
  try {
    const { data } = await api.get(props.endpoint, { params: { search: q || undefined, per_page: 20 } })
    if (mine !== seq) return // a newer search is in flight
    const rows = Array.isArray(data) ? data : data.data
    results.value = rows.map((r: any) => ({ value: r.id, label: label(r) }))
    active.value = 0
  } finally { if (mine === seq) loading.value = false }
}

function focus() { open.value = true; if (!results.value.length) search(query.value) }
function pick(o: Opt) {
  if (props.multiple) {
    if (!selectedIds.value.includes(o.value)) { selected.value.push(o); model.value = [...selectedIds.value, o.value] }
    query.value = ''
  } else {
    selected.value = [o]; model.value = o.value; query.value = ''; open.value = false
  }
}
function remove(id: number) {
  selected.value = selected.value.filter((s) => s.value !== id)
  model.value = props.multiple ? selectedIds.value.filter((x) => x !== id) : null
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') { e.preventDefault(); open.value = true; active.value = Math.min(active.value + 1, results.value.length - 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = Math.max(active.value - 1, 0) }
  else if (e.key === 'Enter' && open.value && results.value[active.value]) { e.preventDefault(); pick(results.value[active.value]) }
  else if (e.key === 'Escape') open.value = false
}
const single = computed(() => (!props.multiple ? selected.value[0] : null))
</script>

<template>
  <div class="ss" @focusout="(e: FocusEvent) => { if (!(e.currentTarget as HTMLElement).contains(e.relatedTarget as Node)) open = false }">
    <div v-if="multiple && selected.length" class="chips">
      <span v-for="s in selected" :key="s.value" class="chip">{{ s.label }}
        <button type="button" :aria-label="`Remove ${s.label}`" @click="remove(s.value)">×</button>
      </span>
    </div>
    <div v-if="single" class="picked input">
      <span>{{ single.label }}</span>
      <button type="button" class="clear" aria-label="Clear selection" @click="remove(single.value)">×</button>
    </div>
    <input v-else :id="inputId" v-model="query" class="input" :class="{ invalid }" :placeholder="placeholder" autocomplete="off"
      role="combobox" :aria-expanded="open" @focus="focus" @keydown="onKey" />
    <ul v-if="open && !single" class="menu card" role="listbox">
      <li v-if="loading && !results.length" class="hint">Searching…</li>
      <li v-else-if="!results.length" class="hint">No matches</li>
      <li v-for="(o, i) in results" :key="o.value" role="option" :aria-selected="i === active" tabindex="-1"
        :class="{ active: i === active, chosen: selectedIds.includes(o.value) }" @mousedown.prevent="pick(o)">
        {{ o.label }}<span v-if="selectedIds.includes(o.value)"> ✓</span>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.ss { position: relative; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.chip { display: inline-flex; align-items: center; gap: 4px; background: var(--primary-50); color: var(--primary); border-radius: 999px; padding: 2px 4px 2px 10px; font-size: 12.5px; font-weight: 550; }
.chip button, .clear { border: none; background: none; cursor: pointer; color: inherit; font-size: 16px; line-height: 1; padding: 0 4px; }
.picked { display: flex; align-items: center; justify-content: space-between; }
.menu { position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 60; list-style: none; margin: 0; padding: 4px; max-height: 260px; overflow-y: auto; box-shadow: var(--shadow-lg); }
.menu li { padding: 7px 10px; border-radius: 6px; cursor: pointer; }
.menu li.active { background: var(--primary-50); }
.menu li.chosen { color: var(--primary); }
.menu .hint { color: var(--text-3); cursor: default; }
</style>
