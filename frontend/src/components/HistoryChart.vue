<script setup>
/**
 * Grafik riwayat (Chart.js): suhu + kelembapan, garis batas atas/bawah,
 * dan zona RH. Mendukung mode Administrator (rata-rata per interval).
 */
import { ref, onMounted, onBeforeUnmount, watch, computed, nextTick } from 'vue'
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  CategoryScale,
  Tooltip,
  Legend,
  Filler
} from 'chart.js'
import { dateTime, time, num, cssVar } from '@/utils/format'

/* Warna grafik diambil dari CSS sehingga selalu sesuai tema. */
const C = {
  temp: cssVar('--chart-temp', '#b45309'),
  moist: cssVar('--chart-moist', '#0369a1'),
  target: cssVar('--chart-target', '#059669'),
  wet: cssVar('--chart-wet', '#6d28d9'),
  rh: cssVar('--chart-rh', '#4338ca'),
  fan: cssVar('--chart-fan', '#1d4ed8'),
  heater: cssVar('--chart-heater', '#c2410c'),
  grid: cssVar('--chart-grid', '#e8ecf2'),
  tick: cssVar('--chart-tick', '#7c879b'),
  band: cssVar('--chart-band', 'rgba(29,78,216,.055)'),
  off: cssVar('--border-strong', '#cbd5e1')
}

Chart.register(
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  CategoryScale,
  Tooltip,
  Legend,
  Filler
)

const props = defineProps({
  rows: { type: Array, default: () => [] },
  thresholds: { type: Object, default: null },
  /** 'temp' | 'moist' | 'both' | 'rh' */
  metric: { type: String, default: 'both' },
  height: { type: Number, default: 300 },
  showThreshold: { type: Boolean, default: true },
  showFanBand: { type: Boolean, default: true }
})

const canvas = ref(null)
const chart = ref(null)
const tooltipEl = ref(null)

const showTemp = computed(() => props.metric === 'both' || props.metric === 'temp')
const showMoist = computed(() => props.metric === 'both' || props.metric === 'moist')

const labels = computed(() => props.rows.map((r) => dateTime(r.t)))

const tempRange = computed(() => {
  const vals = props.rows.map((r) => Number(r.temp_c)).filter((v) => !Number.isNaN(v))
  if (!vals.length) return [20, 60]
  const lo = Math.min(...vals)
  const hi = Math.max(...vals)
  const pad = Math.max(3, (hi - lo) * 0.18)
  return [Math.floor(lo - pad), Math.ceil(hi + pad)]
})

const moistRange = computed(() => {
  const vals = props.rows.map((r) => Number(r.moisture_pct)).filter((v) => !Number.isNaN(v))
  if (!vals.length) return [0, 40]
  const lo = Math.min(...vals)
  const hi = Math.max(...vals)
  const pad = Math.max(2, (hi - lo) * 0.2)
  return [Math.floor(Math.max(0, lo - pad)), Math.ceil(hi + pad)]
})

const thresholdLines = computed(() => {
  if (!props.showThreshold || !props.thresholds) return []
  const out = []
  const tr = tempRange.value
  const mr = moistRange.value
  if (props.thresholds.temp_max != null) {
    out.push({
      value: Number(props.thresholds.temp_max),
      color: C.temp,
      axis: showTemp.value ? 'y' : null,
      label: `Batas atas ${num(props.thresholds.temp_max, 0)}°C`,
      range: tr
    })
  }
  if (props.thresholds.temp_min != null) {
    out.push({
      value: Number(props.thresholds.temp_min),
      color: C.moist,
      axis: showTemp.value ? 'y' : null,
      label: `Batas bawah ${num(props.thresholds.temp_min, 0)}°C`,
      range: tr
    })
  }
  if (showMoist.value && props.thresholds.moisture_stop != null) {
    out.push({
      value: Number(props.thresholds.moisture_stop),
      color: C.target,
      axis: showMoist.value ? 'y1' : null,
      label: `Target ${num(props.thresholds.moisture_stop, 1)}%`,
      range: mr
    })
  }
  if (showMoist.value && props.thresholds.moisture_wet != null) {
    out.push({
      value: Number(props.thresholds.moisture_wet),
      color: C.wet,
      axis: showMoist.value ? 'y1' : null,
      label: `Basah ${num(props.thresholds.moisture_wet, 1)}%`,
      range: mr
    })
  }
  return out
})

