<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { errorMessage } from '../api/client'
import { PHONE_HELP } from '../utils/constants'

const auth = useAuthStore()
const router = useRouter()
const form = reactive({ institute_name: '', city: '', institute_phone: '', name: '', email: '', phone: '', password: '', password_confirmation: '' })
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

async function submit() {
  busy.value = true; errors.value = {}; error.value = ''
  try {
    const payload: Record<string, string | null> = { ...form }
    for (const k of Object.keys(payload)) if (payload[k] === '') payload[k] = null
    await auth.registerInstitute(payload)
    router.push('/')
  } catch (e: any) {
    const errs = e?.response?.data?.errors
    if (errs) for (const [k, v] of Object.entries(errs)) errors.value[k] = (v as string[])[0]
    else error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="wrap">
    <form class="card card-body stack-lg reg" @submit.prevent="submit">
      <div>
        <h1>Create your institute</h1>
        <p class="muted">Set up TuitionPro for your tuition class in under a minute.</p>
      </div>
      <div v-if="error" class="alert alert-danger">{{ error }}</div>
      <h3>Institute</h3>
      <div class="form-grid">
        <div class="field full"><label>Institute name *</label><input v-model="form.institute_name" class="input" required /><span v-if="errors.institute_name" class="error">{{ errors.institute_name }}</span></div>
        <div class="field"><label>City</label><input v-model="form.city" class="input" placeholder="e.g. Nugegoda" /></div>
        <div class="field"><label>Phone</label><input v-model="form.institute_phone" class="input" type="tel" /><span class="error" v-if="errors.institute_phone">{{ errors.institute_phone }}</span></div>
      </div>
      <h3>Your admin account</h3>
      <div class="form-grid">
        <div class="field"><label>Full name *</label><input v-model="form.name" class="input" required /><span v-if="errors.name" class="error">{{ errors.name }}</span></div>
        <div class="field"><label>Mobile</label><input v-model="form.phone" class="input" type="tel" /><span v-if="errors.phone" class="error">{{ errors.phone }}</span><span v-else class="help">{{ PHONE_HELP }}</span></div>
        <div class="field full"><label>Email *</label><input v-model="form.email" class="input" type="email" required /><span v-if="errors.email" class="error">{{ errors.email }}</span></div>
        <div class="field"><label>Password *</label><input v-model="form.password" class="input" type="password" required autocomplete="new-password" /><span v-if="errors.password" class="error">{{ errors.password }}</span><span v-else class="help">At least 8 characters with letters and numbers</span></div>
        <div class="field"><label>Confirm password *</label><input v-model="form.password_confirmation" class="input" type="password" required autocomplete="new-password" /></div>
      </div>
      <button class="btn btn-primary" :disabled="busy"><span v-if="busy" class="spinner" /> Create institute</button>
      <p class="small muted center">Already registered? <RouterLink to="/login">Sign in</RouterLink></p>
    </form>
  </div>
</template>

<style scoped>
.wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 32px 16px; }
.reg { width: 100%; max-width: 620px; }
</style>
