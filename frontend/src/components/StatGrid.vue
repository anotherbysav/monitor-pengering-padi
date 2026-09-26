<script setup>
/** Grid statistik ringkas untuk dashboard. */
import AppIcon from './AppIcon.vue'
import CountUp from './CountUp.vue'
import { num, int, duration } from '@/utils/format'

const props = defineProps({
  stats: { type: Object, default: null },
  reading: { type: Object, default: null },
  advisor: { type: Object, default: null },
  weather: { type: Object, default: null }
})

const items = () => {
  const s = props.stats
  const a = props.advisor
  const w = props.weather
  return [
    {
      key: 'avg_temp',
      label: 'Rata-rata suhu',
      icon: 'thermo',
      value: s ? Number(s.temp?.avg) : null,
      decimals: 1,
      unit: '°C',
      sub: s ? `${num(s.temp?.min, 1)} – ${num(s.temp?.max, 1)}°` : null,
      color: '#b45309'
    },
    {
      key: 'avg_moist',
      label: 'Rata-rata kelembapan',
      icon: 'droplet',
      value: s ? Number(s.moist?.avg) : null,
      decimals: 1,
      unit: '%',
      sub: s ? `${num(s.moist?.min, 1)} – ${num(s.moist?.max, 1)}%` : null,
      color: '#0369a1'
    },
    {
      key: 'rate',
      label: 'Laju nyata (terukur)',
      icon: 'clock',
      value: s ? Number(s.observed_dry_rate) : null,
      decimals: 2,
      unit: '%/jam',
      sub: s ? `${int(s.samples)} sampel` : null,
      color: '#059669'
    },
    {
      key: 'fan',
      label: 'Kipas menyala',
      icon: 'wind',
      value: s ? Number(s.fan_on_pct) : null,
      decimals: 0,
      unit: '%',
      sub: s ? `dari ${int(s.samples)} sampel` : null,
      color: '#0f766e'
    },
    {
      key: 'eta',
      label: 'Perkiraan selesai',
      icon: 'target',
      text: a?.eta?.text || (a?.eta?.seconds ? duration(a.eta.seconds) : '--'),
      sub: a ? `laju ${num(a.drying_rate, 2)}%/jam` : null,
      color: '#4338ca'
    },
    {
      key: 'weather',
      label: 'Cuaca',
      icon: w?.rain ? 'rain' : 'cloud',
      text: w ? `${num(w.temp, 0)}° · ${w.humidity}% RH` : 'Offline',
      sub: w ? (w.rain ? 'Hujan terdeteksi' : w.description || 'Cerah berawan') : 'Data cuaca tidak tersedia',
      color: w?.rain ? '#be123c' : '#6d28d9'
    }
  ]
}
</script>

<template>
  <div class="grid grid--3">
    <div v-for="it in items()" :key="it.key" class="card card--hover st" :style="{ '--c': it.color }">
      <span class="st__icon">
        <AppIcon :name="it.icon" :size="15" />
      </span>
      <div class="st__body">
        <span class="st__label">{{ it.label }}</span>
        <span class="st__value">
          <template v-if="it.text !== undefined">{{ it.text }}</template>
          <template v-else-if="it.value !== null && !Number.isNaN(it.value)">
            <CountUp :value="it.value" :decimals="it.decimals" />
            <small v-if="it.unit" class="st__unit">{{ it.unit }}</small>
          </template>
          <template v-else class="faint">--</template>
        </span>
        <span v-if="it.sub" class="st__sub">{{ it.sub }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.st {
  display: flex;
  align-items: center;
  gap: 13px;
  padding: 15px 17px;
}

.st__icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: grid;
  place-items: center;
  color: var(--c);
  background: color-mix(in srgb, var(--c) 13%, transparent);
  border: 1px solid color-mix(in srgb, var(--c) 26%, transparent);
  flex: none;
  transition: all 0.35s var(--ease);
}

.st:hover .st__icon {
  box-shadow: 0 0 18px -5px var(--c);
  transform: scale(1.06);
}

.st__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 1px;
}

.st__label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-faint);
  font-weight: 640;
}

.st__value {
  font-size: 19px;
  font-weight: 720;
  letter-spacing: -0.02em;
  line-height: 1.2;
  display: flex;
  align-items: baseline;
  gap: 3px;
}

.st__unit {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-faint);
}

.st__sub {
  font-size: 11px;
  color: var(--text-faint);
}
</style>
