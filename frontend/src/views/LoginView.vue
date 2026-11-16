<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { errorMessage } from '../api/client'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

// Demo shortcuts only in development (or when explicitly enabled for a demo deployment).
const showDemo = import.meta.env.DEV || import.meta.env.VITE_SHOW_DEMO_ACCOUNTS === 'true'
const demo = [
  { label: 'Institute admin', email: 'admin@pragathi.lk' },
  { label: 'Teacher', email: 'teacher@pragathi.lk' },
  { label: 'Student', email: 'student@pragathi.lk' },
  { label: 'Parent', email: 'parent@pragathi.lk' },
  { label: 'Platform admin', email: 'super@tuitionpro.lk' },
]

async function submit() {
  busy.value = true; error.value = ''
  try {
    await auth.login(email.value, password.value)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/'
    router.push(redirect)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
function fill(e: string) { email.value = e; password.value = 'password' }
</script>

<template>
  <div class="auth">
    <div class="auth-side">
      <div class="logo">T</div>
      <h1>TuitionPro</h1>
      <p>Classes, attendance, fees, online exams and learning for Sri Lankan tuition institutes, in one place.</p>
      <ul>
        <li>Attendance with SMS alerts to parents</li>
        <li>Monthly fee invoices, receipts and PayHere payments</li>
        <li>Auto-marked online exams and leaderboards</li>
        <li>Video lessons, notes, live classes and doubt clearing</li>
      </ul>
    </div>
    <div class="auth-main">
      <form class="card card-body auth-card stack-lg" @submit.prevent="submit">
        <div>
          <h2>Sign in</h2>
          <p class="muted small">Welcome back. Sign in to your institute account.</p>
        </div>
        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div class="field">
          <label for="email">Email or username</label>
          <input id="email" v-model="email" class="input" type="text" inputmode="email" autocapitalize="none" autocomplete="username" required
            placeholder="you@example.lk or st20260025@institute" />
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" v-model="password" class="input" type="password" autocomplete="current-password" required />
        </div>
        <button class="btn btn-primary" type="submit" :disabled="busy"><span v-if="busy" class="spinner" /> Sign in</button>
        <p class="small muted center">New institute? <RouterLink to="/register">Create an account</RouterLink></p>
        <div v-if="showDemo" class="demo">
          <div class="tiny">Demo accounts (password: <code>password</code>)</div>
          <div class="row">
            <button v-for="d in demo" :key="d.email" type="button" class="btn btn-sm" @click="fill(d.email)">{{ d.label }}</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.auth { min-height: 100vh; display: grid; grid-template-columns: 1fr 1fr; }
.auth-side { background: linear-gradient(160deg, #111a2e 0%, #1c2f66 60%, #0d6b65 100%); color: #dbe3f5; padding: 64px 56px; display: flex; flex-direction: column; justify-content: center; gap: 16px; }
.auth-side h1 { color: #fff; font-size: 30px; }
.auth-side p { max-width: 420px; font-size: 15px; }
.auth-side ul { padding-left: 18px; margin: 8px 0 0; display: flex; flex-direction: column; gap: 6px; }
.logo { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #3b6cff, #0d9488); color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 24px; }
.auth-main { display: flex; align-items: center; justify-content: center; padding: 32px 16px; }
.auth-card { width: 100%; max-width: 400px; }
.demo { border-top: 1px dashed var(--border); padding-top: 12px; display: flex; flex-direction: column; gap: 8px; }
@media (max-width: 860px) { .auth { grid-template-columns: 1fr; } .auth-side { display: none; } }
</style>
