<script setup lang="ts">
import { useRoute } from 'vue-router'
import AppLayout from './components/AppLayout.vue'
import { toasts } from './stores/toast'
import { useAuthStore } from './stores/auth'

const route = useRoute()
const auth = useAuthStore()
</script>

<template>
  <RouterView v-if="route.meta.bare || !auth.user" />
  <AppLayout v-else>
    <RouterView :key="route.path" />
  </AppLayout>

  <div class="toasts" aria-live="polite">
    <div v-for="t in toasts" :key="t.id" class="toast" :class="t.type">{{ t.message }}</div>
  </div>
</template>

<style>
.toasts { position: fixed; bottom: 20px; right: 20px; display: flex; flex-direction: column; gap: 8px; z-index: 100; max-width: calc(100vw - 40px); }
.toast { background: #1a2233; color: #fff; padding: 10px 16px; border-radius: 8px; box-shadow: 0 8px 24px rgba(0, 0, 0, .2); font-weight: 520; border-left: 4px solid #22c55e; }
.toast.error { border-left-color: #ef4444; }
.toast.info { border-left-color: #3b82f6; }
</style>
