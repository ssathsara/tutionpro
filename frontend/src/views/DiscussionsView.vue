<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { toast } from '../stores/toast'
import { dateTime, ROLE_LABELS } from '../utils/format'
import { EP } from '../utils/constants'
import { useOptions } from '../composables/useOptions'
import Modal from '../components/Modal.vue'
import StatusBadge from '../components/StatusBadge.vue'
import Pagination from '../components/Pagination.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const threads = ref<any[]>([])
const meta = reactive({ page: 1, last: 1, total: 0 })
const loading = ref(true)
const f = reactive({ type: '', status: '', batch_id: '' as any, search: '' })
const { options: batches } = useOptions(EP.batches)

async function load() {
  loading.value = true
  try {
    const params: any = { page: meta.page }
    for (const [k, v] of Object.entries(f)) if (v) params[k] = v
    const { data } = await api.get('/threads', { params })
    threads.value = data.data; meta.last = data.last_page; meta.total = data.total
  } finally { loading.value = false }
}
onMounted(load)
let t: any
watch(f, () => { clearTimeout(t); t = setTimeout(() => { meta.page = 1; load() }, 250) })

const show = ref(!!route.query.ask)
const form = reactive<any>({ type: auth.role === 'student' ? 'doubt' : 'discussion', batch_id: route.query.batch_id ? Number(route.query.batch_id) : null, lesson_id: route.query.lesson_id ? Number(route.query.lesson_id) : null, title: '', body: '' })
const error = ref('')
async function create() {
  error.value = ''
  try {
    const { data } = await api.post('/threads', form)
    toast(form.type === 'doubt' ? 'Doubt posted. Your teacher has been notified.' : 'Discussion started')
    show.value = false
    router.push(`/discussions/${data.id}`)
  } catch (e) { error.value = errorMessage(e) }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div><h1>Discussions & doubts</h1><p class="sub">Ask questions, get answers from teachers and discuss with classmates</p></div>
      <button class="btn btn-primary" @click="show = true">{{ auth.role === 'student' ? 'Ask a doubt' : 'New discussion' }}</button>
    </div>
    <div class="card">
      <div class="card-header" style="justify-content:flex-start">
        <input v-model="f.search" class="input" style="max-width:260px" type="search" placeholder="Search…" aria-label="Search" />
        <select v-model="f.type" class="input" style="max-width:160px" aria-label="Type"><option value="">All types</option><option value="doubt">Doubts</option><option value="discussion">Discussions</option></select>
        <select v-model="f.status" class="input" style="max-width:160px" aria-label="Status"><option value="">Any status</option><option value="open">Open</option><option value="resolved">Resolved</option></select>
        <select v-model="f.batch_id" class="input" style="max-width:240px" aria-label="Batch"><option value="">All batches</option><option v-for="b in batches" :key="b.value" :value="b.value">{{ b.label }}</option></select>
      </div>
      <div v-if="loading && !threads.length" class="loading-block"><span class="spinner" /></div>
      <ul v-else class="list">
        <li v-for="th in threads" :key="th.id" class="list-item">
          <span class="avatar">{{ th.author_name?.[0] }}</span>
          <div style="flex:1;min-width:0">
            <RouterLink :to="`/discussions/${th.id}`" class="title">{{ th.title }}</RouterLink>
            <div class="tiny">{{ th.author_name }} ({{ ROLE_LABELS[th.author_role] }}) · {{ th.batch_name }} · {{ dateTime(th.created_at) }}</div>
          </div>
          <span class="badge" :class="th.type === 'doubt' ? 'badge-warning' : 'badge-info'">{{ th.type === 'doubt' ? 'Doubt' : 'Discussion' }}</span>
          <StatusBadge :value="th.status" />
          <span class="tiny nowrap">{{ th.replies_count }} repl{{ th.replies_count === 1 ? 'y' : 'ies' }}</span>
        </li>
        <li v-if="!threads.length" class="empty">Nothing here yet.</li>
      </ul>
      <Pagination :page="meta.page" :last-page="meta.last" :total="meta.total" @change="(p) => { meta.page = p; load() }" />
    </div>

    <Modal v-if="show" :title="form.type === 'doubt' ? 'Ask a doubt' : 'Start a discussion'" @close="show = false">
      <form id="thread-form" class="stack" @submit.prevent="create">
        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div class="form-grid">
          <div class="field"><label>Batch</label><select v-model="form.batch_id" class="input" required><option :value="null">— Select —</option><option v-for="b in batches" :key="b.value" :value="b.value">{{ b.label }}</option></select></div>
          <div class="field"><label>Type</label><select v-model="form.type" class="input"><option value="doubt">Doubt (question for the teacher)</option><option value="discussion">Discussion</option></select></div>
        </div>
        <div class="field"><label>Title</label><input v-model="form.title" class="input" required placeholder="Summarise your question" /></div>
        <div class="field"><label>Details</label><textarea v-model="form.body" class="input" required rows="6" /></div>
      </form>
      <template #footer><button class="btn" @click="show = false">Cancel</button><button class="btn btn-primary" form="thread-form">Post</button></template>
    </Modal>
  </div>
</template>
