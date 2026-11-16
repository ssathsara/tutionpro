<script setup lang="ts">
import ResourcePage from '../components/ResourcePage.vue'
import StatusBadge from '../components/StatusBadge.vue'
import type { ColumnDef, FieldDef } from '../types'
import { date, titleCase } from '../utils/format'

const plans = [{ value: 'starter', label: 'Starter' }, { value: 'growth', label: 'Growth' }, { value: 'enterprise', label: 'Enterprise' }]
const columns: ColumnDef[] = [
  { key: 'name', label: 'Institute' },
  { key: 'city', label: 'City' },
  { key: 'plan', label: 'Plan', format: (v) => titleCase(v) },
  { key: 'students_count', label: 'Students' },
  { key: 'teachers_count', label: 'Teachers' },
  { key: 'is_active', label: 'Status' },
  { key: 'created_at', label: 'Joined', format: (v) => date(v) },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Institute name', required: true },
  { key: 'plan', label: 'Plan', type: 'select', options: plans },
  { key: 'email', label: 'Email', type: 'email' },
  { key: 'phone', label: 'Phone', type: 'tel' },
  { key: 'city', label: 'City' },
  { key: 'district', label: 'District' },
  { key: 'address', label: 'Address' },
  { key: 'is_active', label: 'Active', type: 'checkbox' },
  { key: 'admin_name', label: 'Admin name', required: true, createOnly: true },
  { key: 'admin_email', label: 'Admin email', type: 'email', required: true, createOnly: true },
  { key: 'admin_password', label: 'Admin password', type: 'password', required: true, createOnly: true },
]
</script>

<template>
  <ResourcePage title="Institutes" subtitle="Tenants on the TuitionPro platform" endpoint="/institutes" entity-name="institute"
    :columns="columns" :fields="fields" can-create can-edit can-delete :defaults="{ plan: 'starter', is_active: true }">
    <template #cell-is_active="{ row }"><StatusBadge :value="row.is_active ? 'active' : 'inactive'" /></template>
  </ResourcePage>
</template>
