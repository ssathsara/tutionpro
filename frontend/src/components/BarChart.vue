<script setup lang="ts">
import { computed, ref } from 'vue'

/**
 * Lightweight responsive SVG bar chart (no dependencies).
 * series: up to two numeric series drawn side by side per label.
 */
const props = withDefaults(defineProps<{
  labels: string[]
  series: { name: string; values: (number | null)[]; color?: string }[]
  height?: number
  format?: (v: number) => string
  max?: number
}>(), { height: 220 })

const hover = ref<number | null>(null)
const W = 640
const PAD = { top: 12, right: 8, bottom: 26, left: 8 }
const fmt = (v: number) => (props.format ? props.format(v) : v.toLocaleString())

const maxVal = computed(() => {
  const m = props.max ?? Math.max(1, ...props.series.flatMap((s) => s.values.map((v) => v ?? 0)))
  return m * (props.max ? 1 : 1.1)
})
const innerH = computed(() => props.height - PAD.top - PAD.bottom)
const band = computed(() => (W - PAD.left - PAD.right) / Math.max(1, props.labels.length))
const barW = computed(() => Math.min(28, (band.value * 0.7) / props.series.length))
const COLORS = ['var(--primary)', 'var(--accent)']

function x(i: number, s: number) {
  const groupW = barW.value * props.series.length + (props.series.length - 1) * 3
  return PAD.left + i * band.value + (band.value - groupW) / 2 + s * (barW.value + 3)
}
function h(v: number | null) { return ((v ?? 0) / maxVal.value) * innerH.value }
</script>

<template>
  <div class="chart">
    <div v-if="props.series.length > 1" class="legend">
      <span v-for="(s, si) in props.series" :key="s.name" class="legend-item">
        <i :style="{ background: s.color ?? COLORS[si] }" />{{ s.name }}
      </span>
    </div>
    <svg :viewBox="`0 0 ${W} ${props.height}`" preserveAspectRatio="none" role="img" :style="{ height: props.height + 'px' }">
      <line v-for="g in 4" :key="g" :x1="PAD.left" :x2="W - PAD.right" :y1="PAD.top + innerH * (g - 1) / 3" :y2="PAD.top + innerH * (g - 1) / 3" class="grid-line" />
      <g v-for="(label, i) in props.labels" :key="label" @mouseenter="hover = i" @mouseleave="hover = null">
        <rect :x="PAD.left + i * band" :y="PAD.top" :width="band" :height="innerH" fill="transparent" />
        <rect v-for="(s, si) in props.series" :key="s.name" :x="x(i, si)" :y="PAD.top + innerH - h(s.values[i])"
          :width="barW" :height="Math.max(0, h(s.values[i]))" rx="3" :fill="s.color ?? COLORS[si]" :opacity="hover === null || hover === i ? 1 : 0.45" />
        <text :x="PAD.left + i * band + band / 2" :y="props.height - 8" text-anchor="middle" class="axis">{{ label }}</text>
      </g>
    </svg>
    <div v-if="hover !== null" class="tip">
      <strong>{{ props.labels[hover] }}</strong>
      <span v-for="s in props.series" :key="s.name">{{ s.name }}: {{ s.values[hover] === null ? '—' : fmt(s.values[hover] as number) }}</span>
    </div>
  </div>
</template>

<style scoped>
.chart { position: relative; }
svg { width: 100%; display: block; }
.grid-line { stroke: var(--border); stroke-width: 1; }
.axis { font-size: 11px; fill: var(--text-3); }
.legend { display: flex; gap: 14px; margin-bottom: 6px; font-size: 12px; color: var(--text-2); }
.legend-item { display: inline-flex; align-items: center; gap: 6px; }
.legend-item i { width: 10px; height: 10px; border-radius: 2px; display: inline-block; }
.tip { position: absolute; top: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px; font-size: 12px; display: flex; flex-direction: column; box-shadow: var(--shadow); }
</style>
