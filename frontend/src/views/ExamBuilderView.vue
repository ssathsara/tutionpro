<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { toast } from '../stores/toast'
import { titleCase } from '../utils/format'
import Modal from '../components/Modal.vue'
import { EXAM_LANGUAGES, et, normalizeLang } from '../i18n/exam'

const langLabel = (l?: string) => EXAM_LANGUAGES.find((x) => x.value === normalizeLang(l))?.label

const route = useRoute()
const exam = ref<any>(null)
const error = ref('')
const TYPES = [
  { value: 'mcq', label: 'Multiple choice (one answer)' },
  { value: 'multi_select', label: 'Multiple select (many answers)' },
  { value: 'true_false', label: 'True / False' },
  { value: 'short_answer', label: 'Short answer (text)' },
  { value: 'numeric', label: 'Numeric answer' },
]

async function load() {
  try { exam.value = (await api.get(`/exams/${route.params.id}`)).data } catch (e) { error.value = errorMessage(e) }
}
onMounted(load)
const totalMarks = computed(() => (exam.value?.questions ?? []).reduce((s: number, q: any) => s + Number(q.marks), 0))

// Question editor
const editing = ref<any>(null)
const q = reactive<any>({})
const qError = ref('')
function open(existing?: any) {
  editing.value = existing ?? {}
  qError.value = ''
  const ca = existing?.correct_answer ?? []
  Object.assign(q, {
    type: existing?.type ?? 'mcq',
    question: existing?.question ?? '',
    options: existing?.options && existing.type !== 'true_false' ? [...existing.options] : ['', '', '', ''],
    correctIndex: existing?.type === 'mcq' ? ca[0] : 0,
    correctSet: existing?.type === 'multi_select' ? [...ca] : [],
    tf: existing?.type === 'true_false' ? ca[0] : true,
    accepted: existing?.type === 'short_answer' ? ca.join('\n') : '',
    numValue: existing?.type === 'numeric' ? ca[0] : null,
    numTol: existing?.type === 'numeric' ? ca[1] ?? 0 : 0,
    marks: existing?.marks ?? 1,
    explanation: existing?.explanation ?? '',
  })
}
function payload() {
  const base: any = { type: q.type, question: q.question, marks: q.marks, explanation: q.explanation || null }
  const opts = q.options.map((o: string) => o.trim()).filter(Boolean)
  switch (q.type) {
    case 'mcq': return { ...base, options: opts, correct_answer: [q.correctIndex] }
    case 'multi_select': return { ...base, options: opts, correct_answer: q.correctSet }
    case 'true_false': return { ...base, correct_answer: [q.tf] }
    case 'short_answer': return { ...base, correct_answer: q.accepted.split('\n').map((s: string) => s.trim()).filter(Boolean) }
    case 'numeric': return { ...base, correct_answer: [q.numValue, q.numTol || 0] }
  }
}
async function saveQuestion() {
  qError.value = ''
  try {
    if (editing.value?.id) await api.put(`/exams/${exam.value.id}/questions/${editing.value.id}`, payload())
    else await api.post(`/exams/${exam.value.id}/questions`, payload())
    toast('Question saved'); editing.value = null; load()
  } catch (e) { qError.value = errorMessage(e) }
}
async function remove(question: any) {
  if (!confirm('Delete this question?')) return
  await api.delete(`/exams/${exam.value.id}/questions/${question.id}`); toast('Question deleted'); load()
}
async function togglePublish() {
  try {
    const e = exam.value
    await api.put(`/exams/${e.id}`, {
      batch_id: e.batch_id, title: e.title, instructions: e.instructions, duration_minutes: e.duration_minutes,
      starts_at: e.starts_at, ends_at: e.ends_at, pass_percentage: e.pass_percentage, max_attempts: e.max_attempts,
      shuffle_questions: e.shuffle_questions, show_results: e.show_results, is_published: !e.is_published, language: e.language,
    })
    toast(e.is_published ? 'Exam unpublished' : 'Exam published. Students have been notified.'); load()
  } catch (err) { toast(errorMessage(err), 'error') }
}
function answerText(qq: any) {
  const ca = qq.correct_answer ?? []
  switch (qq.type) {
    case 'mcq': return qq.options?.[ca[0]]
    case 'multi_select': return ca.map((i: number) => qq.options?.[i]).join(', ')
    case 'true_false': return `${et(exam.value?.language, ca[0] ? 'true' : 'false')}${exam.value?.language !== 'en' ? (ca[0] ? ' (True)' : ' (False)') : ''}`
    case 'short_answer': return ca.join(' / ')
    case 'numeric': return `${ca[0]}${ca[1] ? ' ± ' + ca[1] : ''}`
  }
}
</script>

