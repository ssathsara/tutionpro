<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import ResourcePage from '../components/ResourcePage.vue'
import StatusBadge from '../components/StatusBadge.vue'
import Modal from '../components/Modal.vue'
import type { ColumnDef, FieldDef } from '../types'
import { api, errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { toast } from '../stores/toast'
import { date, dateTime, lkr, titleCase } from '../utils/format'
import { EP, PAYMENT_METHODS, studentSearchField } from '../utils/constants'

const auth = useAuthStore()
const route = useRoute()
const isAdmin = computed(() => auth.role === 'institute_admin')
const tab = ref<'invoices' | 'payments'>('invoices')
const invoices = ref<InstanceType<typeof ResourcePage>>()
const summary = ref<any>(null)
const payhereEnabled = ref(false)

async function loadSummary() { if (isAdmin.value) summary.value = (await api.get('/invoices/summary')).data }
onMounted(async () => {
  loadSummary()
  payhereEnabled.value = (await api.get('/meta')).data.payhere_enabled
  if (route.query.paid) toast('Thank you! Your payment will appear once PayHere confirms it.', 'info', 6000)
})

const statusOpts = [
  { value: 'unpaid', label: 'Unpaid' }, { value: 'partial', label: 'Partially paid' }, { value: 'paid', label: 'Paid' },
  { value: 'overdue', label: 'Overdue' }, { value: 'void', label: 'Void' },
]
const invoiceColumns: ColumnDef[] = [
  { key: 'invoice_no', label: 'Invoice' },
  { key: 'student_name', label: 'Student', format: (v, r) => `${v ?? ''}${r.admission_no ? ' · ' + r.admission_no : ''}` },
  { key: 'description', label: 'Description' },
  { key: 'due_date', label: 'Due', format: (v) => date(v) },
  { key: 'amount', label: 'Amount', format: (v, r) => lkr(v - r.discount) },
  { key: 'balance', label: 'Balance', format: (v) => lkr(v) },
  { key: 'status', label: 'Status' },
]
const invoiceFields: FieldDef[] = [
  studentSearchField('student_id', 'Student', { required: true }),
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches },
  { key: 'description', label: 'Description', placeholder: 'e.g. Paper class fee' },
  { key: 'period', label: 'Month (YYYY-MM)', type: 'month' },
  { key: 'amount', label: 'Amount (LKR)', type: 'number', required: true },
  { key: 'discount', label: 'Discount (LKR)', type: 'number' },
  { key: 'due_date', label: 'Due date', type: 'date' },
]
const filters: FieldDef[] = [
  { key: 'status', label: 'Status', type: 'select', options: statusOpts },
  { key: 'batch_id', label: 'Batch', type: 'select', optionsEndpoint: EP.batches },
  { key: 'period', label: 'Month', type: 'month' },
]
const paymentColumns: ColumnDef[] = [
  { key: 'receipt_no', label: 'Receipt' },
  { key: 'paid_at', label: 'Date', format: (v) => dateTime(v) },
  { key: 'student_name', label: 'Student' },
  { key: 'invoice_no', label: 'Invoice' },
  { key: 'method', label: 'Method', format: (v) => titleCase(v) },
  { key: 'reference', label: 'Reference' },
  { key: 'amount', label: 'Amount', format: (v) => lkr(v) },
]

// Generate monthly invoices
const showGen = ref(false)
const gen = reactive({ period: new Date().toISOString().slice(0, 7), due_day: 10 })
async function generate() {
  try {
    const { data } = await api.post('/invoices/generate', gen)
    toast(data.message); showGen.value = false; invoices.value?.reload(); loadSummary()
  } catch (e) { toast(errorMessage(e), 'error') }
}

// Record payment
const payFor = ref<any>(null)
const pay = reactive<Record<string, any>>({ amount: 0, method: 'cash', reference: '' })
const payError = ref('')
function openPay(row: any) { payFor.value = row; Object.assign(pay, { amount: row.balance, method: 'cash', reference: '' }); payError.value = '' }
async function recordPayment() {
  payError.value = ''
  try {
    const { data } = await api.post(`/invoices/${payFor.value.id}/payments`, pay)
    toast(`Payment recorded · ${data.receipt_no}`)
    payFor.value = null; invoices.value?.reload(); loadSummary()
    window.open(`/receipts/${data.id}`, '_blank')
  } catch (e) { payError.value = errorMessage(e) }
}
async function voidInvoice(row: any) {
  if (!confirm(`Void invoice ${row.invoice_no}?`)) return
  try { await api.put(`/invoices/${row.id}`, { status: 'void' }); toast('Invoice voided'); invoices.value?.reload(); loadSummary() } catch (e) { toast(errorMessage(e), 'error') }
}

