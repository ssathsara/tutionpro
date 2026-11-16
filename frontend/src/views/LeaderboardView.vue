<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { EP } from '../utils/constants'
import { useOptions } from '../composables/useOptions'

const route = useRoute()
const { options: batches } = useOptions(EP.batches)
const batchId = ref<number | null>(route.query.batch_id ? Number(route.query.batch_id) : null)
const period = ref('all')
const data = ref<any>(null)
const error = ref('')

async function load() {
  try {
    const params: any = { period: period.value }
    if (batchId.value) params.batch_id = batchId.value
    data.value = (await api.get('/leaderboard', { params })).data
    if (!batchId.value && data.value.batch) batchId.value = data.value.batch.id
  } catch (e) { error.value = errorMessage(e) }
}
onMounted(load)
watch([batchId, period], load)
const medal = (rank: number) => (rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : String(rank))
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div><h1>Leaderboard</h1><p class="sub">Points = exams 60% + attendance 30% + lessons 10% (max 1000)</p></div>
      <div class="row">
        <select v-model="batchId" class="input" aria-label="Batch"><option v-for="b in batches" :key="b.value" :value="b.value">{{ b.label }}</option></select>
        <select v-model="period" class="input" aria-label="Period"><option value="all">All time</option><option value="month">This month</option></select>
      </div>
    </div>
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <div v-if="data" class="card">
      <div v-if="!data.rows.length" class="empty">No students in this batch yet.</div>
      <div v-else class="table-wrap"><table class="table">
        <thead><tr><th>Rank</th><th>Student</th><th class="right">Exam avg</th><th class="right">Attendance</th><th class="right">Lessons</th><th class="right">Points</th></tr></thead>
        <tbody>
          <tr v-for="r in data.rows" :key="r.student_id" :class="{ me: r.is_me }">
            <td class="rank">{{ medal(r.rank) }}</td>
            <td><strong>{{ r.name }}</strong> <span v-if="r.is_me" class="badge badge-info">You</span><div class="tiny">{{ r.exams_taken }} exam(s)</div></td>
            <td class="right mono">{{ r.exam_average }}%</td>
            <td class="right mono">{{ r.attendance_rate }}%</td>
            <td class="right mono">{{ r.lesson_completion }}%</td>
            <td class="right"><strong class="mono pts">{{ r.points }}</strong></td>
          </tr>
        </tbody>
      </table></div>
    </div>
  </div>
</template>

<style scoped>
.rank { font-size: 18px; width: 60px; }
tr.me td { background: var(--primary-50); }
.pts { font-size: 15px; color: var(--primary); }
</style>