/** Baris kipas menyala sebagai pita background (rgba dari index fan_on). */
function fanBandPlugin() {
  return {
    id: 'fanBand',
    beforeDatasetsDraw(c) {
      if (!props.showFanBand) return
      const { ctx, chartArea, scales } = c
      if (!chartArea) return
      const y = scales.y
      if (!y) return
      const step = chartArea.width / Math.max(1, props.rows.length)
      ctx.save()
      props.rows.forEach((r, i) => {
        if (!r.fan_on) return
        ctx.fillStyle = C.band
        const x = chartArea.left + i * step
        ctx.fillRect(x, chartArea.top, Math.max(step, 1.2), chartArea.bottom - chartArea.top)
      })
      ctx.restore()
    }
  }
}

/** Plugin garis batas + label sumbu. */
function thresholdPlugin() {
  return {
    id: 'thresholds',
    afterDatasetsDraw(c) {
      const { ctx, chartArea } = c
      if (!chartArea) return
      thresholdLines.value.forEach((l) => {
        const scale = l.axis ? c.scales[l.axis] : c.scales.y
        if (!scale) return
        const y = scale.getPixelForValue(l.value)
        if (y < chartArea.top - 2 || y > chartArea.bottom + 2) return
        ctx.save()
        ctx.strokeStyle = l.color
        ctx.globalAlpha = 0.65
        ctx.setLineDash([5, 5])
        ctx.lineWidth = 1.3
        ctx.beginPath()
        ctx.moveTo(chartArea.left, y)
        ctx.lineTo(chartArea.right, y)
        ctx.stroke()

        ctx.setLineDash([])
        ctx.globalAlpha = 1
        ctx.font = '600 10px Inter, system-ui, sans-serif'
        ctx.fillStyle = l.color
        ctx.textBaseline = 'bottom'
        const text = l.label
        const w = ctx.measureText(text).width
        const right = chartArea.right - 4
        ctx.globalAlpha = 0.16
        ctx.fillRect(right - w - 7, y - 15, w + 9, 14)
        ctx.globalAlpha = 1
        ctx.fillText(text, right - w - 3, y - 3)
        ctx.restore()
      })
    }
  }
}

function gradient(ctx, area, hex, topAlpha = 0.28) {
  if (!area) return hex + '22'
  const g = ctx.createLinearGradient(0, area.top, 0, area.bottom)
  g.addColorStop(0, hexA(hex, topAlpha))
  g.addColorStop(1, hexA(hex, 0))
  return g
}

function hexA(hex, a) {
  const h = hex.replace('#', '')
  const r = parseInt(h.substring(0, 2), 16)
  const g = parseInt(h.substring(2, 4), 16)
  const b = parseInt(h.substring(4, 6), 16)
  return `rgba(${r},${g},${b},${a})`
}

function datasets() {
  const out = []
  if (showTemp.value) {
    out.push({
      label: 'Suhu (°C)',
      data: props.rows.map((r) => (r.temp_c === null ? null : Number(r.temp_c))),
      yAxisID: 'y',
      borderColor: C.temp,
      backgroundColor: (c) => gradient(c.chart.ctx, c.chart.chartArea, C.temp, 0.16),
      borderWidth: 2.2,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBackgroundColor: C.temp,
      tension: 0.32,
      fill: true,
      order: 2
    })
  }
  if (showMoist.value) {
    out.push({
      label: 'Kelembapan (%)',
      data: props.rows.map((r) => (r.moisture_pct === null ? null : Number(r.moisture_pct))),
      yAxisID: 'y1',
      borderColor: C.moist,
      backgroundColor: (c) => gradient(c.chart.ctx, c.chart.chartArea, C.moist, 0.15),
      borderWidth: 2.2,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBackgroundColor: C.moist,
      tension: 0.32,
      fill: true,
      order: 1
    })
  }
  return out
}

