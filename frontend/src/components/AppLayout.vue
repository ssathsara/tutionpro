<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { navFor } from '../router/nav'
import { api } from '../api/client'
import { ROLE_LABELS } from '../utils/format'
import Icon from './Icon.vue'
import { cycleTheme, themeMode } from '../composables/useTheme'

const THEME_LABEL = { system: 'Theme: system', light: 'Theme: light', dark: 'Theme: dark' } as const
const THEME_ICON = { system: 'monitor', light: 'sun', dark: 'moon' } as const

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const open = ref(false)
const unread = ref(0)

const groups = computed(() => {
  const out: { name: string; items: ReturnType<typeof navFor> }[] = []
  for (const item of navFor(auth.role)) {
    const name = item.group ?? ''
    let g = out.find((x) => x.name === name)
    if (!g) out.push((g = { name, items: [] }))
    g.items.push(item)
  }
  return out
})

function isActive(to: string) {
  return to === '/' ? route.path === '/' : route.path === to || route.path.startsWith(to + '/')
}

async function refreshUnread() {
  if (!auth.user || auth.role === 'super_admin') return
  try { unread.value = (await api.get('/notifications/unread-count')).data.count } catch { /* ignore */ }
}
let poll: ReturnType<typeof setInterval> | undefined
onMounted(() => { refreshUnread(); poll = setInterval(refreshUnread, 60000) })
onBeforeUnmount(() => clearInterval(poll))
watch(() => route.fullPath, () => { open.value = false; refreshUnread() })

const initials = computed(() => (auth.user?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase())

async function logout() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <div class="shell">
    <aside class="sidebar" :class="{ open }">
      <div class="brand">
        <div class="logo">T</div>
        <div>
          <div class="brand-name">TuitionPro</div>
          <div class="brand-sub">{{ auth.user?.institute?.name ?? 'Platform' }}</div>
        </div>
      </div>
      <nav aria-label="Main">
        <div v-for="g in groups" :key="g.name" class="nav-group">
          <div v-if="g.name" class="nav-title">{{ g.name }}</div>
          <RouterLink v-for="item in g.items" :key="item.to" :to="item.to" class="nav-link" :class="{ active: isActive(item.to) }">
            <Icon :name="item.icon" :size="17" />
            <span>{{ item.label }}</span>
            <span v-if="item.to === '/notifications' && unread" class="count">{{ unread > 99 ? '99+' : unread }}</span>
          </RouterLink>
        </div>
      </nav>
    </aside>
    <div v-if="open" class="scrim" @click="open = false" />

    <div class="main">
      <header class="topbar no-print">
        <button class="btn btn-ghost btn-icon menu-btn" aria-label="Open menu" @click="open = !open"><Icon name="menu" /></button>
        <div class="spacer" />
        <button class="btn btn-ghost btn-icon" :title="`${THEME_LABEL[themeMode]} (click to change)`" :aria-label="THEME_LABEL[themeMode]" @click="cycleTheme">
          <Icon :name="THEME_ICON[themeMode]" />
        </button>
        <RouterLink v-if="auth.role !== 'super_admin'" to="/notifications" class="btn btn-ghost btn-icon bell" aria-label="Notifications">
          <Icon name="bell" />
          <span v-if="unread" class="dot" />
        </RouterLink>
        <RouterLink to="/profile" class="me">
          <span class="avatar">{{ initials }}</span>
          <span class="me-text">
            <span class="me-name">{{ auth.user?.name }}</span>
            <span class="me-role">{{ ROLE_LABELS[auth.role ?? ''] }}</span>
          </span>
        </RouterLink>
        <button class="btn btn-ghost btn-icon" title="Sign out" aria-label="Sign out" @click="logout"><Icon name="logout" /></button>
      </header>
      <main class="content">
        <slot />
      </main>
    </div>
  </div>
</template>

<style scoped>
.shell { display: flex; min-height: 100vh; }
.sidebar { width: 248px; background: var(--sidebar); color: var(--sidebar-text); position: fixed; inset: 0 auto 0 0; overflow-y: auto; z-index: 40; transition: transform .2s; }
.brand { display: flex; gap: 10px; align-items: center; padding: 18px 18px 14px; }
.logo { width: 34px; height: 34px; border-radius: 9px; background: linear-gradient(135deg, #3b6cff, #0d9488); color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 17px; }
.brand-name { color: #fff; font-weight: 700; font-size: 15px; }
.brand-sub { font-size: 11.5px; color: #8590a8; max-width: 170px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
nav { padding: 4px 10px 24px; }
.nav-group { margin-top: 10px; }
.nav-title { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: #66708a; padding: 8px 10px 4px; font-weight: 700; }
.nav-link { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 7px; color: var(--sidebar-text); font-weight: 520; font-size: 13.5px; }
.nav-link:hover { background: rgba(255, 255, 255, .06); color: #fff; text-decoration: none; }
.nav-link.active { background: rgba(91, 132, 255, .18); color: var(--sidebar-active); }
.count { margin-left: auto; background: #e5484d; color: #fff; border-radius: 999px; font-size: 11px; padding: 0 6px; font-weight: 700; }
.main { flex: 1; margin-left: 248px; min-width: 0; display: flex; flex-direction: column; }
.topbar { height: 58px; display: flex; align-items: center; gap: 8px; padding: 0 20px; background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 30; }
.menu-btn { display: none; }
.bell { position: relative; }
.dot { position: absolute; top: 7px; right: 8px; width: 8px; height: 8px; background: #e5484d; border-radius: 50%; border: 2px solid var(--surface); }
.me { display: flex; align-items: center; gap: 10px; color: var(--text); padding: 4px 8px; border-radius: 8px; }
.me:hover { background: var(--surface-2); text-decoration: none; }
.me-text { display: flex; flex-direction: column; line-height: 1.2; }
.me-name { font-weight: 600; font-size: 13px; }
.me-role { font-size: 11.5px; color: var(--text-3); }
.content { padding: 24px; width: 100%; }
.scrim { display: none; }
@media (max-width: 960px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); box-shadow: var(--shadow-lg); }
  .scrim { display: block; position: fixed; inset: 0; background: rgba(0, 0, 0, .4); z-index: 35; }
  .main { margin-left: 0; }
  .menu-btn { display: inline-flex; }
  .content { padding: 16px; }
  .me-text { display: none; }
}
@media print { .sidebar, .topbar { display: none; } .main { margin: 0; } }
</style>
