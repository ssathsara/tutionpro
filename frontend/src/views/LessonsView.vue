<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { toast } from '../stores/toast'
import { embedUrl, titleCase } from '../utils/format'
import { EP } from '../utils/constants'
import { useOptions } from '../composables/useOptions'
import { openLessonFile } from '../utils/lessonFile'
import ResourcePage from '../components/ResourcePage.vue'
import Modal from '../components/Modal.vue'
import Icon from '../components/Icon.vue'
import type { ColumnDef, FieldDef } from '../types'

const auth = useAuthStore()
const isStaff = computed(() => auth.hasRole('institute_admin', 'teacher'))
const typeOpts = [{ value: 'video', label: 'Video lesson' }, { value: 'note', label: 'Notes' }, { value: 'tutorial', label: 'Tutorial' }]

// Staff: manage
const columns: ColumnDef[] = [
  { key: 'title', label: 'Title' },
  { key: 'batch_name', label: 'Batch' },
  { key: 'type', label: 'Type', format: (v) => titleCase(v) },
  { key: 'file_name', label: 'Attachment' },
  { key: 'is_published', label: 'Published' },
]
const fields: FieldDef[] = [
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches, required: true },
  { key: 'type', label: 'Type', type: 'select', options: typeOpts, required: true },
  { key: 'title', label: 'Title', required: true },
  { key: 'position', label: 'Order', type: 'number' },
  { key: 'video_url', label: 'Video URL', type: 'url', help: 'YouTube (unlisted works) or Vimeo link' },
  { key: 'file', label: 'Attachment (PDF, Word, PowerPoint, image, max 20MB)', type: 'file' },
  { key: 'description', label: 'Short description', type: 'textarea' },
  { key: 'content', label: 'Content / notes', type: 'textarea' },
  { key: 'is_published', label: 'Published (visible to students)', type: 'checkbox' },
]
const filters: FieldDef[] = [
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches },
  { key: 'type', label: 'Type', type: 'select', options: typeOpts },
]

// Learner: browse
const lessons = ref<any[]>([])
const loading = ref(true)
const batchFilter = ref<number | null>(null)
const typeFilter = ref<string | null>(null)
const viewing = ref<any>(null)
const { options: batchOptions } = useOptions(EP.batches)

async function loadLessons() {
  loading.value = true
  try {
    const params: any = { per_page: 100 }
    if (batchFilter.value) params.batch_id = batchFilter.value
    if (typeFilter.value) params.type = typeFilter.value
    lessons.value = (await api.get('/lessons', { params })).data.data
  } finally { loading.value = false }
}
onMounted(() => { if (!isStaff.value) loadLessons() })
watch([batchFilter, typeFilter], loadLessons)

const grouped = computed(() => {
  const m = new Map<string, any[]>()
  for (const l of lessons.value) { const k = l.batch_name ?? 'Other'; if (!m.has(k)) m.set(k, []); m.get(k)!.push(l) }
  return [...m.entries()]
})
const progress = computed(() => {
  const done = lessons.value.filter((l) => l.completed).length
  return { done, total: lessons.value.length, pct: lessons.value.length ? Math.round((done / lessons.value.length) * 100) : 0 }
})

async function complete(l: any) {
  try { await api.post(`/lessons/${l.id}/complete`); l.completed = true; toast('Marked as complete') } catch (e) { toast(errorMessage(e), 'error') }
}
const ICON: Record<string, string> = { video: 'play', note: 'file', tutorial: 'edit' }
</script>

