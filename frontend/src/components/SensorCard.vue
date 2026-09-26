<script setup>
/** Kartu sensor: gauge besar + tren mini + status koneksi. */
import { computed } from 'vue'
import ArcGauge from './ArcGauge.vue'
import CountUp from './CountUp.vue'
import AppIcon from './AppIcon.vue'
import { num, ago } from '@/utils/format'

const props = defineProps({
  kind: { type: String, required: true }, // 'temp' | 'moist'
  value: { type: Number, default: null },
  trend: { type: Object, default: null },
  online: { type: Boolean, default: false },
  lastSyncSeconds: { type: Number, default: null },
  target: { type: Number, default: null },
  safe: { type: Array, default: null },
  subtitle: { type: String, default: '' }
})

const isTemp = computed(() => props.kind === 'temp')

const cfg = computed(() =>
  isTemp.value
    ? {
        label: 'Suhu',
        unit: '°C',
        icon: 'thermo',
        color: 'var(--amber)',
        min: 20,
        max: 70,
        decimals: 1,
        colorHex: '#b45309'
      }
    : {
        label: 'Kelembapan',
        unit: '%',
        icon: 'droplet',
        color: 'var(--sky)',
        min: 5,
        max: 45,
        decimals: 1,
        colorHex: '#0369a1'
      }
)

const delta = computed(() => {
  const d = Number(props.trend?.delta_10m)
  return Number.isFinite(d) ? d : null
})

const deltaLabel = computed(() => {
  if (delta.value === null) return null
  const sign = delta.value > 0 ? '+' : ''
  return `${sign}${num(delta.value, 2)} ${cfg.value.unit} / 10 menit`
})

const isFalling = computed(() => delta.value !== null && delta.value < 0)
const isRising = computed(() => delta.value !== null && delta.value > 0)

const statusText = computed(() => {
  if (props.value === null) return 'Tidak ada data'
  if (props.safe && props.value > props.safe[1]) return 'Di atas batas'
  if (props.safe && props.value < props.safe[0]) return 'Di bawah batas'
  return 'Dalam zona aman'
})
</script>

<template>
  <div class="card card--hover sensor">
    <div class="sensor__head">
      <div class="row gap-8">
        <span class="sensor__icon" :style="{ color: cfg.color, background: cfg.colorHex + '1f' }">
          <AppIcon :name="cfg.icon" :size="17" />
        </span>
        <div>
          <div class="card__title">{{ cfg.label }}</div>
          <div class="card__subtitle">{{ subtitle || (target !== null ? `Target ${num(target, 1)}${cfg.unit}` : 'Sensor') }}</div>
        </div>
      </div>
      <span class="badge" :class="online ? 'badge--live' : ''">
        <span class="dot" :class="online ? 'dot--pulse' : ''" />
        {{ online ? 'Live' : 'Offline' }}
      </span>
    </div>

    <div class="sensor__gauge">
      <ArcGauge
        :value="value ?? cfg.min"
        :min="cfg.min"
        :max="cfg.max"
        :unit="cfg.unit"
        :label="statusText"
        :decimals="cfg.decimals"
        :safe="safe"
        :color="cfg.color"
        :over-color="isTemp ? 'var(--rose)' : 'var(--emerald)'"
        :under-color="isTemp ? 'var(--sky)' : 'var(--sky)'"
        :size="212"
      />
      <div v-if="value === null" class="sensor__nodata">Data belum tersedia</div>
    </div>

    <div class="sensor__foot">
      <div class="sensor__delta" :class="{ 'is-down': isFalling, 'is-up': isRising }">
        <AppIcon v-if="isFalling" name="download" :size="13" />
        <AppIcon v-else-if="isRising" name="download" :size="13" style="transform: rotate(180deg)" />
        <span class="mono">{{ deltaLabel || 'Belum ada tren' }}</span>
      </div>
      <div class="faint" style="font-size: 11.5px">
        {{ lastSyncSeconds !== null ? `Update ${ago(lastSyncSeconds)}` : 'Menunggu data' }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.sensor {
  display: flex;
  flex-direction: column;
}

.sensor__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.sensor__icon {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  display: grid;
  place-items: center;
  border: 1px solid var(--border);
}

.sensor__gauge {
  position: relative;
  display: flex;
  justify-content: center;
  margin: 4px 0 2px;
}

.sensor__nodata {
  position: absolute;
  inset: auto 0 26px 0;
  text-align: center;
  font-size: 12px;
  color: var(--text-faint);
}

.sensor__foot {
  margin-top: auto;
  padding-top: 14px;
  border-top: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.sensor__delta {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--text-faint);
  padding: 3px 9px;
  border-radius: 999px;
  background: var(--fill-1);
  transition: all 0.35s var(--ease);
}

.sensor__delta.is-down {
  color: var(--emerald-soft);
  background: rgba(5, 150, 105, 0.12);
}

.sensor__delta.is-up {
  color: var(--amber);
  background: rgba(180, 83, 9, 0.12);
}
</style>
