<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { toast } from '../stores/toast'
import { et, normalizeLang, type ExamStringKey } from '../i18n/exam'

const route = useRoute()
const router = useRouter()
const data = ref<any>(null)
const answers = ref<Record<number, any>>({})
const error = ref('')
const remaining = ref(0)
const submitting = ref(false)
const current = ref(0)
let offset = 0
let tick: ReturnType<typeof setInterval> | undefined
let autosave: ReturnType<typeof setInterval> | undefined
let dirty = false

const lang = computed(() => normalizeLang(data.value?.exam?.language))
const t = (key: ExamStringKey, params?: Record<string, string | number>) => et(lang.value, key, params)

onMounted(async () => {
  try {
    const { data: d } = await api.post(`/exams/${route.params.id}/start`)
    data.value = d
    // Restore autosaved answers (stored as arrays server-side)
    for (const q of d.questions) {
      const v = d.answers?.[q.id]
      if (v === undefined || v === null) continue
      answers.value[q.id] = q.type === 'multi_select' ? v : v[0]
    }
    offset = new Date(d.attempt.server_time).getTime() - Date.now()
    updateRemaining()
    tick = setInterval(updateRemaining, 1000)
    autosave = setInterval(save, 20000)
    window.addEventListener('beforeunload', warn)
  } catch (e) { error.value = errorMessage(e) }
})
onBeforeUnmount(() => { clearInterval(tick); clearInterval(autosave); window.removeEventListener('beforeunload', warn) })

function warn(e: BeforeUnloadEvent) { if (data.value && !submitting.value) { e.preventDefault() } }
function updateRemaining() {
  if (!data.value) return
  remaining.value = Math.max(0, Math.floor((new Date(data.value.attempt.deadline).getTime() - (Date.now() + offset)) / 1000))
  if (remaining.value === 0 && !submitting.value) submit(true)
}
const clock = computed(() => `${String(Math.floor(remaining.value / 60)).padStart(2, '0')}:${String(remaining.value % 60).padStart(2, '0')}`)
const answeredCount = computed(() => (data.value?.questions ?? []).filter((q: any) => isAnswered(q)).length)
function isAnswered(q: any) { const v = answers.value[q.id]; return !(v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)) }
function set(q: any, v: any) { answers.value[q.id] = v; dirty = true }
function toggleMulti(q: any, i: number) {
  const arr = Array.isArray(answers.value[q.id]) ? [...answers.value[q.id]] : []
  const at = arr.indexOf(i); if (at >= 0) arr.splice(at, 1); else arr.push(i)
  set(q, arr)
}
/** True/False labels follow the exam language rather than the stored English option text. */
function optionLabel(q: any, o: string, oi: number) {
  return q.type === 'true_false' ? t(oi === 0 ? 'true' : 'false') : o
}
async function save() {
  if (!dirty || !data.value) return
  try { await api.put(`/attempts/${data.value.attempt.id}/answers`, { answers: answers.value }); dirty = false } catch { /* retry next tick */ }
}
async function submit(auto = false) {
  const total = data.value.questions.length
  if (!auto && answeredCount.value < total && !confirm(t('confirmSubmit', { a: answeredCount.value, t: total }))) return
  submitting.value = true
  clearInterval(tick); clearInterval(autosave)
  try {
    const { data: res } = await api.post(`/attempts/${data.value.attempt.id}/submit`, { answers: answers.value })
    toast(auto ? t('timeUp') : t('submitted'))
    router.replace(`/attempts/${res.id}`)
  } catch (e) { toast(errorMessage(e), 'error'); submitting.value = false }
}
</script>

