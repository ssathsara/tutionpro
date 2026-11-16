<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import ResourcePage from '../components/ResourcePage.vue'
import StatusBadge from '../components/StatusBadge.vue'
import Modal from '../components/Modal.vue'
import type { ColumnDef, FieldDef } from '../types'
import { api, errorMessage } from '../api/client'
import { toast } from '../stores/toast'
import { date } from '../utils/format'
import { EP } from '../utils/constants'

const route = useRoute()
const page = ref<InstanceType<typeof ResourcePage>>()
const query = computed(() => (route.query.batch_id ? { batch_id: route.query.batch_id } : {}))

const statusOpts = [{ value: 'scheduled', label: 'Scheduled' }, { value: 'completed', label: 'Completed' }, { value: 'cancelled', label: 'Cancelled' }]
const typeOpts = [{ value: 'physical', label: 'Physical' }, { value: 'live', label: 'Live (online)' }]
const columns: ColumnDef[] = [
  { key: 'date', label: 'Date', format: (v) => date(v) },
  { key: 'start_time', label: 'Time', format: (v, r) => `${v?.slice(0, 5)}${r.end_time ? '–' + r.end_time.slice(0, 5) : ''}` },
  { key: 'batch_name', label: 'Batch' },
  { key: 'title', label: 'Topic' },
  { key: 'type', label: 'Type' },
  { key: 'status', label: 'Status' },
  { key: 'present_count', label: 'Attendance', format: (v, r) => (r.marked_count ? `${v} / ${r.marked_count}` : '—') },
]
const fields: FieldDef[] = [
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches, required: true },
  { key: 'title', label: 'Topic / title' },
  { key: 'date', label: 'Date', type: 'date', required: true },
  { key: 'type', label: 'Type', type: 'select', options: typeOpts, required: true },
  { key: 'start_time', label: 'Start time', type: 'time', required: true },
  { key: 'end_time', label: 'End time', type: 'time' },
  { key: 'meeting_url', label: 'Meeting link (Zoom / Meet)', type: 'url', help: 'Live classes without a link get a Jitsi room automatically' },
  { key: 'recording_url', label: 'Recording link', type: 'url' },
  { key: 'status', label: 'Status', type: 'select', options: statusOpts },
  { key: 'notes', label: 'Notes', type: 'textarea' },
]
const filters: FieldDef[] = [
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches },
  { key: 'type', label: 'Type', type: 'select', options: typeOpts },
  { key: 'status', label: 'Status', type: 'select', options: statusOpts },
  { key: 'from', label: 'From date', type: 'date' },
]

const today = new Date().toISOString().slice(0, 10)
const showGen = ref(false)
const gen = reactive({ from: today, to: new Date(Date.now() + 13 * 864e5).toISOString().slice(0, 10) })
async function generate() {
  try {
    const { data } = await api.post('/class-sessions/generate', gen)
    toast(data.message); showGen.value = false; page.value?.reload()
  } catch (e) { toast(errorMessage(e), 'error') }
}
</script>

<template>
  <ResourcePage ref="page" title="Classes & Attendance" subtitle="Scheduled classes, live sessions and attendance marking" endpoint="/class-sessions" entity-name="class"
    :columns="columns" :fields="fields" :filters="filters" :query="query" can-create can-edit can-delete :searchable="false"
    :defaults="{ date: today, type: 'physical', status: 'scheduled', start_time: '08:00' }">
    <template #header-actions><button class="btn" @click="showGen = true">Generate from timetable</button></template>
    <template #cell-type="{ row }"><StatusBadge :value="row.type === 'live' ? 'live' : 'physical'" /></template>
    <template #cell-status="{ row }"><StatusBadge :value="row.status" /></template>
    <template #actions="{ row }">
      <RouterLink :to="`/sessions/${row.id}/attendance`" class="btn btn-sm">Mark attendance</RouterLink>
    </template>
  </ResourcePage>

  <Modal v-if="showGen" title="Generate classes from timetable" width="460px" @close="showGen = false">
    <div class="stack">
      <p class="muted small">Creates class sessions for every timetable slot in the date range. Existing sessions are skipped.</p>
      <div class="form-grid">
        <div class="field"><label>From</label><input v-model="gen.from" type="date" class="input" /></div>
        <div class="field"><label>To</label><input v-model="gen.to" type="date" class="input" /></div>
      </div>
    </div>
    <template #footer>
      <button class="btn" @click="showGen = false">Cancel</button>
      <button class="btn btn-primary" @click="generate">Generate</button>
    </template>
  </Modal>
</template>
