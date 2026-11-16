<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { toast } from '../stores/toast'
import { date } from '../utils/format'

const route = useRoute()
const router = useRouter()
const session = ref<any>(null)
const students = ref<any[]>([])
const saving = ref(false)
const error = ref('')
const STATUSES = [
  { v: 'present', l: 'Present', k: 'P' }, { v: 'late', l: 'Late', k: 'L' },
  { v: 'absent', l: 'Absent', k: 'A' }, { v: 'excused', l: 'Excused', k: 'E' },
]

onMounted(async () => {
  try {
    const { data } = await api.get(`/class-sessions/${route.params.id}/attendance`)
    session.value = data.session
    students.value = data.students
  } catch (e) { error.value = errorMessage(e) }
})

const counts = computed(() => {
  const c: Record<string, number> = { present: 0, late: 0, absent: 0, excused: 0, unmarked: 0 }
  for (const s of students.value) c[s.status ?? 'unmarked']++
  return c
})
function markAll(status: string) { for (const s of students.value) if (!s.status || status === 'present') s.status = status }

async function save() {
  const records = students.value.filter((s) => s.status).map((s) => ({ student_id: s.student_id, status: s.status, remarks: s.remarks }))
  if (!records.length) { toast('Mark at least one student', 'error'); return }
  saving.value = true
  try {
    const { data } = await api.post(`/class-sessions/${route.params.id}/attendance`, { records })
    toast(data.absent_alerts ? `Attendance saved. ${data.absent_alerts} parent alert(s) sent.` : 'Attendance saved')
    router.push('/sessions')
  } catch (e) { toast(errorMessage(e), 'error') } finally { saving.value = false }
}
</script>

<template>
  <div class="page">
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <template v-if="session">
      <div class="page-header">
        <div>
          <RouterLink to="/sessions" class="small">← Classes</RouterLink>
          <h1>{{ session.batch_name }}</h1>
          <p class="sub">{{ date(session.date) }} · {{ session.start_time?.slice(0, 5) }} · {{ session.title ?? (session.type === 'live' ? 'Live class' : 'Class') }}</p>
        </div>
        <div class="row">
          <button class="btn" @click="markAll('present')">Mark all present</button>
          <button class="btn btn-primary" :disabled="saving" @click="save"><span v-if="saving" class="spinner" /> Save attendance</button>
        </div>
      </div>

      <div class="row small">
        <span class="badge badge-success">Present {{ counts.present }}</span>
        <span class="badge badge-warning">Late {{ counts.late }}</span>
        <span class="badge badge-danger">Absent {{ counts.absent }}</span>
        <span class="badge badge-info">Excused {{ counts.excused }}</span>
        <span class="badge">Unmarked {{ counts.unmarked }}</span>
        <span class="tiny">Parents of absent students receive an SMS alert.</span>
      </div>

      <div class="card">
        <div v-if="!students.length" class="empty">No students are enrolled in this batch.</div>
        <ul v-else class="list">
          <li v-for="s in students" :key="s.student_id" class="list-item att-row">
            <span class="avatar">{{ s.name?.[0] }}</span>
            <div class="who"><div class="title">{{ s.name }}</div><div class="tiny">{{ s.admission_no }}<span v-if="s.method === 'online'"> · joined online</span></div></div>
            <div class="seg" role="radiogroup" :aria-label="`Attendance for ${s.name}`">
              <button v-for="st in STATUSES" :key="st.v" type="button" class="seg-btn" :class="[st.v, { on: s.status === st.v }]"
                role="radio" :aria-checked="s.status === st.v" :title="st.l" @click="s.status = st.v">
                <span class="k">{{ st.k }}</span><span class="l">{{ st.l }}</span>
              </button>
            </div>
            <input v-model="s.remarks" class="input remarks" placeholder="Remarks" aria-label="Remarks" />
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>

<style scoped>
.att-row { flex-wrap: wrap; }
.who { min-width: 160px; flex: 1; }
.seg { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.seg-btn { border: none; background: var(--surface); padding: 6px 12px; cursor: pointer; font: inherit; font-weight: 600; color: var(--text-2); border-right: 1px solid var(--border); display: inline-flex; gap: 6px; }
.seg-btn:last-child { border-right: none; }
.seg-btn .k { display: none; }
.seg-btn.on.present { background: var(--success-50); color: var(--success); }
.seg-btn.on.late { background: var(--warning-50); color: var(--warning); }
.seg-btn.on.absent { background: var(--danger-50); color: var(--danger); }
.seg-btn.on.excused { background: var(--info-50); color: var(--primary); }
.remarks { max-width: 200px; }
@media (max-width: 640px) {
  .seg-btn .k { display: inline; } .seg-btn .l { display: none; }
  .remarks { max-width: none; flex-basis: 100%; }
}
</style>
