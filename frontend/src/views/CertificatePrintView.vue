<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '../api/client'
import { date, titleCase } from '../utils/format'

const route = useRoute()
const c = ref<any>(null)
const error = ref('')
onMounted(async () => {
  try { c.value = (await api.get(`/certificates/${route.params.id}`)).data } catch (e) { error.value = errorMessage(e) }
})
const verifyUrl = computed(() => (c.value ? `${location.origin}/verify/${c.value.serial}` : ''))
function print() { window.print() }
</script>

<template>
  <div class="wrap">
    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <template v-if="c">
      <div class="cert">
        <div class="inner">
          <div class="inst">{{ c.institute_name }}<span v-if="c.institute_city"> · {{ c.institute_city }}</span></div>
          <div class="kind">Certificate of {{ titleCase(c.type) }}</div>
          <div class="lead">This is to certify that</div>
          <div class="name">{{ c.student_name }}</div>
          <div class="lead">has been awarded</div>
          <div class="title">{{ c.title }}</div>
          <div v-if="c.batch_name || c.exam_title" class="detail">{{ c.batch_name }}<span v-if="c.exam_title"> · {{ c.exam_title }}</span></div>
          <div v-if="c.remarks" class="detail">{{ c.remarks }}</div>
          <div class="foot">
            <div><div class="line" />{{ c.issued_by_name ?? 'Authorised signatory' }}</div>
            <div class="center"><div class="small">Issued {{ date(c.issued_on) }}</div><div class="tiny">Serial {{ c.serial }}</div></div>
            <div class="right"><div class="tiny">Verify at</div><div class="tiny mono">{{ verifyUrl }}</div></div>
          </div>
        </div>
      </div>
      <div class="row no-print" style="justify-content:center;margin-top:16px"><button class="btn btn-primary" @click="print">Print / save as PDF</button></div>
    </template>
  </div>
</template>

<style scoped>
.wrap { padding: 24px 16px; }
.cert { max-width: 960px; margin: 0 auto; background: #fffdf7; color: #2a2416; padding: 18px; border-radius: 6px; box-shadow: var(--shadow-lg); aspect-ratio: 1.414; }
.inner { border: 3px double #b08d3c; height: 100%; padding: 4% 6%; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 10px; }
.inst { font-size: 16px; letter-spacing: .12em; text-transform: uppercase; color: #6f5a2a; font-weight: 600; }
.kind { font-family: Georgia, 'Times New Roman', serif; font-size: clamp(26px, 4.5vw, 44px); color: #8a6a22; }
.lead { font-style: italic; color: #5d5543; }
.name { font-family: Georgia, serif; font-size: clamp(24px, 4vw, 38px); font-weight: 700; border-bottom: 1px solid #b08d3c; padding: 0 24px 4px; }
.title { font-size: clamp(16px, 2.2vw, 22px); font-weight: 650; }
.detail { color: #5d5543; }
.foot { margin-top: auto; width: 100%; display: grid; grid-template-columns: 1fr 1fr 1fr; align-items: end; gap: 12px; font-size: 13px; }
.line { border-top: 1px solid #2a2416; width: 160px; margin-bottom: 4px; }
.tiny { color: #6b6450; }
@media (max-width: 640px) { .cert { aspect-ratio: auto; } .foot { grid-template-columns: 1fr; text-align: center; } .line { margin: 0 auto 4px; } .right { text-align: center; } }
@media print { .wrap { padding: 0; } .cert { box-shadow: none; max-width: none; } @page { size: A4 landscape; margin: 10mm; } }
</style>