<template>
  <div class="page" :lang="lang">
    <div v-if="error" class="card card-body stack">
      <div class="alert alert-danger">{{ error }}</div>
      <RouterLink to="/exams" class="btn" style="align-self:flex-start">{{ t('back') }}</RouterLink>
    </div>
    <div v-if="!data && !error" class="loading-block"><span class="spinner" /> {{ t('starting') }}</div>
    <template v-if="data">
      <div class="exam-bar card">
        <div><h2>{{ data.exam.title }}</h2><div class="tiny">{{ t('answered', { a: answeredCount, t: data.questions.length }) }} · {{ t('autosave') }}</div></div>
        <span class="spacer" />
        <div class="clock" :class="{ low: remaining < 120 }" :title="t('timeLeft')" :aria-label="`${t('timeLeft')} ${clock}`" aria-live="polite">{{ clock }}</div>
        <button class="btn btn-primary" :disabled="submitting" @click="submit()">{{ t('submit') }}</button>
      </div>
      <div v-if="data.exam.instructions" class="alert pre">{{ data.exam.instructions }}</div>

      <div class="layout">
        <div class="card card-body stack-lg">
          <template v-for="(q, i) in data.questions" :key="q.id">
            <div v-show="i === current" class="stack">
              <div class="row"><span class="badge badge-info">{{ t('question', { n: i + 1, t: data.questions.length }) }}</span><span class="tiny">{{ t('marks', { m: Number(q.marks) }) }}</span></div>
              <div class="pre qtext">{{ q.question }}</div>

              <div v-if="q.type === 'mcq' || q.type === 'true_false'" class="choices" role="radiogroup">
                <button v-for="(o, oi) in q.options" :key="oi" type="button" class="choice" role="radio"
                  :class="{ on: q.type === 'mcq' ? answers[q.id] === oi : answers[q.id] === (oi === 0) }" :aria-checked="q.type === 'mcq' ? answers[q.id] === oi : answers[q.id] === (oi === 0)"
                  @click="set(q, q.type === 'mcq' ? oi : oi === 0)">
                  <span class="letter">{{ String.fromCharCode(65 + oi) }}</span>{{ optionLabel(q, o, oi) }}
                </button>
              </div>
              <div v-else-if="q.type === 'multi_select'" class="choices">
                <span class="tiny">{{ t('selectAll') }}</span>
                <button v-for="(o, oi) in q.options" :key="oi" type="button" class="choice" :class="{ on: (answers[q.id] ?? []).includes(oi) }" @click="toggleMulti(q, oi)">
                  <span class="letter">{{ (answers[q.id] ?? []).includes(oi) ? '✓' : String.fromCharCode(65 + oi) }}</span>{{ o }}
                </button>
              </div>
              <input v-else-if="q.type === 'numeric'" :value="answers[q.id]" type="number" step="any" class="input" style="max-width:240px" :placeholder="t('yourAnswerPh')"
                @input="set(q, ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value))" />
              <input v-else :value="answers[q.id]" class="input" :placeholder="t('typeAnswer')" :lang="lang" autocomplete="off" spellcheck="false"
                @input="set(q, ($event.target as HTMLInputElement).value)" />

              <div class="row">
                <button class="btn" :disabled="i === 0" @click="current--">{{ t('previous') }}</button>
                <span class="spacer" />
                <button v-if="i < data.questions.length - 1" class="btn btn-primary" @click="current++; save()">{{ t('next') }}</button>
                <button v-else class="btn btn-primary" :disabled="submitting" @click="submit()">{{ t('finish') }}</button>
              </div>
            </div>
          </template>
        </div>
        <div class="card card-body nav">
          <div class="tiny" style="margin-bottom:8px">{{ t('questions') }}</div>
          <div class="qgrid">
            <button v-for="(q, i) in data.questions" :key="q.id" class="qbtn" :class="{ done: isAnswered(q), cur: i === current }" @click="current = i">{{ i + 1 }}</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.exam-bar { display: flex; align-items: center; gap: 14px; padding: 12px 18px; position: sticky; top: 66px; z-index: 5; flex-wrap: wrap; }
.clock { font-size: 22px; font-weight: 750; font-variant-numeric: tabular-nums; padding: 4px 12px; border-radius: 8px; background: var(--surface-2); }
.clock.low { color: var(--danger); background: var(--danger-50); }
.layout { display: grid; grid-template-columns: 1fr 220px; gap: 16px; align-items: start; }
@media (max-width: 860px) { .layout { grid-template-columns: 1fr; } }
.qtext { font-size: 16px; line-height: 1.6; }
.choices { display: flex; flex-direction: column; gap: 8px; }
.choice { display: flex; align-items: center; gap: 12px; text-align: left; padding: 12px 14px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--surface); font: inherit; color: var(--text); cursor: pointer; }
.choice:hover { border-color: var(--primary); }
.choice.on { border-color: var(--primary); background: var(--primary-50); }
.letter { width: 26px; height: 26px; border-radius: 50%; border: 1.5px solid var(--border); display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0; }
.choice.on .letter { background: var(--primary); border-color: var(--primary); color: #fff; }
.qgrid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; }
.qbtn { height: 34px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface); cursor: pointer; font: inherit; font-weight: 600; color: var(--text-2); }
.qbtn.done { background: var(--success-50); color: var(--success); border-color: transparent; }
.qbtn.cur { outline: 2px solid var(--primary); }
</style>
