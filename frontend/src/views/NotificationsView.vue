<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client'
import { dateTime } from '../utils/format'
import Pagination from '../components/Pagination.vue'
import Icon from '../components/Icon.vue'

const router = useRouter()
const items = ref<any[]>([])
const page = ref(1)
const last = ref(1)
const total = ref(0)
const unreadOnly = ref(false)

async function load() {
  const { data } = await api.get('/notifications', { params: { page: page.value, unread: unreadOnly.value ? 1 : undefined } })
  items.value = data.data; last.value = data.last_page; total.value = data.total
}
onMounted(load)
async function open(n: any) {
  if (!n.read_at) { await api.post(`/notifications/${n.id}/read`); n.read_at = new Date().toISOString() }
  if (n.link) router.push(n.link)
}
async function readAll() { await api.post('/notifications/read-all'); load() }
const ICON: Record<string, string> = { announcement: 'megaphone', fee: 'cash', payment: 'cash', attendance: 'check', exam: 'edit', doubt: 'chat', discussion: 'chat', live_class: 'video', lesson: 'play', certificate: 'award' }
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div><h1>Notifications</h1></div>
      <div class="row">
        <label class="checkbox small"><input v-model="unreadOnly" type="checkbox" @change="page = 1; load()" /> Unread only</label>
        <button class="btn" @click="readAll">Mark all as read</button>
      </div>
    </div>
    <div class="card">
      <ul class="list">
        <li v-for="n in items" :key="n.id" class="list-item note" :class="{ unread: !n.read_at }" role="button" tabindex="0" @click="open(n)" @keydown.enter="open(n)">
          <span class="ico"><Icon :name="ICON[n.type] ?? 'bell'" :size="17" /></span>
          <div style="flex:1;min-width:0"><div class="title">{{ n.title }}</div><div class="small muted">{{ n.body }}</div></div>
          <span class="tiny nowrap">{{ dateTime(n.created_at) }}</span>
        </li>
        <li v-if="!items.length" class="empty">You're all caught up.</li>
      </ul>
      <Pagination :page="page" :last-page="last" :total="total" @change="(p) => { page = p; load() }" />
    </div>
  </div>
</template>

<style scoped>
.note { cursor: pointer; }
.note:hover { background: var(--surface-2); }
.note.unread .title { color: var(--primary); }
.note.unread { background: color-mix(in srgb, var(--primary-50) 45%, var(--surface)); }
.ico { width: 34px; height: 34px; border-radius: 8px; background: var(--surface-2); display: flex; align-items: center; justify-content: center; color: var(--text-2); flex-shrink: 0; }
</style>
