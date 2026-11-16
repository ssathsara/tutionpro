<script setup lang="ts">
import { computed } from 'vue'
import ResourcePage from '../components/ResourcePage.vue'
import StatusBadge from '../components/StatusBadge.vue'
import type { ColumnDef, FieldDef } from '../types'
import { useAuthStore } from '../stores/auth'
import { dateTime } from '../utils/format'
import { EP } from '../utils/constants'
import { EXAM_LANGUAGES } from '../i18n/exam'

const LANG_SHORT: Record<string, string> = { si: 'සිංහල', ta: 'தமிழ்', en: 'English' }

const auth = useAuthStore()
const isStaff = computed(() => auth.hasRole('institute_admin', 'teacher'))

const staffColumns: ColumnDef[] = [
  { key: 'title', label: 'Exam' },
  { key: 'batch_name', label: 'Batch' },
  { key: 'language', label: 'Language', format: (v) => LANG_SHORT[v] ?? 'English' },
  { key: 'questions_count', label: 'Questions', format: (v, r) => `${v} (${Number(r.total_marks ?? 0)} marks)` },
  { key: 'duration_minutes', label: 'Duration', format: (v) => `${v} min` },
  { key: 'starts_at', label: 'Window', format: (v, r) => (v || r.ends_at ? `${dateTime(v)} → ${dateTime(r.ends_at)}` : 'Always open') },
  { key: 'attempts_count', label: 'Submissions' },
  { key: 'is_published', label: 'Status' },
]
const learnerColumns: ColumnDef[] = [
  { key: 'title', label: 'Exam' },
  { key: 'batch_name', label: 'Batch' },
  { key: 'language', label: 'Language', format: (v) => LANG_SHORT[v] ?? 'English' },
  { key: 'duration_minutes', label: 'Duration', format: (v) => `${v} min` },
  { key: 'ends_at', label: 'Closes', format: (v) => (v ? dateTime(v) : '—') },
  { key: 'my_attempts', label: 'Result' },
]
const fields: FieldDef[] = [
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches, required: true },
  { key: 'title', label: 'Title', required: true },
  { key: 'language', label: 'Exam language', type: 'select', options: EXAM_LANGUAGES, placeholder: 'Same as batch medium',
    help: 'Students see buttons, timer and results in this language. Write questions in the same language.' },
  { key: 'duration_minutes', label: 'Duration (minutes)', type: 'number', required: true },
  { key: 'pass_percentage', label: 'Pass mark (%)', type: 'number' },
  { key: 'starts_at', label: 'Opens at', type: 'datetime-local' },
  { key: 'ends_at', label: 'Closes at', type: 'datetime-local' },
  { key: 'max_attempts', label: 'Attempts allowed', type: 'number' },
  { key: 'instructions', label: 'Instructions', type: 'textarea' },
  { key: 'shuffle_questions', label: 'Shuffle question order', type: 'checkbox' },
  { key: 'show_results', label: 'Show results & answers after submission', type: 'checkbox' },
  { key: 'is_published', label: 'Published', type: 'checkbox', help: 'Add questions before publishing' },
]
const filters: FieldDef[] = [{ key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches }]
const best = (row: any) => {
  const done = (row.my_attempts ?? []).filter((a: any) => a.status === 'submitted')
  return done.length ? done.reduce((m: any, a: any) => (a.percentage > (m?.percentage ?? -1) ? a : m), null) : null
}
</script>

<template>
  <ResourcePage title="Exams" :subtitle="isStaff ? 'Online MCQ and structured exams with automatic marking' : 'Online exams for your batches'"
    endpoint="/exams" entity-name="exam" :columns="isStaff ? staffColumns : learnerColumns" :fields="fields" :filters="filters"
    :can-create="isStaff" :can-edit="isStaff" :can-delete="isStaff" :searchable="false"
    :defaults="{ duration_minutes: 30, pass_percentage: 40, max_attempts: 1, show_results: true, shuffle_questions: false, is_published: false }"
    :row-link="isStaff ? (r) => `/exams/${r.id}/builder` : undefined">
    <template #cell-is_published="{ row }">
      <span v-if="!row.is_published" class="badge">Draft</span>
      <StatusBadge v-else :value="row.is_open ? 'open' : 'completed'" />
    </template>
    <template #cell-my_attempts="{ row }">
      <template v-if="best(row)">
        <RouterLink :to="`/attempts/${best(row).id}`">{{ best(row).percentage !== undefined && best(row).percentage !== null ? best(row).percentage + '%' : 'Submitted' }}</RouterLink>
      </template>
      <span v-else-if="(row.my_attempts ?? []).some((a: any) => a.status === 'in_progress')" class="badge badge-warning">In progress</span>
      <span v-else class="muted">Not attempted</span>
    </template>
    <template #actions="{ row }">
      <template v-if="isStaff">
        <RouterLink :to="`/exams/${row.id}/builder`" class="btn btn-sm">Questions</RouterLink>
        <RouterLink :to="`/exams/${row.id}/results`" class="btn btn-sm">Results</RouterLink>
      </template>
      <RouterLink v-else-if="auth.role === 'student' && row.is_open && (row.attempts_left > 0 || (row.my_attempts ?? []).some((a: any) => a.status === 'in_progress'))"
        :to="`/exams/${row.id}/take`" class="btn btn-sm btn-primary">{{ (row.my_attempts ?? []).some((a: any) => a.status === 'in_progress') ? 'Resume' : 'Start' }}</RouterLink>
    </template>
  </ResourcePage>
</template>
