<script setup lang="ts">
import { computed } from 'vue'
import ResourcePage from '../components/ResourcePage.vue'
import StatusBadge from '../components/StatusBadge.vue'
import type { ColumnDef, FieldDef } from '../types'
import { useAuthStore } from '../stores/auth'
import { lkr, titleCase } from '../utils/format'
import { EP, GRADES, MEDIUMS, MODES } from '../utils/constants'

const auth = useAuthStore()
const isAdmin = computed(() => auth.role === 'institute_admin')
const isStaff = computed(() => auth.hasRole('institute_admin', 'teacher'))

const columns: ColumnDef[] = [
  { key: 'name', label: 'Batch' },
  { key: 'subject_name', label: 'Subject' },
  { key: 'teacher_name', label: 'Teacher' },
  { key: 'grade', label: 'Grade' },
  { key: 'medium', label: 'Medium' },
  { key: 'mode', label: 'Mode', format: (v) => titleCase(v) },
  { key: 'monthly_fee', label: 'Monthly fee', format: (v) => lkr(v) },
  { key: 'students_count', label: 'Students', format: (v, r) => (r.capacity ? `${v} / ${r.capacity}` : String(v)) },
  { key: 'is_active', label: 'Status' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Batch name', required: true, placeholder: 'e.g. 2027 A/L Physics - Theory' },
  { key: 'subject_id', label: 'Subject', type: 'select', optionsEndpoint: EP.subjects },
  { key: 'teacher_id', label: 'Teacher', type: 'select', optionsEndpoint: EP.teachers },
  { key: 'grade', label: 'Grade', type: 'select', options: GRADES },
  { key: 'medium', label: 'Medium', type: 'select', options: MEDIUMS, required: true },
  { key: 'mode', label: 'Mode', type: 'select', options: MODES, required: true },
  { key: 'monthly_fee', label: 'Monthly fee (LKR)', type: 'number', required: true },
  { key: 'capacity', label: 'Capacity', type: 'number' },
  { key: 'room', label: 'Default room / hall' },
  { key: 'start_date', label: 'Start date', type: 'date' },
  { key: 'end_date', label: 'End date', type: 'date' },
  { key: 'is_active', label: 'Active', type: 'checkbox' },
  { key: 'description', label: 'Description', type: 'textarea' },
]
</script>

<template>
  <ResourcePage title="Batches" :subtitle="isStaff ? 'Classes, fees and enrollments' : 'Classes you are enrolled in'" endpoint="/batches" entity-name="batch"
    :columns="columns" :fields="fields" :can-create="isAdmin" :can-edit="isAdmin" :can-delete="isAdmin"
    :defaults="{ medium: 'Sinhala', mode: 'physical', is_active: true, monthly_fee: 2500 }"
    :row-link="isStaff ? (r) => `/batches/${r.id}` : undefined">
    <template #cell-is_active="{ row }"><StatusBadge :value="row.is_active ? 'active' : 'inactive'" /></template>
  </ResourcePage>
</template>