function build() {
  if (!canvas.value) return
  if (chart.value) chart.value.destroy()

  const scales = {
    x: {
      grid: { color: C.grid, drawTicks: false },
      border: { display: false },
      ticks: {
        color: C.tick,
        font: { size: 10, family: 'Inter, system-ui' },
        maxRotation: 0,
        autoSkip: true,
        maxTicksLimit: 7,
        callback(v) {
          const d = this.getLabelForValue(v)
          return d ? time(d) : ''
        }
      }
    }
  }

  if (showTemp.value) {
    scales.y = {
      position: 'left',
      suggestedMin: tempRange.value[0],
      suggestedMax: tempRange.value[1],
      grid: { color: C.grid, drawTicks: false },
      border: { display: false },
      ticks: {
        color: C.temp,
        font: { size: 10, family: 'Inter, system-ui' },
        callback: (v) => `${v}°`
      },
      title: { display: true, text: 'Suhu', color: C.temp, font: { size: 10, weight: '600' } }
    }
  }

  if (showMoist.value) {
    scales.y1 = {
      position: 'right',
      suggestedMin: moistRange.value[0],
      suggestedMax: moistRange.value[1],
      grid: { display: false },
      border: { display: false },
      ticks: {
        color: C.moist,
        font: { size: 10, family: 'Inter, system-ui' },
        callback: (v) => `${v}%`
      },
      title: { display: true, text: 'Kelembapan', color: C.moist, font: { size: 10, weight: '600' } }
    }
  }

  chart.value = new Chart(canvas.value, {
    type: 'line',
    data: { labels: labels.value, datasets: datasets() },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      animation: { duration: 700, easing: 'easeOutQuart' },
      layout: { padding: { top: 8, right: 4 } },
      plugins: {
        legend: {
          display: true,
          align: 'end',
          labels: {
            color: C.tick,
            usePointStyle: true,
            pointStyle: 'line',
            boxWidth: 22,
            font: { size: 11, weight: '600', family: 'Inter, system-ui' },
            padding: 14
          }
        },
        tooltip: {
          enabled: false,
          external: (ctx) => {
            const el = tooltipEl.value
            if (!el) return
            const { tooltip } = ctx
            if (!tooltip || tooltip.opacity === 0) {
              el.style.opacity = '0'
              return
            }
            const idx = tooltip.dataPoints?.[0]?.dataIndex ?? 0
            const r = props.rows[idx]
            if (!r) return
            const rh = r.ambient_rh_pct ? `RH ${num(r.ambient_rh_pct, 0)}%` : null
            el.innerHTML = `
              <div class="tt-time">${dateTime(r.t)}</div>
              <div class="tt-row"><span class="tt-dot" style="background:${C.temp}"></span>Suhu <b>${num(r.temp_c, 1)} °C</b></div>
              <div class="tt-row"><span class="tt-dot" style="background:${C.moist}"></span>Kelembapan <b>${num(r.moisture_pct, 1)} %</b></div>
              ${rh ? `<div class="tt-row"><span class="tt-dot" style="background:${C.rh}"></span>RH ambient <b>${rh}</b></div>` : ''}
              <div class="tt-row"><span class="tt-dot" style="background:${r.fan_on ? C.fan : C.off}"></span>Kipas <b>${r.fan_on ? 'nyala ' + num(r.fan_duty, 0) + '%' : 'mati'}</b></div>
              <div class="tt-row"><span class="tt-dot" style="background:${r.heater_on ? C.heater : C.off}"></span>Heater <b>${r.heater_on ? 'nyala ' + num(r.heater_duty, 0) + '%' : 'mati'}</b></div>`
            el.style.opacity = '1'
            const area = ctx.chart.chartArea
            const left = Math.min(
              Math.max(area.left, tooltip.caretX - 130),
              area.right - 268
            )
            el.style.transform = `translate(${left}px, ${Math.max(6, tooltip.caretY - 40)}px)`
          }
        }
      },
      scales
    },
    plugins: [fanBandPlugin(), thresholdPlugin()]
  })
}

onMounted(() => nextTick(build))

watch(
  () => [props.rows, props.metric, props.thresholds],
  () => build(),
  { deep: false }
)

onBeforeUnmount(() => {
  if (chart.value) chart.value.destroy()
})
</script>

<template>
  <div class="chart-wrap" :style="{ height: height + 'px' }">
    <canvas ref="canvas" />
    <div ref="tooltipEl" class="tt" />
    <div v-if="!rows.length" class="chart-empty">
      <span class="skeleton" style="width: 180px; height: 12px" />
      <span class="faint" style="font-size: 12.5px">Belum ada data untuk rentang ini.</span>
    </div>
  </div>
</template>

<style scoped>
.chart-wrap {
  position: relative;
  width: 100%;
}

.tt {
  position: absolute;
  top: 0;
  left: 0;
  min-width: 250px;
  padding: 10px 12px;
  background: var(--popover);
  border: 1px solid var(--border-strong);
  border-radius: 11px;
  box-shadow: var(--shadow-lg);
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.18s var(--ease), transform 0.12s linear;
  z-index: 5;
  font-size: 12.5px;
}

.tt-time {
  font-size: 11px;
  color: var(--text-faint);
  margin-bottom: 7px;
  font-family: var(--mono);
}

.tt-row {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 2.5px 0;
  color: var(--text-dim);
}

.tt-row b {
  margin-left: auto;
  color: var(--text);
  font-family: var(--mono);
  font-size: 12px;
}

.tt-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex: none;
}

.chart-empty {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
}
</style>
