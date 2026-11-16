<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import Icon from './Icon.vue'

const props = withDefaults(defineProps<{ title: string; width?: string }>(), { width: '640px' })
const emit = defineEmits<{ close: [] }>()

function onKey(e: KeyboardEvent) { if (e.key === 'Escape') emit('close') }
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="backdrop" @mousedown.self="emit('close')">
      <div class="modal card" role="dialog" aria-modal="true" :aria-label="props.title" :style="{ maxWidth: props.width }">
        <div class="modal-head">
          <h2>{{ props.title }}</h2>
          <button class="btn btn-ghost btn-icon" aria-label="Close" @click="emit('close')"><Icon name="x" /></button>
        </div>
        <div class="modal-body"><slot /></div>
        <div v-if="$slots.footer" class="modal-foot"><slot name="footer" /></div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.backdrop { position: fixed; inset: 0; background: rgba(10, 15, 30, .5); display: flex; align-items: flex-start; justify-content: center; padding: 6vh 16px; z-index: 50; overflow-y: auto; }
.modal { width: 100%; box-shadow: var(--shadow-lg); }
.modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border); }
.modal-body { padding: 18px; }
.modal-foot { padding: 12px 18px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; }
</style>
