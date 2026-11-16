<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import { date } from '../utils/format'

const route = useRoute()
const router = useRouter()
const serial = ref((route.params.serial as string) ?? '')
const result = ref<any>(null)
const notFound = ref(false)
const busy = ref(false)

async function check() {
  if (!serial.value.trim()) return
  busy.value = true; result.value = null; notFound.value = false
  router.replace(`/verify/${encodeURIComponent(serial.value.trim())}`)
  try { result.value = (await api.get(`/certificates/verify/${encodeURIComponent(serial.value.trim())}`)).data }
  catch { notFound.value = true }
  finally { busy.value = false }
}
onMounted(() => { if (serial.value) check() })
</script>

<template>
  <div class="wrap">
    <div class="card card-body stack-lg box">
      <div>
        <h1>Verify a certificate</h1>
        <p class="muted">Enter the serial number printed on a TuitionPro certificate.</p>
      </div>
      <form class="row" @submit.prevent="check">
        <input v-model="serial" class="input" style="flex:1" placeholder="TP-2026-XXXXXXXX" aria-label="Serial number" />
        <button class="btn btn-primary" :disabled="busy">Verify</button>
      </form>
      <div v-if="result" class="alert alert-success stack">
        <strong>Valid certificate</strong>
        <div><b>{{ result.student_name }}</b> was awarded <b>{{ result.title }}</b></div>
        <div class="small">{{ result.institute_name }}<span v-if="result.batch_name"> · {{ result.batch_name }}</span> · Issued {{ date(result.issued_on) }}</div>
      </div>
      <div v-if="notFound" class="alert alert-danger">No certificate matches this serial number.</div>
      <RouterLink to="/login" class="small">Back to TuitionPro</RouterLink>
    </div>
  </div>
</template>

<style scoped>
.wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
.box { width: 100%; max-width: 520px; }
</style>
