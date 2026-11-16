<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { toast } from '../stores/toast'
import { DAYS } from '../utils/format'
import { DAY_OPTIONS, EP } from '../utils/constants'
import Modal from '../components/Modal.vue'
import FormField from '../components/FormField.vue'
import type { FieldDef } from '../types'

const auth = useAuthStore()
const isAdmin = computed(() => auth.role === 'institute_admin')
const slots = ref<any[]>([])
const loading = ref(true)
const show = ref(false)
const editing = ref<any>(null)
const form = reactive<Record<string, any>>({})
const error = ref('')

const fields: FieldDef[] = [
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches, required: true },
  { key: 'day_of_week', label: 'Day', type: 'select', options: DAY_OPTIONS, required: true },
  { key: 'start_time', label: 'Start', type: 'time', required: true },
  { key: 'end_time', label: 'End', type: 'time', required: true },
  { key: 'room', label: 'Room / hall' },
]

async function load() {
  loading.value = true
  try { slots.value = (await api.get('/timetable')).data } finally { loading.value = false }
}
onMounted(load)

// Show Monday first, Sunday last (common in Sri Lankan institutes: weekend classes)
const order = [1, 2, 3, 4, 5, 6, 0]
const byDay = computed(() => order.map((d) => ({ day: d, slots: slots.value.filter((s) => s.day_of_week === d) })))

function open(slot?: any) {
  editing.value = slot ?? null
  Object.assign(form, slot
    ? { batch_id: slot.batch_id, day_of_week: slot.day_of_week, start_time: slot.start_time.slice(0, 5), end_time: slot.end_time.slice(0, 5), room: slot.room }
    : { batch_id: null, day_of_week: 6, start_time: '08:00', end_time: '10:00', room: null })
  error.value = ''; show.value = true
}
async function save() {
  error.value = ''
  try {
    if (editing.value) await api.put(`/timetable/${editing.value.id}`, form)
    else await api.post('/timetable', form)
    toast('Timetable updated'); show.value = false; load()
  } catch (e) { error.value = errorMessage(e) }
}
async function remove() {
  if (!confirm('Remove this slot from the timetable?')) return
  await api.delete(`/timetable/${editing.value.id}`); show.value = false; toast('Slot removed'); load()
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div><h1>Timetable</h1><p class="sub">Weekly class schedule{{ isAdmin ? '. Room and teacher clashes are blocked automatically.' : '' }}</p></div>
      <button v-if="isAdmin" class="btn btn-primary" @click="open()">Add slot</button>
    </div>
    <div v-if="loading" class="loading-block"><span class="spinner" /></div>
    <div v-else class="week">
      <div v-for="d in byDay" :key="d.day" class="card day">
        <div class="day-head">{{ DAYS[d.day] }}</div>
        <div class="day-body">
          <button v-for="s in d.slots" :key="s.id" class="slot" :class="{ clickable: isAdmin }" :disabled="!isAdmin" @click="open(s)">
            <span class="time">{{ s.start_time.slice(0, 5) }} – {{ s.end_time.slice(0, 5) }}</span>
            <span class="name">{{ s.batch_name }}</span>
            <span class="tiny">{{ s.teacher_name ?? '' }}{{ s.room ? ' · ' + s.room : '' }}</span>
          </button>
          <div v-if="!d.slots.length" class="tiny center" style="padding:12px 0">No classes</div>
        </div>
      </div>
    </div>

    <Modal v-if="show" :title="editing ? 'Edit slot' : 'Add slot'" width="520px" @close="show = false">
      <form id="slot-form" class="form-grid" @submit.prevent="save">
        <div v-if="error" class="alert alert-danger full">{{ error }}</div>
        <FormField v-for="f in fields" :key="f.key" v-model="form[f.key]" :field="f" />
      </form>
      <template #footer>
        <button v-if="editing" class="btn btn-danger" type="button" @click="remove">Delete</button>
        <span class="spacer" />
        <button class="btn" type="button" @click="show = false">Cancel</button>
        <button class="btn btn-primary" form="slot-form">Save</button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
.week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 10px; }
@media (max-width: 1100px) { .week { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .week { grid-template-columns: minmax(0, 1fr); } }
.day-head { padding: 10px 12px; font-weight: 650; border-bottom: 1px solid var(--border); font-size: 13px; }
.day-body { padding: 8px; display: flex; flex-direction: column; gap: 6px; }
.slot { text-align: left; border: 1px solid var(--border); border-left: 3px solid var(--primary); background: var(--surface-2); border-radius: 6px; padding: 8px; display: flex; flex-direction: column; gap: 2px; font: inherit; color: var(--text); }
.slot.clickable { cursor: pointer; }
.slot.clickable:hover { border-color: var(--primary); }
.slot:disabled { opacity: 1; }
.time { font-size: 12px; font-weight: 650; color: var(--primary); font-variant-numeric: tabular-nums; }
.name { font-weight: 580; font-size: 13px; }
</style>
