<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { toast } from '../stores/toast'
import { date } from '../utils/format'
import { EP } from '../utils/constants'
import StatusBadge from '../components/StatusBadge.vue'
import ResourcePage from '../components/ResourcePage.vue'
import type { ColumnDef, FieldDef } from '../types'

const auth = useAuthStore()
const isStaff = computed(() => auth.hasRole('institute_admin', 'teacher'))
const upcoming = ref<any[]>([])
const recordings = ref<any[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    upcoming.value = (await api.get('/class-sessions', { params: { type: 'live', upcoming: 1, per_page: 30 } })).data.data
    recordings.value = (await api.get('/class-sessions', { params: { type: 'live', status: 'completed', per_page: 30 } })).data.data.filter((s: any) => s.recording_url)
  } finally { loading.value = false }
}
onMounted(() => { if (!isStaff.value) load() })

function isToday(d: string) { return d?.slice(0, 10) === new Date().toLocaleDateString('en-CA') }
async function join(s: any) {
  try {
    const { data } = await api.post(`/class-sessions/${s.id}/join`)
    window.open(data.meeting_url, '_blank', 'noopener')
  } catch (e) { toast(errorMessage(e), 'error') }
}

const columns: ColumnDef[] = [
  { key: 'date', label: 'Date', format: (v) => date(v) },
  { key: 'start_time', label: 'Time', format: (v) => v?.slice(0, 5) },
  { key: 'batch_name', label: 'Batch' },
  { key: 'title', label: 'Topic' },
  { key: 'status', label: 'Status' },
  { key: 'meeting_url', label: 'Link' },
]
const fields: FieldDef[] = [
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches, required: true },
  { key: 'title', label: 'Topic', required: true },
  { key: 'date', label: 'Date', type: 'date', required: true },
  { key: 'start_time', label: 'Start time', type: 'time', required: true },
  { key: 'end_time', label: 'End time', type: 'time' },
  { key: 'meeting_url', label: 'Meeting link', type: 'url', help: 'Zoom / Google Meet link. Leave blank to create a free Jitsi room.' },
  { key: 'recording_url', label: 'Recording link (after class)', type: 'url' },
  { key: 'status', label: 'Status', type: 'select', options: [{ value: 'scheduled', label: 'Scheduled' }, { value: 'completed', label: 'Completed' }, { value: 'cancelled', label: 'Cancelled' }] },
]
const today = new Date().toLocaleDateString('en-CA')
</script>

<template>
  <ResourcePage v-if="isStaff" title="Live Classes" subtitle="Online classes on Zoom, Google Meet or Jitsi. Students are notified and attendance is recorded when they join."
    endpoint="/class-sessions" entity-name="live class" :query="{ type: 'live' }" :columns="columns" :fields="fields" can-create can-edit can-delete
    :searchable="false" :defaults="{ type: 'live', status: 'scheduled', date: today, start_time: '19:00' }"
    :to-payload="(f) => ({ ...f, type: 'live' })">
    <template #cell-status="{ row }"><StatusBadge :value="row.status" /></template>
    <template #cell-meeting_url="{ row }"><a v-if="row.meeting_url" :href="row.meeting_url" target="_blank" rel="noopener" class="small">Open room</a></template>
  </ResourcePage>

  <div v-else class="page">
    <div class="page-header"><div><h1>Live classes</h1><p class="sub">Join online classes. Your attendance is recorded automatically.</p></div></div>
    <div v-if="loading" class="loading-block"><span class="spinner" /></div>
    <template v-else>
      <div class="card">
        <div class="card-header"><h2>Upcoming</h2></div>
        <ul class="list">
          <li v-for="s in upcoming" :key="s.id" class="list-item">
            <div class="when"><div class="strong">{{ date(s.date) }}</div><div class="tiny mono">{{ s.start_time?.slice(0, 5) }}{{ s.end_time ? ' – ' + s.end_time.slice(0, 5) : '' }}</div></div>
            <div><div class="title">{{ s.title ?? 'Live class' }}</div><div class="tiny">{{ s.batch_name }} · {{ s.teacher_name }}</div></div>
            <span class="spacer" />
            <span v-if="isToday(s.date)" class="badge badge-danger">Today</span>
            <button v-if="auth.role === 'student'" class="btn btn-primary btn-sm" :disabled="!isToday(s.date)"
              :title="isToday(s.date) ? 'The room opens 15 minutes before the start time' : 'You can join on the day of the class'" @click="join(s)">Join</button>
          </li>
          <li v-if="!upcoming.length" class="empty">No live classes scheduled.</li>
        </ul>
      </div>
      <div class="card">
        <div class="card-header"><h2>Recordings</h2></div>
        <ul class="list">
          <li v-for="s in recordings" :key="s.id" class="list-item">
            <div><div class="title">{{ s.title ?? 'Live class' }}</div><div class="tiny">{{ s.batch_name }} · {{ date(s.date) }}</div></div>
            <span class="spacer" /><a :href="s.recording_url" target="_blank" rel="noopener" class="btn btn-sm">Watch</a>
          </li>
          <li v-if="!recordings.length" class="empty">No recordings yet.</li>
        </ul>
      </div>
    </template>
  </div>
</template>

<style scoped>
.when { min-width: 110px; }
.strong { font-weight: 600; }
</style>
