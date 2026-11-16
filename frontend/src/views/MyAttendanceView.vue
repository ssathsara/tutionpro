<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { date } from '../utils/format'
import StatusBadge from '../components/StatusBadge.vue'

const auth = useAuthStore()
const children = ref<any[]>([])
const studentId = ref<number | null>(auth.user?.student_id ?? null)
const data = ref<any>(null)
const error = ref('')

onMounted(async () => {
  if (auth.role === 'parent') {
    children.value = (await api.get('/parent/children')).data
    studentId.value = children.value[0]?.id ?? null
  }
})
watch(studentId, async (id) => {
  if (!id) return
  data.value = null
  try { data.value = (await api.get(`/students/${id}/attendance`)).data } catch (e) { error.value = errorMessage(e) }
}, { immediate: true })
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div><h1>Attendance</h1><p class="sub">Class attendance history</p></div>
      <select v-if="children.length > 1" v-model="studentId" class="input" style="max-width:240px" aria-label="Child">
        <option v-for="c in children" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
    </div>
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <div v-if="auth.role === 'parent' && !children.length" class="empty card">No children are linked to your account yet.</div>
    <template v-if="data">
      <div class="grid grid-4">
        <div class="card stat"><div class="label">Attendance rate</div><div class="value">{{ data.summary.rate ?? '—' }}<span v-if="data.summary.rate !== null">%</span></div></div>
        <div class="card stat"><div class="label">Present</div><div class="value">{{ data.summary.present }}</div></div>
        <div class="card stat"><div class="label">Late</div><div class="value">{{ data.summary.late }}</div></div>
        <div class="card stat"><div class="label">Absent</div><div class="value">{{ data.summary.absent }}</div></div>
      </div>
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Date</th><th>Batch</th><th>Class</th><th>Status</th><th>How</th></tr></thead>
          <tbody>
            <tr v-for="r in data.records" :key="r.id">
              <td>{{ date(r.session?.date) }}</td><td>{{ r.session?.batch?.name }}</td>
              <td class="small">{{ r.session?.title ?? (r.session?.type === 'live' ? 'Live class' : 'Class') }}</td>
              <td><StatusBadge :value="r.status" /></td><td class="small muted">{{ r.method === 'online' ? 'Online' : 'In class' }}</td>
            </tr>
            <tr v-if="!data.records.length"><td colspan="5" class="empty">No attendance records yet.</td></tr>
          </tbody>
        </table></div>
      </div>
    </template>
  </div>
</template>
