<script setup lang="ts">
import ResourcePage from '../components/ResourcePage.vue'
import StatusBadge from '../components/StatusBadge.vue'
import type { ColumnDef, FieldDef } from '../types'
import { PHONE_HELP } from '../utils/constants'

const columns: ColumnDef[] = [
  { key: 'name', label: 'Name' },
  { key: 'specialization', label: 'Subject' },
  { key: 'phone', label: 'Mobile' },
  { key: 'email', label: 'Email' },
  { key: 'batches_count', label: 'Batches' },
  { key: 'is_active', label: 'Status' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Full name', required: true },
  { key: 'email', label: 'Email (login)', type: 'email', required: true },
  { key: 'phone', label: 'Mobile', type: 'tel', help: PHONE_HELP },
  { key: 'password', label: 'Password', type: 'password', help: 'Leave blank to auto-generate / keep current' },
  { key: 'nic', label: 'NIC number', help: 'e.g. 199012345678 or 901234567V' },
  { key: 'specialization', label: 'Subject specialization' },
  { key: 'qualification', label: 'Qualifications' },
  { key: 'payment_rate', label: 'Revenue share (%)', type: 'number' },
  { key: 'joined_on', label: 'Joined on', type: 'date' },
  { key: 'is_active', label: 'Account active', type: 'checkbox' },
  { key: 'bio', label: 'Bio', type: 'textarea' },
]
</script>

<template>
  <ResourcePage title="Teachers" subtitle="Teaching staff and their logins" endpoint="/teachers" entity-name="teacher"
    :columns="columns" :fields="fields" can-create can-edit can-delete :defaults="{ is_active: true, payment_rate: 70 }">
    <template #cell-is_active="{ row }"><StatusBadge :value="row.is_active ? 'active' : 'inactive'" /></template>
  </ResourcePage>
</template>
