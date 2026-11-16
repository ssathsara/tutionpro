<script setup lang="ts">
import { computed } from 'vue'
import ResourcePage from '../components/ResourcePage.vue'
import StatusBadge from '../components/StatusBadge.vue'
import type { ColumnDef, FieldDef } from '../types'
import { useAuthStore } from '../stores/auth'
import { EP, GENDERS, GRADES, PHONE_HELP } from '../utils/constants'

const auth = useAuthStore()
const isAdmin = computed(() => auth.role === 'institute_admin')

const columns: ColumnDef[] = [
  { key: 'name', label: 'Name' },
  { key: 'admission_no', label: 'Admission no' },
  { key: 'grade', label: 'Grade' },
  { key: 'phone', label: 'Mobile' },
  { key: 'batches', label: 'Batches', format: (v) => (v ?? []).map((b: any) => b.name).join(', ') || '—' },
  { key: 'status', label: 'Status' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Full name', required: true },
  { key: 'email', label: 'Email / login', type: 'text', help: 'Optional. Leave blank to create a username from the admission number.' },
  { key: 'phone', label: 'Mobile', type: 'tel', help: PHONE_HELP },
  { key: 'password', label: 'Password', type: 'password', help: 'Leave blank to generate one (shown once after saving) / keep current' },
  { key: 'admission_no', label: 'Admission no', help: 'Leave blank to auto-generate' },
  { key: 'grade', label: 'Grade', type: 'select', options: GRADES },
  { key: 'school', label: 'School' },
  { key: 'date_of_birth', label: 'Date of birth', type: 'date' },
  { key: 'gender', label: 'Gender', type: 'select', options: GENDERS },
  { key: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }, { value: 'graduated', label: 'Graduated' }] },
  { key: 'address', label: 'Address' },
  { key: 'guardian_name', label: 'Guardian name' },
  { key: 'guardian_phone', label: 'Guardian mobile', type: 'tel' },
  { key: 'parent_email', label: 'Parent portal email', type: 'email', help: 'Creates or links a parent login' },
  { key: 'parent_name', label: 'Parent name' },
  { key: 'parent_phone', label: 'Parent mobile', type: 'tel' },
  { key: 'batch_ids', label: 'Batches', type: 'multiselect', optionsEndpoint: EP.batches },
]
const filters: FieldDef[] = [
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches },
  { key: 'grade', label: 'Grade', type: 'select', options: GRADES },
  { key: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }, { value: 'graduated', label: 'Graduated' }] },
]
const toForm = (r: any) => ({ ...r, batch_ids: (r.batches ?? []).map((b: any) => b.id) })
</script>

<template>
  <ResourcePage title="Students" subtitle="Registrations, enrollments and parent links" endpoint="/students" entity-name="student"
    :columns="columns" :fields="fields" :filters="filters" :can-create="isAdmin" :can-edit="isAdmin" :can-delete="isAdmin"
    :to-form="toForm" :defaults="{ status: 'active' }" :row-link="(r) => `/students/${r.id}`">
    <template #cell-status="{ row }"><StatusBadge :value="row.status" /></template>
  </ResourcePage>
</template>