<template>
  <ResourcePage v-if="isStaff" title="Lessons" subtitle="Video lessons, notes and tutorials for your batches" endpoint="/lessons" entity-name="lesson"
    :columns="columns" :fields="fields" :filters="filters" can-create can-edit can-delete multipart :defaults="{ type: 'note', is_published: true }">
    <template #cell-is_published="{ row }"><span :class="row.is_published ? 'badge badge-success' : 'badge'">{{ row.is_published ? 'Published' : 'Draft' }}</span></template>
    <template #cell-file_name="{ row }"><button v-if="row.has_file" type="button" class="link small" @click="openLessonFile(row)">{{ row.file_name }}</button><span v-else class="muted">—</span></template>
  </ResourcePage>

  <div v-else class="page">
    <div class="page-header">
      <div><h1>Lessons</h1><p class="sub">Recorded classes, notes and tutorials</p></div>
      <div class="row">
        <select v-model="batchFilter" class="input" aria-label="Batch"><option :value="null">All batches</option><option v-for="o in batchOptions" :key="o.value" :value="o.value">{{ o.label }}</option></select>
        <select v-model="typeFilter" class="input" aria-label="Type"><option :value="null">All types</option><option v-for="o in typeOpts" :key="o.value" :value="o.value">{{ o.label }}</option></select>
      </div>
    </div>

    <div v-if="auth.role === 'student' && lessons.length" class="card card-body">
      <div class="row"><strong>Your progress</strong><span class="spacer" /><span class="small muted">{{ progress.done }} of {{ progress.total }} completed</span></div>
      <div class="progress" style="margin-top:8px"><span :style="{ width: progress.pct + '%' }" /></div>
    </div>

    <div v-if="loading" class="loading-block"><span class="spinner" /></div>
    <div v-else-if="!lessons.length" class="card empty">No lessons have been published yet.</div>
    <div v-for="[batch, items] in grouped" :key="batch" class="stack">
      <h2>{{ batch }}</h2>
      <div class="grid grid-3">
        <button v-for="l in items" :key="l.id" class="card lesson" @click="viewing = l">
          <div class="lesson-icon" :class="l.type"><Icon :name="ICON[l.type]" :size="20" /></div>
          <div class="lesson-text">
            <div class="title">{{ l.title }}</div>
            <div class="tiny">{{ titleCase(l.type) }}<span v-if="l.description"> · {{ l.description }}</span></div>
          </div>
          <span v-if="l.completed" class="badge badge-success">Done</span>
        </button>
      </div>
    </div>

    <Modal v-if="viewing" :title="viewing.title" width="860px" @close="viewing = null">
      <div class="stack-lg">
        <div v-if="viewing.video_url" class="video">
          <iframe v-if="embedUrl(viewing.video_url)" :src="embedUrl(viewing.video_url)!" title="Lesson video" allowfullscreen
            allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" />
          <a v-else :href="viewing.video_url" target="_blank" rel="noopener" class="btn">Open video</a>
        </div>
        <p v-if="viewing.description" class="muted">{{ viewing.description }}</p>
        <div v-if="viewing.content" class="pre content">{{ viewing.content }}</div>
        <button v-if="viewing.has_file" type="button" class="btn" style="align-self:flex-start" @click="openLessonFile(viewing)"><Icon name="download" :size="16" /> {{ viewing.file_name ?? 'Open attachment' }}</button>
      </div>
      <template #footer>
        <RouterLink v-if="auth.role === 'student'" :to="{ path: '/discussions', query: { ask: 1, batch_id: viewing.batch_id, lesson_id: viewing.id } }" class="btn">Ask a doubt</RouterLink>
        <span class="spacer" />
        <button v-if="auth.role === 'student' && !viewing.completed" class="btn btn-primary" @click="complete(viewing)">Mark as complete</button>
        <span v-else-if="viewing.completed" class="badge badge-success">Completed</span>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
.lesson { display: flex; gap: 12px; align-items: flex-start; padding: 14px; text-align: left; cursor: pointer; font: inherit; color: var(--text); }
.lesson:hover { border-color: var(--primary); }
.lesson-text { flex: 1; min-width: 0; }
.lesson-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; background: var(--primary-50); color: var(--primary); }
.lesson-icon.note { background: var(--success-50); color: var(--success); }
.lesson-icon.tutorial { background: var(--warning-50); color: var(--warning); }
.title { font-weight: 600; }
.video { position: relative; padding-top: 56.25%; background: #000; border-radius: 8px; overflow: hidden; }
.video iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
.link { background: none; border: none; padding: 0; color: var(--primary); cursor: pointer; font: inherit; text-align: left; }
.link:hover { text-decoration: underline; }
.content { background: var(--surface-2); border-radius: 8px; padding: 14px; line-height: 1.7; }
</style>
