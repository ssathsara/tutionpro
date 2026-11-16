<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { toast } from '../stores/toast'
import { dateTime } from '../utils/format'
import StatusBadge from '../components/StatusBadge.vue'
import BarChart from '../components/BarChart.vue'
import Modal from '../components/Modal.vue'

const route = useRoute()
const r = ref<any>(null)
const error = ref('')
const showCert = ref(false)
const cert = reactive({ title: '', type: 'merit', min_percentage: 75 })

onMounted(async () => {
  try {
    r.value = (await api.get(`/exams/${route.params.id}/results`)).data
    cert.title = `Certificate of Merit - ${r.value.exam.title}`
  } catch (e) { error.value = errorMessage(e) }
})
async function issue() {
  try {
    const { data } = await api.post(`/exams/${route.params.id}/certificates`, cert)
    toast(data.message); showCert.value = false
  } catch (e) { toast(errorMessage(e), 'error') }
}
function exportCsv() {
  const rows = [['Rank', 'Student', 'Admission no', 'Score', 'Total', 'Percentage', 'Result', 'Submitted']]
  r.value.attempts.forEach((a: any, i: number) => rows.push([i + 1, a.student_name, a.admission_no, a.score, a.total_marks, a.percentage, a.passed ? 'Pass' : 'Fail', a.submitted_at]))
  const csv = rows.map((row) => row.map((c: any) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n')
  const link = document.createElement('a')
  link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
  link.download = `${r.value.exam.title}-results.csv`
  link.click()
}
</script>

<template>
  <div class="page">
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <template v-if="r">
      <div class="page-header">
        <div><RouterLink to="/exams" class="small">← Exams</RouterLink><h1>{{ r.exam.title }} · Results</h1></div>
        <div class="row">
          <button class="btn" :disabled="!r.attempts.length" @click="exportCsv">Export CSV</button>
          <button class="btn btn-primary" :disabled="!r.attempts.length" @click="showCert = true">Issue certificates</button>
        </div>
      </div>
      <div class="grid grid-4">
        <div class="card stat"><div class="label">Attempted</div><div class="value">{{ r.stats.attempted }} <span class="muted small">/ {{ r.stats.enrolled }}</span></div></div>
        <div class="card stat"><div class="label">Average</div><div class="value">{{ r.stats.average ?? '—' }}<span v-if="r.stats.average !== null">%</span></div></div>
        <div class="card stat"><div class="label">Highest / lowest</div><div class="value">{{ r.stats.highest ?? '—' }} / {{ r.stats.lowest ?? '—' }}</div></div>
        <div class="card stat"><div class="label">Pass rate</div><div class="value">{{ r.stats.pass_rate ?? '—' }}<span v-if="r.stats.pass_rate !== null">%</span></div></div>
      </div>

      <div class="card">
        <div class="card-header"><h2>Question analysis</h2><span class="tiny">% of students who answered correctly</span></div>
        <div class="card-body">
          <BarChart :labels="r.questions.map((_: any, i: number) => 'Q' + (i + 1))" :series="[{ name: 'Correct', values: r.questions.map((q: any) => q.correct_rate) }]" :max="100" :format="(v) => v + '%'" :height="180" />
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2>Submissions</h2></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>#</th><th>Student</th><th>Score</th><th>%</th><th>Result</th><th>Time taken</th><th>Submitted</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(a, i) in r.attempts" :key="a.id">
              <td>{{ i + 1 }}</td><td>{{ a.student_name }} <span class="tiny">{{ a.admission_no }}</span></td>
              <td class="mono">{{ a.score }} / {{ a.total_marks }}</td><td class="mono">{{ a.percentage }}%</td>
              <td><StatusBadge :value="a.passed ? 'passed' : 'failed'" /></td>
              <td class="small">{{ a.duration_minutes ?? '—' }} min</td><td class="small">{{ dateTime(a.submitted_at) }}</td>
              <td class="actions"><RouterLink :to="`/attempts/${a.id}`" class="btn btn-sm">View</RouterLink></td>
            </tr>
            <tr v-if="!r.attempts.length"><td colspan="8" class="empty">No submissions yet.</td></tr>
          </tbody>
        </table></div>
      </div>
    </template>

    <Modal v-if="showCert" title="Issue certificates" width="480px" @close="showCert = false">
      <div class="stack">
        <p class="small muted">Issues a certificate to every student who scored at least the minimum percentage. Students who already have one for this exam are skipped.</p>
        <div class="field"><label>Certificate title</label><input v-model="cert.title" class="input" /></div>
        <div class="form-grid">
          <div class="field"><label>Type</label><select v-model="cert.type" class="input"><option value="merit">Merit</option><option value="completion">Completion</option><option value="participation">Participation</option></select></div>
          <div class="field"><label>Minimum %</label><input v-model.number="cert.min_percentage" type="number" min="0" max="100" class="input" /></div>
        </div>
      </div>
      <template #footer><button class="btn" @click="showCert = false">Cancel</button><button class="btn btn-primary" @click="issue">Issue</button></template>
    </Modal>
  </div>
</template>
