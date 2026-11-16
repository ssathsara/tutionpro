<script setup lang="ts">
import { computed } from 'vue'
import { titleCase } from '../utils/format'

const props = defineProps<{ value?: string | null }>()
const MAP: Record<string, string> = {
  paid: 'success', present: 'success', completed: 'success', resolved: 'success', active: 'success', submitted: 'success', passed: 'success',
  partial: 'warning', late: 'warning', scheduled: 'info', in_progress: 'warning', open: 'warning', excused: 'info', live: 'info',
  unpaid: 'danger', absent: 'danger', cancelled: 'danger', void: 'danger', inactive: 'danger', overdue: 'danger', failed: 'danger',
}
const cls = computed(() => 'badge badge-' + (MAP[props.value ?? ''] ?? 'neutral'))
</script>

<template>
  <span v-if="props.value" :class="cls">{{ titleCase(props.value) }}</span>
</template>
