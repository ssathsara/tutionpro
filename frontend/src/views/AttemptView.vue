<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { dateTime, titleCase } from '../utils/format'
import { et, normalizeLang, type ExamStringKey } from '../i18n/exam'

const route = useRoute()
const auth = useAuthStore()
const a = ref<any>(null)
const error = ref('')
onMounted(async () => {
  try { a.value = (await api.get(`/attempts/${route.params.id}`)).data } catch (e) { error.value = errorMessage(e) }
})

// Learners see the result in the exam's language; staff keep the English interface.
const lang = computed(() => (auth.hasRole('student', 'parent') ? normalizeLang(a.value?.exam?.language) : 'en'))
const t = (key: ExamStringKey, params?: Record<string, string | number>) => et(lang.value, key, params)

function fmt(q: any, v: any) {
  if (v === null || v === undefined || (Array.isArray(v) && !v.length)) return t('noAnswer')
  const arr = Array.isArray(v) ? v : [v]
  switch (q.type) {
    case 'mcq': case 'multi_select': return arr.map((i: number) => q.options?.[i] ?? i).join(', ')
    case 'true_false': return arr[0] === true || arr[0] === 'true' ? t('true') : t('false')
    case 'numeric': return q.correct_answer === v && arr[1] ? `${arr[0]} ± ${arr[1]}` : String(arr[0])
    default: return arr.join(' / ')
  }
}
</script>

<template>
  <div class="page" :lang="normalizeLang(a?.exam?.language)">
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <template v-if="a">
      <div class="page-header">
        <div>
          <RouterLink to="/exams" class="small">← {{ t('back') }}</RouterLink>
          <h1>{{ a.exam.title }}</h1>
          <p class="sub">{{ a.student_name }} · {{ t('submittedAt') }} {{ dateTime(a.submitted_at) }}</p>
        </div>
      </div>

      <div v-if="a.percentage !== null" class="card card-body result">
        <div class="score">{{ a.percentage }}<span>%</span></div>
        <div>
          <div class="big">{{ t('scoreOf', { s: a.score, t: a.total_marks }) }}</div>
          <span :class="a.passed ? 'badge badge-success' : 'badge badge-danger'">{{ a.passed ? t('passed') : t('failed') }}</span>
          <div class="tiny">{{ t('passMark', { p: a.exam.pass_percentage }) }}</div>
        </div>
      </div>
      <div v-else class="alert">{{ t('hidden') }}</div>

      <div v-for="(q, i) in a.questions" :key="q.id" class="card card-body stack qcard" :class="q.is_correct ? 'ok' : 'bad'">
        <div class="row">
          <strong>Q{{ i + 1 }}</strong><span v-if="lang === 'en'" class="badge">{{ titleCase(q.type) }}</span>
          <span class="spacer" /><span :class="q.is_correct ? 'badge badge-success' : 'badge badge-danger'">{{ q.marks_awarded }} / {{ q.marks }}</span>
        </div>
        <div class="pre qtext">{{ q.question }}</div>
        <div class="small"><span class="muted">{{ t('yourAnswer') }}:</span> {{ fmt(q, q.your_answer) }}</div>
        <div v-if="!q.is_correct" class="small"><span class="muted">{{ t('correctAnswer') }}:</span> <strong>{{ fmt(q, q.correct_answer) }}</strong></div>
        <div v-if="q.explanation" class="alert small pre"><strong>{{ t('explanation') }}:</strong> {{ q.explanation }}</div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.result { display: flex; align-items: center; gap: 24px; }
.score { font-size: 52px; font-weight: 800; color: var(--primary); line-height: 1; }
.score span { font-size: 24px; }
.big { font-size: 18px; font-weight: 650; margin-bottom: 4px; }
.qcard { border-left: 4px solid var(--border); }
.qcard.ok { border-left-color: var(--success); }
.qcard.bad { border-left-color: var(--danger); }
.qtext { font-size: 15px; }
</style>
