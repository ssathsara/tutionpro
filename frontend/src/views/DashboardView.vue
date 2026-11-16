<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { date, dateTime, lkr, titleCase } from '../utils/format'
import BarChart from '../components/BarChart.vue'
import StatusBadge from '../components/StatusBadge.vue'

const auth = useAuthStore()
const d = ref<any>(null)
const error = ref('')

onMounted(async () => {
  try { d.value = (await api.get('/dashboard')).data } catch (e) { error.value = errorMessage(e) }
})

const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
})
const firstName = computed(() => (auth.user?.name ?? '').replace(/^(Mr\.|Mrs\.|Ms\.)\s*/, '').split(' ')[0])
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v}%`)
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1>{{ greeting }}, {{ firstName }}</h1>
        <p class="sub">{{ new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}</p>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <div v-if="!d && !error" class="loading-block"><span class="spinner" /> Loading dashboard…</div>

    <template v-if="d">
      <!-- Platform admin -->
      <template v-if="d.role === 'super_admin'">
        <div class="grid grid-4">
          <div class="card stat"><div class="label">Institutes</div><div class="value">{{ d.platform.institutes }}</div><div class="hint">{{ d.platform.active_institutes }} active</div></div>
          <div class="card stat"><div class="label">Students</div><div class="value">{{ d.platform.students }}</div></div>
          <div class="card stat"><div class="label">Teachers</div><div class="value">{{ d.platform.teachers }}</div></div>
          <div class="card stat"><div class="label">Collected this month</div><div class="value">{{ lkr(d.platform.revenue_this_month) }}</div><div class="hint">All institutes</div></div>
        </div>
        <div class="card">
          <div class="card-header"><h2>Newest institutes</h2><RouterLink to="/institutes" class="small">Manage</RouterLink></div>
          <ul class="list"><li v-for="i in d.platform.recent_institutes" :key="i.id" class="list-item"><span class="title">{{ i.name }}</span><span class="muted small">{{ i.city }}</span><span class="spacer" /><span class="badge">{{ titleCase(i.plan) }}</span></li></ul>
        </div>
      </template>

      <!-- Institute admin / teacher -->
      <template v-if="d.role === 'institute_admin' || d.role === 'teacher'">
        <div class="grid grid-4">
          <div class="card stat"><div class="label">Active students</div><div class="value">{{ d.stats.students }}</div><div class="hint">{{ d.stats.active_batches }} active batches</div></div>
          <div class="card stat"><div class="label">Attendance (30 days)</div><div class="value">{{ pct(d.stats.attendance_rate_30d) }}</div><div class="hint">{{ d.stats.sessions_this_week }} classes this week</div></div>
          <div v-if="d.role === 'institute_admin'" class="card stat"><div class="label">Collected this month</div><div class="value">{{ lkr(d.stats.revenue_this_month) }}</div></div>
          <div v-if="d.role === 'institute_admin'" class="card stat"><div class="label">Outstanding fees</div><div class="value">{{ lkr(d.stats.outstanding) }}</div><div class="hint"><RouterLink to="/fees">View invoices</RouterLink></div></div>
          <div v-if="d.role === 'teacher'" class="card stat"><div class="label">Average exam score</div><div class="value">{{ pct(d.stats.average_exam_score_90d) }}</div><div class="hint">Last 90 days</div></div>
          <div v-if="d.role === 'teacher'" class="card stat"><div class="label">Open doubts</div><div class="value">{{ d.open_doubts.length }}</div><div class="hint"><RouterLink to="/discussions">Answer now</RouterLink></div></div>
        </div>

        <div class="grid grid-2">
          <div v-if="d.role === 'institute_admin'" class="card">
            <div class="card-header"><h2>Fee collection</h2><span class="tiny">Last 6 months</span></div>
            <div class="card-body">
              <BarChart :labels="d.revenue.months.map((m: any) => m.label.slice(0, 3))"
                :series="[{ name: 'Invoiced', values: d.revenue.months.map((m: any) => m.invoiced), color: 'var(--border)' }, { name: 'Collected', values: d.revenue.months.map((m: any) => m.collected) }]"
                :format="lkr" />
            </div>
          </div>
          <div v-if="d.role === 'teacher'" class="card">
            <div class="card-header"><h2>My batches</h2></div>
            <ul class="list">
              <li v-for="b in d.batches" :key="b.id" class="list-item">
                <RouterLink :to="`/batches/${b.id}`" class="title">{{ b.name }}</RouterLink>
                <span class="spacer" /><span class="muted small">{{ b.students_count }} students</span>
              </li>
              <li v-if="!d.batches.length" class="empty">No batches assigned yet.</li>
            </ul>
          </div>
          <div class="card">
            <div class="card-header"><h2>Today's classes</h2><RouterLink to="/sessions" class="small">All classes</RouterLink></div>
            <ul class="list">
              <li v-for="s in d.today_sessions" :key="s.id" class="list-item">
                <span class="mono nowrap">{{ s.start_time?.slice(0, 5) }}</span>
                <div><div class="title">{{ s.batch_name }}</div><div class="tiny">{{ s.title ?? (s.type === 'live' ? 'Live class' : 'Class') }}</div></div>
                <span class="spacer" />
                <StatusBadge :value="s.type === 'live' ? 'live' : s.status" />
                <RouterLink :to="`/sessions/${s.id}/attendance`" class="btn btn-sm">Attendance</RouterLink>
              </li>
              <li v-if="!d.today_sessions.length" class="empty">No classes today.</li>
            </ul>
          </div>
        </div>

        <div class="grid grid-2">
          <div v-if="d.role === 'institute_admin'" class="card">
            <div class="card-header"><h2>Recent payments</h2><RouterLink to="/fees" class="small">Fees</RouterLink></div>
            <ul class="list">
              <li v-for="p in d.recent_payments" :key="p.id" class="list-item">
                <div><div class="title">{{ p.student_name }}</div><div class="tiny">{{ p.receipt_no }} · {{ titleCase(p.method) }} · {{ dateTime(p.paid_at) }}</div></div>
                <span class="spacer" /><strong class="mono">{{ lkr(p.amount) }}</strong>
              </li>
              <li v-if="!d.recent_payments.length" class="empty">No payments yet.</li>
            </ul>
          </div>
          <div v-if="d.role === 'teacher'" class="card">
            <div class="card-header"><h2>Open doubts</h2></div>
            <ul class="list">
              <li v-for="t in d.open_doubts" :key="t.id" class="list-item">
                <div><RouterLink :to="`/discussions/${t.id}`" class="title">{{ t.title }}</RouterLink><div class="tiny">{{ t.user?.name }} · {{ t.batch?.name }}</div></div>
              </li>
              <li v-if="!d.open_doubts.length" class="empty">No open doubts. Nice work!</li>
            </ul>
          </div>
          <div class="card">
            <div class="card-header"><h2>Announcements</h2><RouterLink to="/announcements" class="small">All</RouterLink></div>
            <ul class="list">
              <li v-for="a in d.announcements" :key="a.id" class="list-item" style="align-items:flex-start">
                <div><div class="title">{{ a.title }} <span v-if="a.is_pinned" class="badge badge-info">Pinned</span></div><div class="small muted">{{ a.body }}</div></div>
              </li>
              <li v-if="!d.announcements.length" class="empty">No announcements.</li>
            </ul>
          </div>
        </div>
      </template>

      <!-- Student / parent -->
      <template v-if="d.role === 'student' || d.role === 'parent'">
        <div v-if="d.role === 'parent' && d.children?.length" class="grid grid-2">
          <div v-for="c in d.children" :key="c.id" class="card card-body stack">
            <div class="row"><span class="avatar">{{ c.name?.[0] }}</span><div><h3>{{ c.name }}</h3><div class="tiny">{{ c.admission_no }} · {{ c.grade }}</div></div><span class="spacer" /><RouterLink :to="`/students/${c.id}`" class="btn btn-sm">Full report</RouterLink></div>
            <div class="grid grid-2">
              <div><div class="tiny">Attendance</div><div class="value-sm">{{ pct(c.attendance_rate) }}</div></div>
              <div><div class="tiny">Outstanding fees</div><div class="value-sm" :class="{ danger: c.outstanding > 0 }">{{ lkr(c.outstanding) }}</div></div>
            </div>
            <div v-if="c.latest_results.length" class="small">
              <div class="tiny">Latest results</div>
              <div v-for="r in c.latest_results" :key="r.id" class="row"><span>{{ r.exam?.title }}</span><span class="spacer" /><strong>{{ r.percentage }}%</strong></div>
            </div>
          </div>
        </div>

        <div class="grid grid-4">
          <div class="card stat"><div class="label">My batches</div><div class="value">{{ d.stats.batches }}</div></div>
          <div class="card stat"><div class="label">Attendance</div><div class="value">{{ pct(d.stats.attendance_rate) }}</div></div>
          <div class="card stat"><div class="label">Outstanding fees</div><div class="value">{{ lkr(d.stats.outstanding) }}</div><div class="hint"><RouterLink to="/fees">Pay now</RouterLink></div></div>
          <div class="card stat"><div class="label">Lessons completed</div><div class="value">{{ d.stats.lessons_completed }}<span class="muted small"> / {{ d.stats.lessons_total }}</span></div></div>
        </div>

        <div class="grid grid-2">
          <div class="card">
            <div class="card-header"><h2>Upcoming classes</h2><RouterLink to="/live" class="small">Live classes</RouterLink></div>
            <ul class="list">
              <li v-for="s in d.upcoming_sessions" :key="s.id" class="list-item">
                <div class="nowrap"><div class="small strong">{{ date(s.date) }}</div><div class="tiny mono">{{ s.start_time?.slice(0, 5) }}</div></div>
                <div><div class="title">{{ s.batch_name }}</div><div class="tiny">{{ s.title ?? 'Class' }}</div></div>
                <span class="spacer" /><StatusBadge :value="s.type === 'live' ? 'live' : 'scheduled'" />
              </li>
              <li v-if="!d.upcoming_sessions.length" class="empty">No classes in the next 7 days.</li>
            </ul>
          </div>
          <div class="card">
            <div class="card-header"><h2>Exams</h2><RouterLink to="/exams" class="small">All exams</RouterLink></div>
            <ul class="list">
              <li v-for="e in d.open_exams" :key="e.id" class="list-item">
                <div><div class="title">{{ e.title }}</div><div class="tiny">{{ e.batch?.name }} · {{ e.duration_minutes }} min<span v-if="e.ends_at"> · closes {{ dateTime(e.ends_at) }}</span></div></div>
                <span class="spacer" />
                <RouterLink v-if="d.role === 'student'" :to="`/exams/${e.id}/take`" class="btn btn-sm btn-primary">Start</RouterLink>
              </li>
              <li v-if="!d.open_exams.length" class="empty">No open exams.</li>
            </ul>
          </div>
        </div>

        <div class="grid grid-2">
          <div class="card">
            <div class="card-header"><h2>Pending fees</h2><RouterLink to="/fees" class="small">Fees</RouterLink></div>
            <ul class="list">
              <li v-for="i in d.pending_invoices" :key="i.id" class="list-item">
                <div><div class="title">{{ i.description ?? i.invoice_no }}</div><div class="tiny">{{ i.student?.user?.name }} · due {{ date(i.due_date) }}</div></div>
                <span class="spacer" /><strong class="mono">{{ lkr(i.balance) }}</strong>
              </li>
              <li v-if="!d.pending_invoices.length" class="empty">All fees are paid. Thank you!</li>
            </ul>
          </div>
          <div class="card">
            <div class="card-header"><h2>Announcements</h2><RouterLink to="/announcements" class="small">All</RouterLink></div>
            <ul class="list">
              <li v-for="a in d.announcements" :key="a.id" class="list-item" style="align-items:flex-start">
                <div><div class="title">{{ a.title }} <span v-if="a.is_pinned" class="badge badge-info">Pinned</span></div><div class="small muted">{{ a.body }}</div></div>
              </li>
              <li v-if="!d.announcements.length" class="empty">No announcements.</li>
            </ul>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
.value-sm { font-size: 18px; font-weight: 650; }
.danger { color: var(--danger); }
.strong { font-weight: 600; }
</style>
