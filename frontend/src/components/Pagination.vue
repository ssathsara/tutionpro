<script setup lang="ts">
const props = defineProps<{ page: number; lastPage: number; total: number }>()
const emit = defineEmits<{ change: [page: number] }>()
</script>

<template>
  <div v-if="props.lastPage > 1 || props.total > 0" class="pager">
    <span class="tiny">{{ props.total }} record{{ props.total === 1 ? '' : 's' }}</span>
    <div v-if="props.lastPage > 1" class="row">
      <button class="btn btn-sm" :disabled="props.page <= 1" @click="emit('change', props.page - 1)">Previous</button>
      <span class="small muted">Page {{ props.page }} of {{ props.lastPage }}</span>
      <button class="btn btn-sm" :disabled="props.page >= props.lastPage" @click="emit('change', props.page + 1)">Next</button>
    </div>
  </div>
</template>

<style scoped>
.pager { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-top: 1px solid var(--border); gap: 10px; flex-wrap: wrap; }
</style>
