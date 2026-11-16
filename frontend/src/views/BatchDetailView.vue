<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { toast } from '../stores/toast'
import { DAYS, lkr, titleCase } from '../utils/format'
import Modal from '../components/Modal.vue'
import StatusBadge from '../components/StatusBadge.vue'

const route = useRoute()
const auth = useAuthStore()
const isAdmin = computed(() => auth.role === 'institute_admin')
const b = ref<any>(null)
const error = ref('')
const progress = ref<any>(null)
const showEnroll = ref(false)
const selected = ref<number[]>([])
const discount = ref(0)
const filter = ref('')

async function load() {
  try {
    b.value = (await api.get(`/batches/${route.params.id}`)).data
    progress.value = (await api.get('/lessons/progress', { params: { batch_id: route.params.id } })).data
  } catch (e) { error.value = errorMessage(e) }
}
onMounted(load)

// Candidates are searched on the server (works with any number of students).
const candidates = ref<any[]>([])
const candidateTotal = ref(0)
async function searchCandidates() {
  const { data } = await api.get('/students', { params: { not_in_batch: route.params.id, search: filter.value || undefined, status: 'active', per_page: 50 } })
  candidates.value = data.data; candidateTotal.value = data.total
}
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(filter, () => { clearTimeout(searchTimer); searchTimer = setTimeout(searchCandidates, 250) })
async function openEnroll() {
  selected.value = []; discount.value = 0; filter.value = ''; showEnroll.value = true
  await searchCandidates()
}
async function enroll() {
  try {
    await api.post(`/batches/${b.value.id}/students`, { student_ids: selected.value, discount_percent: discount.value })
    toast(`${selected.value.length} student(s) enrolled`); showEnroll.value = false; load()
  } catch (e) { toast(errorMessage(e), 'error') }
}
async function unenroll(s: any) {
  if (!confirm(`Remove ${s.name} from this batch?`)) return
  try { await api.delete(`/batches/${b.value.id}/students/${s.id}`); toast('Student removed'); load() } catch (e) { toast(errorMessage(e), 'error') }
}
async function setDiscount(s: any) {
  const v = prompt(`Discount % for ${s.name} (scholarship / sibling discount)`, String(s.enrollment.discount_percent ?? 0))
  if (v === null) return
  try { await api.put(`/batches/${b.value.id}/students/${s.id}`, { discount_percent: Number(v) }); toast('Discount updated'); load() } catch (e) { toast(errorMessage(e), 'error') }
}
const progressFor = (id: number) => progress.value?.students.find((p: any) => p.student_id === id)?.percent ?? 0
</script>

<template>
  <div class="page">
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <template v-if="b">
      <div class="page-header">
        <div>
          <RouterLink to="/batches" class="small">← Batches</RouterLink>
          <h1>{{ b.name }}</h1>
          <p class="sub">{{ b.subject_name ?? 'No subject' }} · {{ b.teacher_name ?? 'No teacher' }} · {{ b.medium }} medium · {{ titleCase(b.mode) }} · {{ lkr(b.monthly_fee) }}/month</p>
        </div>
        <div class="row">
          <RouterLink :to="`/sessions?batch_id=${b.id}`" class="btn">Classes</RouterLink>
          <RouterLink :to="`/leaderboard?batch_id=${b.id}`" class="btn">Leaderboard</RouterLink>
          <button v-if="isAdmin" class="btn btn-primary" @click="openEnroll">Enroll students</button>
        </div>
      </div>

      <div class="grid grid-4">
        <div class="card stat"><div class="label">Students</div><div class="value">{{ b.students.filter((s: any) => s.enrollment.status === 'active').length }}<span v-if="b.capacity" class="muted small"> / {{ b.capacity }}</span></div></div>
        <div class="card stat"><div class="label">Weekly schedule</div><div class="small" style="margin-top:6px">
          <div v-for="s in b.slots" :key="s.id">{{ DAYS[s.day_of_week] }} {{ s.start_time.slice(0, 5) }}–{{ s.end_time.slice(0, 5) }}</div>
          <span v-if="!b.slots.length" class="muted">Not scheduled</span></div></div>
        <div class="card stat"><div class="label">Room</div><div class="value" style="font-size:18px">{{ b.room ?? '—' }}</div></div>
        <div class="card stat"><div class="label">Published lessons</div><div class="value">{{ progress?.total_lessons ?? 0 }}</div></div>
      </div>

      <div class="card">
        <div class="card-header"><h2>Enrolled students</h2></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Name</th><th>Admission no</th><th>Mobile</th><th>Discount</th><th>Lesson progress</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <tr v-for="s in b.students" :key="s.id">
              <td><RouterLink :to="`/students/${s.id}`">{{ s.name }}</RouterLink></td>
              <td class="mono small">{{ s.admission_no }}</td>
              <td class="small">{{ s.phone ?? '—' }}</td>
              <td>{{ s.enrollment.discount_percent > 0 ? s.enrollment.discount_percent + '%' : '—' }}</td>
              <td style="min-width:120px"><div class="progress"><span :style="{ width: progressFor(s.id) + '%' }" /></div><span class="tiny">{{ progressFor(s.id) }}%</span></td>
              <td><StatusBadge :value="s.enrollment.status" /></td>
              <td class="actions">
                <template v-if="isAdmin">
                  <button class="btn btn-sm btn-ghost" @click="setDiscount(s)">Discount</button>
                  <button class="btn btn-sm btn-ghost btn-danger" @click="unenroll(s)">Remove</button>
                </template>
              </td>
            </tr>
            <tr v-if="!b.students.length"><td colspan="7" class="empty">No students enrolled yet.</td></tr>
          </tbody>
        </table></div>
      </div>
    </template>

    <Modal v-if="showEnroll" title="Enroll students" @close="showEnroll = false">
      <div class="stack">
        <input v-model="filter" class="input" placeholder="Search by name or admission no" />
        <div class="check-list" style="max-height:320px">
          <label v-for="s in candidates" :key="s.id" class="checkbox"><input v-model="selected" type="checkbox" :value="s.id" /> {{ s.name }} <span class="tiny">{{ s.admission_no }}</span></label>
          <span v-if="!candidates.length" class="tiny">No students available.</span>
          <span v-if="candidateTotal > candidates.length" class="tiny">Showing {{ candidates.length }} of {{ candidateTotal }}. Type a name or admission number to narrow down.</span>
        </div>
        <div class="field" style="max-width:220px"><label>Discount (%)</label><input v-model.number="discount" type="number" min="0" max="100" class="input" /></div>
      </div>
      <template #footer>
        <button class="btn" @click="showEnroll = false">Cancel</button>
        <button class="btn btn-primary" :disabled="!selected.length" @click="enroll">Enroll {{ selected.length || '' }}</button>
      </template>
    </Modal>
  </div>
</template>