// PayHere online payment
async function payOnline(row: any) {
  try {
    const { data } = await api.post(`/invoices/${row.id}/payhere`)
    const form = document.createElement('form')
    form.method = 'POST'; form.action = data.action
    for (const [k, v] of Object.entries(data.fields)) {
      const input = document.createElement('input'); input.type = 'hidden'; input.name = k; input.value = String(v); form.appendChild(input)
    }
    document.body.appendChild(form); form.submit()
  } catch (e) { toast(errorMessage(e), 'error') }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div><h1>Fees</h1><p class="sub">{{ isAdmin ? 'Monthly invoices, collections and receipts' : 'Your invoices and payment receipts' }}</p></div>
      <div v-if="isAdmin" class="row">
        <button class="btn" @click="showGen = true">Generate monthly invoices</button>
        <button class="btn btn-primary" @click="invoices?.openCreate()">New invoice</button>
      </div>
    </div>

    <div v-if="summary" class="grid grid-4">
      <div class="card stat"><div class="label">Collected today</div><div class="value">{{ lkr(summary.collected_today) }}</div></div>
      <div class="card stat"><div class="label">Collected this month</div><div class="value">{{ lkr(summary.collected_this_month) }}</div></div>
      <div class="card stat"><div class="label">Outstanding</div><div class="value">{{ lkr(summary.outstanding) }}</div></div>
      <div class="card stat"><div class="label">Overdue</div><div class="value" style="color:var(--danger)">{{ lkr(summary.overdue) }}</div><div class="hint">{{ summary.overdue_count }} invoices</div></div>
    </div>

    <div class="tabs">
      <button class="tab" :class="{ active: tab === 'invoices' }" @click="tab = 'invoices'">Invoices</button>
      <button class="tab" :class="{ active: tab === 'payments' }" @click="tab = 'payments'">Payments & receipts</button>
    </div>

    <ResourcePage v-show="tab === 'invoices'" ref="invoices" title="Invoices" hide-header endpoint="/invoices" entity-name="invoice"
      :columns="invoiceColumns" :fields="invoiceFields" :filters="filters" :can-create="isAdmin" :can-delete="isAdmin" :searchable="isAdmin"
      @saved="loadSummary">
      <template #cell-status="{ row }"><StatusBadge :value="row.is_overdue ? 'overdue' : row.status" /></template>
      <template #actions="{ row }">
        <template v-if="isAdmin && ['unpaid', 'partial'].includes(row.status)">
          <button class="btn btn-sm btn-primary" @click="openPay(row)">Record payment</button>
          <button class="btn btn-sm btn-ghost" @click="voidInvoice(row)">Void</button>
        </template>
        <button v-if="!isAdmin && ['unpaid', 'partial'].includes(row.status) && payhereEnabled" class="btn btn-sm btn-primary" @click="payOnline(row)">Pay online</button>
        <span v-if="!isAdmin && ['unpaid', 'partial'].includes(row.status) && !payhereEnabled" class="tiny">Pay at the office</span>
      </template>
    </ResourcePage>

    <ResourcePage v-if="tab === 'payments'" title="Payments" hide-header endpoint="/payments" entity-name="payment"
      :columns="paymentColumns" :searchable="false">
      <template #actions="{ row }"><RouterLink :to="`/receipts/${row.id}`" target="_blank" class="btn btn-sm">Receipt</RouterLink></template>
    </ResourcePage>

    <Modal v-if="showGen" title="Generate monthly invoices" width="460px" @close="showGen = false">
      <div class="stack">
        <p class="muted small">Creates one invoice per active enrollment using each batch's monthly fee and the student's discount. Students and parents are notified. Existing invoices for the month are skipped.</p>
        <div class="form-grid">
          <div class="field"><label>Month</label><input v-model="gen.period" type="month" class="input" /></div>
          <div class="field"><label>Due on day</label><input v-model.number="gen.due_day" type="number" min="1" max="28" class="input" /></div>
        </div>
      </div>
      <template #footer><button class="btn" @click="showGen = false">Cancel</button><button class="btn btn-primary" @click="generate">Generate</button></template>
    </Modal>

    <Modal v-if="payFor" :title="`Record payment · ${payFor.invoice_no}`" width="480px" @close="payFor = null">
      <form id="pay-form" class="stack" @submit.prevent="recordPayment">
        <div class="alert">{{ payFor.student_name }} · {{ payFor.description }}<br /><strong>Balance {{ lkr(payFor.balance) }}</strong></div>
        <div v-if="payError" class="alert alert-danger">{{ payError }}</div>
        <div class="form-grid">
          <div class="field"><label>Amount (LKR)</label><input v-model.number="pay.amount" type="number" step="0.01" class="input" required /></div>
          <div class="field"><label>Method</label><select v-model="pay.method" class="input"><option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option></select></div>
          <div class="field full"><label>Reference</label><input v-model="pay.reference" class="input" placeholder="Bank slip / card ref (optional)" /></div>
        </div>
      </form>
      <template #footer><button class="btn" @click="payFor = null">Cancel</button><button class="btn btn-primary" form="pay-form">Save & print receipt</button></template>
    </Modal>
  </div>
</template>