<template>
  <div class="page">
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <template v-if="exam">
      <div class="page-header">
        <div>
          <RouterLink to="/exams" class="small">← Exams</RouterLink>
          <h1>{{ exam.title }}</h1>
          <p class="sub">{{ exam.batch?.name }} · {{ langLabel(exam.language) }} · {{ exam.duration_minutes }} minutes · pass mark {{ exam.pass_percentage }}% · {{ exam.questions.length }} questions · {{ totalMarks }} marks</p>
        </div>
        <div class="row">
          <RouterLink :to="`/exams/${exam.id}/results`" class="btn">Results</RouterLink>
          <button class="btn" :class="exam.is_published ? '' : 'btn-success'" @click="togglePublish">{{ exam.is_published ? 'Unpublish' : 'Publish exam' }}</button>
          <button class="btn btn-primary" @click="open()">Add question</button>
        </div>
      </div>
      <div v-if="exam.is_published" class="alert alert-warning">This exam is published. Editing questions after students have started will affect their marks.</div>
      <div v-if="exam.language === 'si'" class="alert small">
        Sinhala exam: type questions and options in Sinhala (Windows: Settings → Time &amp; language → add the Sinhala keyboard, or paste from Helakuru / Google Input Tools).
        Students see the exam screen and results in Sinhala. Short answers are matched regardless of capitals, extra spaces and invisible joiner characters (e.g. ශ්‍රී typed with or without ZWJ).
      </div>
      <div v-else-if="exam.language === 'ta'" class="alert small">Tamil exam: students see the exam screen and results in Tamil. Type questions and options in Tamil.</div>

      <div v-if="!exam.questions.length" class="card empty">No questions yet. Click "Add question" to build your paper.</div>
      <div v-for="(qq, i) in exam.questions" :key="qq.id" class="card card-body qcard">
        <div class="row">
          <span class="qnum">{{ i + 1 }}</span>
          <span class="badge">{{ titleCase(qq.type) }}</span>
          <span class="tiny">{{ Number(qq.marks) }} mark{{ Number(qq.marks) === 1 ? '' : 's' }}</span>
          <span class="spacer" />
          <button class="btn btn-sm btn-ghost" @click="open(qq)">Edit</button>
          <button class="btn btn-sm btn-ghost btn-danger" @click="remove(qq)">Delete</button>
        </div>
        <div class="pre qtext" :lang="normalizeLang(exam.language)">{{ qq.question }}</div>
        <ol v-if="qq.options && qq.type !== 'true_false'" type="A" class="opts">
          <li v-for="(o, oi) in qq.options" :key="oi" :class="{ correct: (qq.correct_answer ?? []).includes(oi) }">{{ o }}</li>
        </ol>
        <div class="small"><span class="muted">Answer:</span> <strong>{{ answerText(qq) }}</strong></div>
      </div>
    </template>

    <Modal v-if="editing" :title="editing.id ? 'Edit question' : 'Add question'" width="720px" @close="editing = null">
      <form id="q-form" class="stack" @submit.prevent="saveQuestion">
        <div v-if="qError" class="alert alert-danger">{{ qError }}</div>
        <div class="form-grid">
          <div class="field"><label>Type</label><select v-model="q.type" class="input"><option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option></select></div>
          <div class="field"><label>Marks</label><input v-model.number="q.marks" type="number" step="0.5" min="0" class="input" /></div>
        </div>
        <div class="field"><label>Question</label><textarea v-model="q.question" class="input" required :lang="normalizeLang(exam?.language)" /></div>

        <div v-if="q.type === 'mcq' || q.type === 'multi_select'" class="field">
          <label>Options <span class="tiny">({{ q.type === 'mcq' ? 'select the correct one' : 'tick all correct options' }})</span></label>
          <div v-for="(_, oi) in q.options" :key="oi" class="row opt-row">
            <input v-if="q.type === 'mcq'" v-model="q.correctIndex" type="radio" :value="oi" name="correct" :aria-label="`Option ${oi + 1} correct`" />
            <input v-else v-model="q.correctSet" type="checkbox" :value="oi" :aria-label="`Option ${oi + 1} correct`" />
            <span class="opt-letter">{{ String.fromCharCode(65 + oi) }}</span>
            <input v-model="q.options[oi]" class="input" style="flex:1" :placeholder="`Option ${String.fromCharCode(65 + oi)}`" />
            <button v-if="q.options.length > 2" type="button" class="btn btn-sm btn-ghost" @click="q.options.splice(oi, 1)">✕</button>
          </div>
          <div><button v-if="q.options.length < 10" type="button" class="btn btn-sm" @click="q.options.push('')">Add option</button></div>
        </div>

        <div v-if="q.type === 'true_false'" class="row">
          <label class="checkbox"><input v-model="q.tf" type="radio" :value="true" /> True<span v-if="exam?.language !== 'en'" class="muted"> / {{ et(exam?.language, 'true') }}</span></label>
          <label class="checkbox"><input v-model="q.tf" type="radio" :value="false" /> False<span v-if="exam?.language !== 'en'" class="muted"> / {{ et(exam?.language, 'false') }}</span></label>
        </div>
        <div v-if="q.type === 'short_answer'" class="field">
          <label>Accepted answers (one per line)</label>
          <textarea v-model="q.accepted" class="input" placeholder="Photosynthesis&#10;photo synthesis" />
          <span class="help">Marking ignores capital letters and extra spaces.</span>
        </div>
        <div v-if="q.type === 'numeric'" class="form-grid">
          <div class="field"><label>Correct value</label><input v-model.number="q.numValue" type="number" step="any" class="input" /></div>
          <div class="field"><label>Tolerance (±)</label><input v-model.number="q.numTol" type="number" step="any" min="0" class="input" /></div>
        </div>
        <div class="field"><label>Explanation (shown after submission)</label><textarea v-model="q.explanation" class="input" /></div>
      </form>
      <template #footer><button class="btn" @click="editing = null">Cancel</button><button class="btn btn-primary" form="q-form">Save question</button></template>
    </Modal>
  </div>
</template>

<style scoped>
.qcard { display: flex; flex-direction: column; gap: 10px; }
.qnum { width: 26px; height: 26px; border-radius: 50%; background: var(--primary-50); color: var(--primary); font-weight: 700; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; }
.qtext { font-size: 15px; }
.opts { margin: 0; padding-left: 22px; display: flex; flex-direction: column; gap: 4px; }
.opts .correct { color: var(--success); font-weight: 650; }
.opt-row { flex-wrap: nowrap; }
.opt-letter { font-weight: 650; width: 16px; color: var(--text-2); }
</style>
