<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { toast } from '../stores/toast'
import { ROLE_LABELS } from '../utils/format'
import { PHONE_HELP } from '../utils/constants'

const auth = useAuthStore()
const profile = reactive({ name: auth.user?.name ?? '', phone: auth.user?.phone ?? '' })
const pw = reactive({ current_password: '', password: '', password_confirmation: '' })
const err1 = ref('')
const err2 = ref('')

async function saveProfile() {
  err1.value = ''
  try {
    const { data } = await api.put('/auth/profile', { name: profile.name, phone: profile.phone || null })
    auth.user = data.user
    toast('Profile updated')
  } catch (e) { err1.value = errorMessage(e) }
}
async function changePassword() {
  err2.value = ''
  try {
    await api.put('/auth/password', pw)
    pw.current_password = pw.password = pw.password_confirmation = ''
    toast('Password changed. Other sessions were signed out.')
  } catch (e) { err2.value = errorMessage(e) }
}
</script>

<template>
  <div class="page">
    <div class="page-header"><div><h1>My profile</h1><p class="sub">{{ auth.user?.email }} · {{ ROLE_LABELS[auth.role ?? ''] }}<span v-if="auth.user?.institute"> · {{ auth.user.institute.name }}</span></p></div></div>
    <div class="grid grid-2">
      <form class="card card-body stack" @submit.prevent="saveProfile">
        <h2>Details</h2>
        <div v-if="err1" class="alert alert-danger">{{ err1 }}</div>
        <div class="field"><label>Name</label><input v-model="profile.name" class="input" required /></div>
        <div class="field"><label>Mobile</label><input v-model="profile.phone" class="input" type="tel" /><span class="help">{{ PHONE_HELP }}. Used for SMS alerts.</span></div>
        <div><button class="btn btn-primary">Save</button></div>
      </form>
      <form class="card card-body stack" @submit.prevent="changePassword">
        <h2>Change password</h2>
        <div v-if="err2" class="alert alert-danger">{{ err2 }}</div>
        <div class="field"><label>Current password</label><input v-model="pw.current_password" class="input" type="password" required autocomplete="current-password" /></div>
        <div class="field"><label>New password</label><input v-model="pw.password" class="input" type="password" required autocomplete="new-password" /></div>
        <div class="field"><label>Confirm new password</label><input v-model="pw.password_confirmation" class="input" type="password" required autocomplete="new-password" /></div>
        <div><button class="btn btn-primary">Update password</button></div>
      </form>
    </div>
  </div>
</template>
