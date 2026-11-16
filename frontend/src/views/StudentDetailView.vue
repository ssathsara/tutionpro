<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { date, dateTime, lkr } from '../utils/format'
import StatusBadge from '../components/StatusBadge.vue'

const route = useRoute()
const s = ref<any>(null)
const error = ref('')
onMounted(async () => {
  try { s.value = (await api.get(`/students/${route.params.id}`)).data } catch (e) { error.value = errorMessage(e) }
})
</script>

<template>
  <div class="page">
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <div v-if="!s && !error" class="loading-block"><span class="spinner" /></div>
    <template v-if="s">
      <div class="page-header">
        <div class="row">
          <span class="avatar" style="width:48px;height:48px;font-size:18px">{{ s.name?.[0] }}</span>
          <div><h1>{{ s.name }}</h1><p class="sub">{{ s.admission_no }} · {{ s.grade ?? '—' }} · {{ s.school ?? 'School not set' }}</p></div>
        </div>
        <StatusBadge :value="s.status" />
      </div>

      <div class="grid grid-4">
        <div class="card stat"><div class="label">Attendance</div><div class="value">{{ s.attendance.rate ?? '—' }}<span v-if="s.attendance.rate !== null">%</span></div><div class="hint">{{ s.attendance.present }} of {{ s.attendance.total }} classes</div></div>
        <div v-if="s.can_see_fees" class="card stat"><div class="label">Outstanding</div><div class="value">{{ lkr(s.outstanding) }}</div></div>
        <div class="card stat"><div class="label">Batches</div><div class="value">{{ s.batches.length }}</div></div>
        <div class="card stat"><div class="label">Exams taken</div><div class="value">{{ s.exam_results.length }}</div></div>
      </div>

      <div class="grid grid-2">
        <div class="card">
          <div class="card-header"><h2>Contact</h2></div>
          <div class="card-body stack small">
            <div><span class="muted">Email:</span> {{ s.email }}</div>
            <div><span class="muted">Mobile:</span> {{ s.phone ?? '—' }}</div>
            <div><span class="muted">Guardian:</span> {{ s.guardian_name ?? '—' }} {{ s.guardian_phone ? '· ' + s.guardian_phone : '' }}</div>
            <div><span class="muted">Parent accounts:</span> {{ s.guardians.map((g: any) => g.name).join(', ') || '—' }}</div>
            <div><span class="muted">Date of birth:</span> {{ date(s.date_of_birth) }}</div>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><h2>Batches</h2></div>
          <ul class="list">
            <li v-for="b in s.batches" :key="b.id" class="list-item">
              <div><div class="title">{{ b.name }}</div><div class="tiny">{{ b.teacher?.user?.name ?? 'No teacher' }} · {{ b.medium }} medium</div></div>
              <span class="spacer" /><span v-if="s.can_see_fees" class="mono small">{{ lkr(b.monthly_fee) }}/mo</span>
            </li>
            <li v-if="!s.batches.length" class="empty">Not enrolled in any batch.</li>
          </ul>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="card">
          <div class="card-header"><h2>Exam results</h2></div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Exam</th><th>Score</th><th>Date</th></tr></thead>
            <tbody>
              <tr v-for="r in s.exam_results" :key="r.id">
                <td><RouterLink :to="`/attempts/${r.id}`">{{ r.exam?.title }}</RouterLink></td>
                <td><span v-if="r.exam?.show_results">{{ r.percentage }}% <StatusBadge :value="r.percentage >= r.exam.pass_percentage ? 'passed' : 'failed'" /></span><span v-else class="muted">Hidden</span></td>
                <td class="small">{{ dateTime(r.submitted_at) }}</td>
              </tr>
              <tr v-if="!s.exam_results.length"><td colspan="3" class="empty">No exams yet.</td></tr>
            </tbody>
          </table></div>
        </div>
        <div class="card">
          <div class="card-header"><h2>Recent attendance</h2></div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Batch</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="a in s.recent_attendance" :key="a.id">
                <td class="small">{{ date(a.session?.date) }}</td><td class="small">{{ a.session?.batch?.name }}</td><td><StatusBadge :value="a.status" /></td>
              </tr>
              <tr v-if="!s.recent_attendance.length"><td colspan="3" class="empty">No attendance records.</td></tr>
            </tbody>
          </table></div>
        </div>
      </div>

      <div v-if="s.can_see_fees" class="card">
        <div class="card-header"><h2>Invoices</h2></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Invoice</th><th>Description</th><th>Due</th><th class="right">Amount</th><th class="right">Balance</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="i in s.invoices" :key="i.id">
              <td class="mono small">{{ i.invoice_no }}</td><td>{{ i.description }}</td><td class="small">{{ date(i.due_date) }}</td>
              <td class="right mono">{{ lkr(i.amount - i.discount) }}</td><td class="right mono">{{ lkr(i.balance) }}</td><td><StatusBadge :value="i.status" /></td>
            </tr>
            <tr v-if="!s.invoices.length"><td colspan="6" class="empty">No invoices.</td></tr>
          </tbody>
        </table></div>
      </div>
    </template>
  </div>
</template>
