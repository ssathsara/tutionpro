<script setup lang="ts">
import { computed } from 'vue'
import ResourcePage from '../components/ResourcePage.vue'
import type { ColumnDef, FieldDef } from '../types'
import { useAuthStore } from '../stores/auth'
import { date, titleCase } from '../utils/format'
import { EP, studentSearchField } from '../utils/constants'

const auth = useAuthStore()
const isStaff = computed(() => auth.hasRole('institute_admin', 'teacher'))
const columns: ColumnDef[] = [
  { key: 'title', label: 'Certificate' },
  { key: 'student_name', label: 'Student' },
  { key: 'batch_name', label: 'Batch' },
  { key: 'type', label: 'Type', format: (v) => titleCase(v) },
  { key: 'serial', label: 'Serial' },
  { key: 'issued_on', label: 'Issued', format: (v) => date(v) },
]
const fields: FieldDef[] = [
  studentSearchField('student_id', 'Student', { required: true }),
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches, required: true },
  { key: 'title', label: 'Title', required: true, placeholder: 'e.g. Certificate of Completion - 2026 A/L Physics' },
  { key: 'type', label: 'Type', type: 'select', required: true, options: [{ value: 'completion', label: 'Completion' }, { value: 'merit', label: 'Merit' }, { value: 'participation', label: 'Participation' }] },
  { key: 'issued_on', label: 'Issue date', type: 'date' },
  { key: 'remarks', label: 'Remarks', type: 'textarea' },
]
</script>

<template>
  <ResourcePage title="Certificates" :subtitle="isStaff ? 'Issue certificates. Each one can be verified online by its serial number.' : 'Your certificates'"
    endpoint="/certificates" entity-name="certificate" :columns="columns" :fields="fields" :can-create="isStaff" :can-delete="isStaff"
    :searchable="false" :defaults="{ type: 'completion' }">
    <template #cell-serial="{ row }"><RouterLink :to="`/verify/${row.serial}`" class="mono small">{{ row.serial }}</RouterLink></template>
    <template #actions="{ row }"><RouterLink :to="`/certificates/${row.id}/print`" target="_blank" class="btn btn-sm">View / print</RouterLink></template>
  </ResourcePage>
</template>
