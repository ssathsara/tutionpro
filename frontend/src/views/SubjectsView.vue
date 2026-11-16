<script setup lang="ts">
import ResourcePage from '../components/ResourcePage.vue'
import type { ColumnDef, FieldDef } from '../types'

const columns: ColumnDef[] = [
  { key: 'name', label: 'Subject' },
  { key: 'code', label: 'Code' },
  { key: 'batches_count', label: 'Batches' },
  { key: 'description', label: 'Description' },
]
const fields: FieldDef[] = [
  { key: 'name', label: 'Subject name', required: true },
  { key: 'code', label: 'Code' },
  { key: 'description', label: 'Description', type: 'textarea' },
]
</script>

<template>
  <ResourcePage title="Subjects" endpoint="/subjects" entity-name="subject" :columns="columns" :fields="fields"
    can-create can-edit can-delete :paginated="false" />
</template>
