<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { lkr, titleCase } from '../utils/format'
import BarChart from '../components/BarChart.vue'

const auth = useAuthStore()
const isAdmin = computed(() => auth.role === 'institute_admin')
const overview = ref<any>(null)
const revenue = ref<any>(null)
const attendance = ref<any>(null)
const exams = ref<any>(null)
const atRisk = ref<any[]>([])
const atRiskTotal = ref(0)
const error = ref('')

onMounted(async () => {
  try {
    const reqs: Promise<any>[] = [api.get('/analytics/overview'), api.get('/analytics/attendance'), api.get('/analytics/exams'), api.get('/analytics/at-risk')]
    if (isAdmin.value) reqs.push(api.get('/analytics/revenue'))
    const [o, a, e, r, rev] = await Promise.all(reqs)
    overview.value = o.data; attendance.value = a.data; exams.value = e.data; atRisk.value = r.data.students; atRiskTotal.value = r.data.total; revenue.value = rev?.data
  } catch (e) { error.value = errorMessage(e) }
})
const methodTotal = computed(() => Object.values(revenue.value?.by_method ?? {}).reduce((s: number, v: any) => s + Number(v), 0))
function exportAtRisk() {
  const rows = [['Student', 'Admission no', 'Mobile', 'Batches', 'Attendance %', 'Exam avg %', 'Reasons']]
  atRisk.value.forEach((s) => rows.push([s.name, s.admission_no, s.phone, s.batches, s.attendance_rate, s.exam_average, s.reasons.join('; ')]))
  const csv = rows.map((r) => r.map((c: any) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n')
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = 'students-needing-attention.csv'; a.click()
}
</script>

<template>
  <div class="page">
    <div class="page-header"><div><h1>Analytics</h1><p class="sub">Learning, attendance and financial insights · figures refresh every 5 minutes</p></div></div>
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <div v-if="!overview && !error" class="loading-block"><span class="spinner" /></div>
    <template v-if="overview">
      <div class="grid grid-4">
        <div class="card stat"><div class="label">Active students</div><div class="value">{{ overview.students }}</div></div>
        <div class="card stat"><div class="label">Attendance (30 days)</div><div class="value">{{ overview.attendance_rate_30d ?? '—' }}<span v-if="overview.attendance_rate_30d !== null">%</span></div></div>
        <div class="card stat"><div class="label">Avg exam score (90 days)</div><div class="value">{{ overview.average_exam_score_90d ?? '—' }}<span v-if="overview.average_exam_score_90d !== null">%</span></div></div>
        <div class="card stat"><div class="label">Need attention</div><div class="value" :style="{ color: atRiskTotal ? 'var(--danger)' : undefined }">{{ atRiskTotal }}</div><div class="hint">students</div></div>
      </div>

      <div v-if="revenue" class="grid grid-2">
        <div class="card">
          <div class="card-header"><h2>Revenue (12 months)</h2></div>
          <div class="card-body"><BarChart :labels="revenue.months.map((m: any) => m.label.slice(0, 3))" :series="[{ name: 'Invoiced', values: revenue.months.map((m: any) => m.invoiced), color: 'var(--border)' }, { name: 'Collected', values: revenue.months.map((m: any) => m.collected) }]" :format="lkr" /></div>
        </div>
        <div class="card">
          <div class="card-header"><h2>Payment methods</h2></div>
          <div class="card-body stack">
            <div v-for="(v, k) in revenue.by_method" :key="k">
              <div class="row small"><span>{{ titleCase(String(k)) }}</span><span class="spacer" /><span class="mono">{{ lkr(v) }}</span></div>
              <div class="progress"><span :style="{ width: (methodTotal ? (Number(v) / methodTotal) * 100 : 0) + '%' }" /></div>
            </div>
            <div v-if="!Object.keys(revenue.by_method).length" class="empty">No payments yet.</div>
          </div>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="card">
          <div class="card-header"><h2>Weekly attendance</h2></div>
          <div class="card-body"><BarChart :labels="attendance.weekly.map((w: any) => w.week)" :series="[{ name: 'Attendance %', values: attendance.weekly.map((w: any) => w.rate) }]" :max="100" :format="(v) => v + '%'" /></div>
        </div>
        <div class="card">
          <div class="card-header"><h2>Attendance by batch (8 weeks)</h2></div>
          <div class="card-body stack">
            <div v-for="b in attendance.by_batch" :key="b.batch_id">
              <div class="row small"><span>{{ b.batch_name }}</span><span class="spacer" /><strong :style="{ color: b.rate < 75 ? 'var(--danger)' : undefined }">{{ b.rate }}%</strong></div>
              <div class="progress"><span :style="{ width: b.rate + '%', background: b.rate < 75 ? 'var(--danger)' : undefined }" /></div>
            </div>
            <div v-if="!attendance.by_batch.length" class="empty">No attendance records yet.</div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2>Exam performance</h2><span class="tiny">Average score and pass rate for recent exams</span></div>
        <div class="card-body">
          <BarChart v-if="exams.exams.length" :labels="exams.exams.map((e: any) => e.title.slice(0, 14))" :series="[{ name: 'Average %', values: exams.exams.map((e: any) => e.average) }, { name: 'Pass rate %', values: exams.exams.map((e: any) => e.pass_rate) }]" :max="100" :format="(v) => v + '%'" />
          <div v-else class="empty">No exam submissions yet.</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2>Students needing attention</h2>
          <span v-if="atRiskTotal > atRisk.length" class="tiny">Showing the {{ atRisk.length }} most serious of {{ atRiskTotal }}</span>
          <span class="spacer" /><button class="btn btn-sm" :disabled="!atRisk.length" @click="exportAtRisk">Export CSV</button></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Student</th><th>Batches</th><th class="right">Attendance</th><th class="right">Exam avg</th><th>Concerns</th></tr></thead>
          <tbody>
            <tr v-for="s in atRisk" :key="s.student_id">
              <td><RouterLink :to="`/students/${s.student_id}`">{{ s.name }}</RouterLink><div class="tiny">{{ s.admission_no }} · {{ s.phone }}</div></td>
              <td class="small">{{ s.batches }}</td>
              <td class="right mono">{{ s.attendance_rate ?? '—' }}<span v-if="s.attendance_rate !== null">%</span></td>
              <td class="right mono">{{ s.exam_average ?? '—' }}<span v-if="s.exam_average !== null">%</span></td>
              <td><span v-for="r in s.reasons" :key="r" class="badge badge-danger" style="margin:2px">{{ r }}</span></td>
            </tr>
            <tr v-if="!atRisk.length"><td colspan="5" class="empty">No students flagged. Everyone is on track.</td></tr>
          </tbody>
        </table></div>
      </div>
    </template>
  </div>
</template>
