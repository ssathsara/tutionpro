<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { toast } from '../stores/toast'
import { dateTime, ROLE_LABELS } from '../utils/format'
import StatusBadge from '../components/StatusBadge.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const t = ref<any>(null)
const reply = ref('')
const error = ref('')
const busy = ref(false)

async function load() {
  try { t.value = (await api.get(`/threads/${route.params.id}`)).data } catch (e) { error.value = errorMessage(e) }
}
onMounted(load)

async function send() {
  if (!reply.value.trim()) return
  busy.value = true
  try { await api.post(`/threads/${t.value.id}/replies`, { body: reply.value }); reply.value = ''; load() }
  catch (e) { toast(errorMessage(e), 'error') } finally { busy.value = false }
}
async function accept(r: any) { await api.post(`/replies/${r.id}/accept`); toast('Marked as the answer'); load() }
async function toggle() { await api.post(`/threads/${t.value.id}/resolve`); load() }
async function remove() {
  if (!confirm('Delete this thread?')) return
  await api.delete(`/threads/${t.value.id}`); toast('Thread deleted'); router.push('/discussions')
}
</script>

<template>
  <div class="page">
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <template v-if="t">
      <div class="page-header">
        <div>
          <RouterLink to="/discussions" class="small">← Discussions</RouterLink>
          <h1>{{ t.title }}</h1>
          <p class="sub">{{ t.batch_name }}<span v-if="t.lesson"> · Lesson: {{ t.lesson.title }}</span></p>
        </div>
        <div class="row">
          <StatusBadge :value="t.status" />
          <button v-if="t.can_moderate" class="btn btn-sm" @click="toggle">{{ t.status === 'resolved' ? 'Reopen' : 'Mark resolved' }}</button>
          <button v-if="t.can_moderate" class="btn btn-sm btn-danger" @click="remove">Delete</button>
        </div>
      </div>

      <div class="card card-body post">
        <div class="row"><span class="avatar">{{ t.user?.name?.[0] }}</span><div><strong>{{ t.user?.name }}</strong><div class="tiny">{{ ROLE_LABELS[t.user?.role] }} · {{ dateTime(t.created_at) }}</div></div></div>
        <div class="pre body">{{ t.body }}</div>
      </div>

      <div v-for="r in t.replies" :key="r.id" class="card card-body post" :class="{ answer: r.is_answer, staff: ['teacher', 'institute_admin'].includes(r.user?.role) }">
        <div class="row">
          <span class="avatar">{{ r.user?.name?.[0] }}</span>
          <div><strong>{{ r.user?.name }}</strong> <span v-if="['teacher', 'institute_admin'].includes(r.user?.role)" class="badge badge-info">{{ ROLE_LABELS[r.user?.role] }}</span><div class="tiny">{{ dateTime(r.created_at) }}</div></div>
          <span class="spacer" />
          <span v-if="r.is_answer" class="badge badge-success">Accepted answer</span>
          <button v-else-if="t.can_moderate && t.type === 'doubt'" class="btn btn-sm btn-ghost" @click="accept(r)">Accept answer</button>
        </div>
        <div class="pre body">{{ r.body }}</div>
      </div>

      <form class="card card-body stack" @submit.prevent="send">
        <label for="reply" class="strong">Your reply</label>
        <textarea id="reply" v-model="reply" class="input" rows="4" :placeholder="auth.role === 'student' ? 'Add more details or thank your teacher…' : 'Write an answer…'" />
        <div><button class="btn btn-primary" :disabled="busy || !reply.trim()">Post reply</button></div>
      </form>
    </template>
  </div>
</template>

<style scoped>
.post { display: flex; flex-direction: column; gap: 10px; }
.post.staff { border-left: 3px solid var(--primary); }
.post.answer { border-left: 3px solid var(--success); background: color-mix(in srgb, var(--success-50) 50%, var(--surface)); }
.body { line-height: 1.65; }
.strong { font-weight: 600; }
</style>
