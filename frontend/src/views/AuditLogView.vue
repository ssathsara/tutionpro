<script setup lang="ts">
import ResourcePage from '../components/ResourcePage.vue'
import type { ColumnDef } from '../types'
import { dateTime } from '../utils/format'

const columns: ColumnDef[] = [
  { key: 'created_at', label: 'When', format: (v) => dateTime(v) },
  { key: 'user_name', label: 'User' },
  { key: 'action', label: 'Action' },
  { key: 'subject_type', label: 'Record', format: (v, r) => (v ? `${v} #${r.subject_id}` : '—') },
  { key: 'meta', label: 'Details', format: (v) => (v ? Object.entries(v).map(([k, x]) => `${k}: ${x}`).join(', ') : '—') },
  { key: 'ip', label: 'IP address' },
]
</script>

<template>
  <ResourcePage title="Audit log" subtitle="Security-relevant activity in your institute" endpoint="/audit-logs" entity-name="entry"
    :columns="columns" :searchable="false"
    :filters="[{ key: 'action', label: 'Action', type: 'select', options: [{ value: 'auth', label: 'Sign-ins & passwords' }, { value: 'student', label: 'Students' }, { value: 'payment', label: 'Payments' }, { value: 'invoice', label: 'Invoices' }, { value: 'batch', label: 'Batches' }, { value: 'teacher', label: 'Teachers' }] }]" />
</template>
