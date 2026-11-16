<script setup lang="ts">
import { computed } from 'vue'
import ResourcePage from '../components/ResourcePage.vue'
import type { ColumnDef, FieldDef } from '../types'
import { useAuthStore } from '../stores/auth'
import { dateTime, titleCase } from '../utils/format'
import { EP } from '../utils/constants'

const auth = useAuthStore()
const isStaff = computed(() => auth.hasRole('institute_admin', 'teacher'))
const audience = computed(() => [
  { value: 'all', label: 'Everyone' }, { value: 'students', label: 'Students' }, { value: 'parents', label: 'Parents' },
  ...(auth.role === 'institute_admin' ? [{ value: 'teachers', label: 'Teachers (staff only)' }] : []),
])
const columns: ColumnDef[] = [
  { key: 'title', label: 'Announcement' },
  { key: 'batch_name', label: 'For' },
  { key: 'audience', label: 'Audience', format: (v) => titleCase(v) },
  { key: 'author_name', label: 'Posted by' },
  { key: 'published_at', label: 'Published', format: (v) => dateTime(v) },
]
const fields = computed<FieldDef[]>(() => [
  { key: 'title', label: 'Title', required: true },
  { key: 'batch_id', label: auth.role === 'institute_admin' ? 'Batch (leave empty for whole institute)' : 'Batch', type: 'select', optionsEndpoint: EP.batches, required: auth.role !== 'institute_admin' },
  { key: 'audience', label: 'Audience', type: 'select', options: audience.value, required: true },
  { key: 'published_at', label: 'Schedule for (optional)', type: 'datetime-local' },
  { key: 'body', label: 'Message', type: 'textarea', required: true },
  { key: 'is_pinned', label: 'Pin to top', type: 'checkbox' },
  { key: 'send_sms', label: 'Also send as SMS', type: 'checkbox', help: 'Sends to the mobile numbers of all recipients' },
])
</script>

<template>
  <ResourcePage title="Announcements" :subtitle="isStaff ? 'Notices to students, parents and staff (in-app and SMS)' : 'Notices from your institute'"
    endpoint="/announcements" entity-name="announcement" :columns="columns" :fields="fields" :can-create="isStaff" :can-edit="isStaff" :can-delete="isStaff"
    :searchable="false" :defaults="{ audience: 'all', is_pinned: false, send_sms: false }">
    <template #cell-title="{ row }">
      <div><strong>{{ row.title }}</strong> <span v-if="row.is_pinned" class="badge badge-info">Pinned</span></div>
      <div class="small muted pre" style="max-width:560px">{{ row.body }}</div>
    </template>
  </ResourcePage>
</template>
