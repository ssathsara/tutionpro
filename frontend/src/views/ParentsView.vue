<script setup lang="ts">
import ResourcePage from '../components/ResourcePage.vue'
import type { ColumnDef, FieldDef } from '../types'
import { PHONE_HELP, studentSearchField } from '../utils/constants'

const columns: ColumnDef[] = [
  { key: 'name', label: 'Name' },
  { key: 'phone', label: 'Mobile' },
  { key: 'email', label: 'Email' },
  { key: 'children', label: 'Children', format: (v) => (v ?? []).map((c: any) => c.name).join(', ') || '—' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Full name', required: true },
  { key: 'email', label: 'Email (login)', type: 'email', required: true },
  { key: 'phone', label: 'Mobile', type: 'tel', help: PHONE_HELP + '. Receives absence & payment SMS.' },
  { key: 'password', label: 'Password', type: 'password', help: 'Leave blank to auto-generate / keep current' },
  studentSearchField('student_ids', 'Children', { multiple: true }),
]
</script>

<template>
  <ResourcePage title="Parents" subtitle="Parent portal accounts linked to students" endpoint="/parents" entity-name="parent"
    :columns="columns" :fields="fields" can-create can-edit can-delete />
</template>
