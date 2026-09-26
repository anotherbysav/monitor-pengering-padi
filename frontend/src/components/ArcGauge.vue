<script setup>
/**
 * ArcGauge: meter busur SVG dengan animasi needle, area risau,
 * dan gradien warna sesuai status.
 */
import { computed } from 'vue'
import { num } from '@/utils/format'

const props = defineProps({
  value: { type: Number, default: 0 },
  min: { type: Number, default: 0 },
  max: { type: Number, default: 100 },
  unit: { type: String, default: '' },
  label: { type: String, default: '' },
  decimals: { type: Number, default: 1 },
  /** [start, end] dalam persen -> zona-optimal (hijau) */
  safe: { type: Array, default: null },
  /** warna untuk nilai di atas batas atas */
  overColor: { type: String, default: 'var(--rose)' },
  underColor: { type: String, default: 'var(--sky)' },
  color: { type: String, default: 'var(--sky)' },
  size: { type: Number, default: 220 },
  icon: { type: String, default: '' }
})

const START = -125
const SWEEP = 250

const pct = computed(() => {
  const span = props.max - props.min || 1
  return Math.min(1, Math.max(0, (props.value - props.min) / span))
})

const angle = computed(() => START + SWEEP * pct.value)

const state = computed(() => {
  if (props.safe && props.value < props.safe[0]) return 'under'
  if (props.safe && props.value > props.safe[1]) return 'over'
  return 'normal'
})

const activeColor = computed(() => {
  if (state.value === 'over') return props.overColor
  if (state.value === 'under') return props.underColor
  return props.color
})

/** Path busur (SVG arc). */
function arcPath(fromPct, toPct) {
  const a0 = START + SWEEP * fromPct
  const a1 = START + SWEEP * toPct
  const r = 78
  const c = 100
  const rad = (a) => (a * Math.PI) / 180
  const p = (a) => {
    const x = c + r * Math.cos(rad(a))
    const y = c + r * Math.sin(rad(a))
    return [x, y]
  }
  const [x0, y0] = p(a0)
  const [x1, y1] = p(a1)
  const large = Math.abs(a1 - a0) > 180 ? 1 : 0
  return `M ${x0.toFixed(2)} ${y0.toFixed(2)} A ${r} ${r} 0 ${large} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`
}

const trackPath = computed(() => arcPath(0, 1))

/** area aktif (nilai sekarang) */
const fillPath = computed(() => {
  const t = Math.max(0.0001, pct.value)
  return `${arcPath(0, t)} L 100 100 L 0 100 Z`
})

/** zona aman */
const safePath = computed(() => {
  if (!props.safe) return ''
  const span = props.max - props.min || 1
  const a = Math.min(1, Math.max(0, (props.safe[0] - props.min) / span))
  const b = Math.min(1, Math.max(0, (props.safe[1] - props.min) / span))
  return b > a ? arcPath(a, b) : ''
})

const needleEnd = computed(() => {
  const r = 60
  const rad = (angle.value * Math.PI) / 180
  return [100 + r * Math.cos(rad), 100 + r * Math.sin(rad)]
})

/** label skala */
const ticks = computed(() => {
  const out = []
  for (let i = 0; i <= 4; i++) {
    const p = i / 4
    const a = ((START + SWEEP * p) * Math.PI) / 180
    out.push({
      x: 100 + 94 * Math.cos(a),
      y: 100 + 94 * Math.sin(a),
      text: num(props.min + (props.max - props.min) * p, props.decimals > 1 ? 1 : 0)
    })
  }
  return out
})
</script>

<template>
  <div class="gauge" :style="{ width: size + 'px', maxWidth: '100%' }">
    <svg viewBox="0 0 200 200" class="gauge__svg" role="img" :aria-label="`${label}: ${num(value, decimals)} ${unit}`">
      <defs>
        <linearGradient id="gaugeTrack" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="var(--chart-track)" />
          <stop offset="100%" stop-color="var(--fill-1)" />
        </linearGradient>
        <filter id="gaugeGlow" x="-50%" y="-50%" width="200%" height="200%">
          <feGaussianBlur stdDeviation="4" result="b" />
          <feMerge>
            <feMergeNode in="b" />
            <feMergeNode in="SourceGraphic" />
          </feMerge>
        </filter>
      </defs>

      <!-- track -->
      <path :d="trackPath" fill="none" stroke="url(#gaugeTrack)" stroke-width="12" stroke-linecap="round" />

      <!-- zona aman -->
      <path
        v-if="safePath"
        :d="safePath"
        fill="none"
        stroke="var(--emerald)"
        stroke-width="3.5"
        stroke-linecap="round"
        opacity=".5"
      />

      <!-- area terisi -->
      <path :d="fillPath" :fill="activeColor" opacity=".1" class="gauge__fill" />

      <!-- progress busur -->
      <path
        :d="arcPath(0, Math.max(0.0001, pct))"
        fill="none"
        :stroke="activeColor"
        stroke-width="12"
        stroke-linecap="round"
        filter="url(#gaugeGlow)"
        class="gauge__arc"
      />

      <!-- skala -->
      <text
        v-for="(t, i) in ticks"
        :key="i"
        :x="t.x"
        :y="t.y"
        class="gauge__tick"
        text-anchor="middle"
        dominant-baseline="middle"
      >
        {{ t.text }}
      </text>

      <!-- needle -->
      <g class="gauge__needle" :style="{ transform: `rotate(${angle + 90}deg)` }">
        <line x1="100" y1="100" :x2="needleEnd[0]" :y2="needleEnd[1]" :stroke="activeColor" stroke-width="2.6" stroke-linecap="round" />
        <circle :cx="needleEnd[0]" :cy="needleEnd[1]" r="4" :fill="activeColor" />
      </g>
      <circle cx="100" cy="100" r="7" :fill="activeColor" opacity=".9" />
      <circle cx="100" cy="100" r="3" fill="var(--on-accent)" />

      <!-- nilai -->
      <text x="100" y="128" text-anchor="middle" class="gauge__value">
        {{ num(value, decimals) }}<tspan class="gauge__unit"> {{ unit }}</tspan>
      </text>
      <text v-if="label" x="100" y="148" text-anchor="middle" class="gauge__label">{{ label }}</text>
    </svg>
  </div>
</template>

<style scoped>
.gauge {
  margin: 0 auto;
  filter: drop-shadow(0 2px 8px rgba(15, 23, 42, 0.12));
}

.gauge__svg {
  width: 100%;
  height: auto;
  overflow: visible;
}

.gauge__arc {
  transition: stroke 0.6s var(--ease);
}

.gauge__fill {
  transition: fill 0.6s var(--ease);
}

.gauge__needle {
  transform-origin: 100px 100px;
  transition: transform 1.1s var(--ease-spring);
}

.gauge__tick {
  font-size: 9px;
  fill: var(--text-faint);
  font-family: var(--mono);
}

.gauge__value {
  font-size: 26px;
  font-weight: 700;
  fill: var(--text);
  font-family: var(--mono);
  letter-spacing: -0.02em;
}

.gauge__unit {
  font-size: 12px;
  font-weight: 600;
  fill: var(--text-dim);
}

.gauge__label {
  font-size: 10.5px;
  font-weight: 600;
  fill: var(--text-faint);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
</style>
