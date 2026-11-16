<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { dateTime, lkr, titleCase } from '../utils/format'

const route = useRoute()
const r = ref<any>(null)
const error = ref('')
function print() { window.print() }
onMounted(async () => {
  try { r.value = (await api.get(`/payments/${route.params.id}/receipt`)).data } catch (e) { error.value = errorMessage(e) }
})
</script>

<template>
  <div class="wrap">
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <div v-if="r" class="receipt card">
      <div class="head">
        <div><h1>{{ r.institute?.name }}</h1><div class="small muted">{{ r.institute?.address }} {{ r.institute?.city }}<br />{{ r.institute?.phone }} {{ r.institute?.email }}</div></div>
        <div class="right"><div class="tag">RECEIPT</div><div class="mono strong">{{ r.payment.receipt_no }}</div><div class="small muted">{{ dateTime(r.payment.paid_at) }}</div></div>
      </div>
      <table class="table">
        <tbody>
          <tr><th>Student</th><td>{{ r.student.name }} ({{ r.student.admission_no }})</td></tr>
          <tr><th>Invoice</th><td>{{ r.invoice?.invoice_no }} · {{ r.invoice?.description }}</td></tr>
          <tr><th>Payment method</th><td>{{ titleCase(r.payment.method) }}<span v-if="r.payment.reference"> · {{ r.payment.reference }}</span></td></tr>
          <tr><th>Amount paid</th><td class="amount">{{ lkr(r.payment.amount) }}</td></tr>
          <tr><th>Invoice balance</th><td>{{ lkr(r.invoice?.balance) }}</td></tr>
          <tr v-if="r.payment.receiver"><th>Received by</th><td>{{ r.payment.receiver.name }}</td></tr>
        </tbody>
      </table>
      <p class="tiny center">This is a computer generated receipt. Thank you for your payment.</p>
      <div class="row no-print" style="justify-content:center"><button class="btn btn-primary" @click="print">Print</button></div>
    </div>
  </div>
</template>

<style scoped>
.wrap { padding: 32px 16px; display: flex; justify-content: center; }
.receipt { max-width: 640px; width: 100%; padding: 28px; display: flex; flex-direction: column; gap: 18px; }
.head { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.tag { font-weight: 800; letter-spacing: .15em; color: var(--primary); }
.table th { width: 40%; background: none; text-transform: none; font-size: 13px; color: var(--text-2); }
.amount { font-size: 20px; font-weight: 700; }
.strong { font-weight: 650; }
@media print { .receipt { box-shadow: none; border: 1px solid #ccc; } }
</style>
