<script setup>
/**
 * Kartu rekomendasi waktu pengeringan:
 * status, waktu tersisa, laju, progress, dan saran tindakan.
 */
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import ProgressRing from './ProgressRing.vue'
import CountUp from './CountUp.vue'
import { num, duration, tone } from '@/utils/format'

const props = defineProps({
  advisor: { type: Object, default: null },
  reading: { type: Object, default: null },
  loading: { type: Boolean, default: false }
})

const emit = defineEmits(['refresh', 'detail'])

const a = computed(() => props.advisor)

const status = computed(() => {
  const s = a.value?.status || 'wait'
  return tone(s)
})

const etaSeconds = computed(() => {
  const e = a.value?.eta?.seconds
  return Number.isFinite(Number(e)) ? Number(e) : null
})

const etaText = computed(() => a.value?.eta?.text || (etaSeconds.value !== null ? duration(etaSeconds.value) : '--'))

/** progres: moisture sekarang -> moisture target */
const progress = computed(() => {
  const cur = Number(props.reading?.moisture_pct)
  const target = Number(a.value?.target_moisture_pct ?? a.value?.progress?.target)
  const start = Number(a.value?.progress?.from ?? props.reading?.moisture_pct)
  if (!Number.isFinite(cur) || !Number.isFinite(target) || start <= target) return 0
  return Math.min(100, Math.max(0, ((start - cur) / (start - target)) * 100))
})

const score = computed(() => Number(a.value?.score ?? 0))
const rate = computed(() => Number(a.value?.drying_rate ?? 0))
const remainingPct = computed(() => {
  const cur = Number(props.reading?.moisture_pct)
  const target = Number(a.value?.target_moisture_pct)
  if (!Number.isFinite(cur) || !Number.isFinite(target)) return null
  return Math.max(0, cur - target)
})

const bestTime = computed(() => a.value?.data?.best_time || null)
const conditions = computed(() => a.value?.data?.conditions || [])
const advice = computed(() => a.value?.data?.advice || [])
const risks = computed(() => a.value?.data?.risks || [])
const isDone = computed(() => a.value?.status === 'stop')
</script>

<template>
  <div class="card rec" :style="{ '--rec': status.hex }">
    <div class="rec__aura" />

    <div class="card__head" style="position: relative">
      <div>
        <div class="card__title">
          <AppIcon name="bulb" :size="16" style="color: var(--rec)" />
          Rekomendasi Waktu Pengeringan
        </div>
        <div class="card__subtitle">Dihitung dari sensor, cuaca, dan pengaturan material</div>
      </div>
      <div class="row gap-6">
        <span class="badge" :style="{ color: status.hex, borderColor: status.hex + '55', background: status.hex + '18' }">
          <span class="dot dot--pulse" />
          {{ a?.label || 'Menunggu data' }}
        </span>
        <button class="btn btn--icon" :disabled="loading" title="Hitung ulang" @click="emit('refresh')">
          <AppIcon name="refresh" :size="15" :spin="loading" />
        </button>
      </div>
    </div>

    <div v-if="!a" class="rec__loading">
      <span class="skeleton" style="width: 220px; height: 34px" />
      <span class="skeleton" style="width: 100%; height: 12px" />
      <span class="skeleton" style="width: 70%; height: 12px" />
    </div>

    <template v-else>
      <div class="rec__main">
        <div class="rec__eta">
          <span class="section-title">Perkiraan selesai</span>
          <div class="rec__eta-value" :style="{ color: status.hex }">
            <template v-if="!isDone">
              <CountUp v-if="etaSeconds !== null" :value="etaSeconds / 3600" :decimals="1" class="mono" />
              <span v-if="etaSeconds !== null" class="rec__eta-unit">jam</span>
              <template v-else>{{ etaText }}</template>
            </template>
            <template v-else>
              <span>{{ etaText }}</span>
            </template>
          </div>
          <p class="rec__eta-sub">{{ etaText }} ({{ duration(etaSeconds) }})</p>
        </div>

        <ProgressRing :value="progress" :size="118" :stroke="9" :color="status.hex" class="rec__ring">
          <span class="section-title" style="font-size: 9.5px">Progres</span>
        </ProgressRing>

        <div class="rec__metrics">
          <div class="rec__metric">
            <span class="rec__metric-label">Laju pengeringan</span>
            <span class="rec__metric-value mono" :style="{ color: rate > 0 ? 'var(--emerald-soft)' : 'var(--rose)' }">
              <CountUp :value="rate" :decimals="2" /> <small>%/jam</small>
            </span>
          </div>
          <div class="rec__metric">
            <span class="rec__metric-label">Sisa kelembapan</span>
            <span class="rec__metric-value mono">
              <template v-if="remainingPct !== null">{{ num(remainingPct, 1) }} <small>%</small></template>
              <template v-else>--</template>
            </span>
          </div>
          <div class="rec__metric">
            <span class="rec__metric-label">Skor kondisi</span>
            <span class="rec__metric-value mono">
              <CountUp :value="score" :decimals="0" /> <small>/100</small>
            </span>
          </div>
          <div class="rec__metric">
            <span class="rec__metric-label">Sasaran akhir</span>
            <span class="rec__metric-value mono">{{ num(a?.target_moisture_pct, 1) }} <small>%</small></span>
          </div>
        </div>
      </div>

      <!---linear progress-->
      <div class="rec__bar">
        <div class="rec__bar-fill" :style="{ width: progress + '%', background: status.hex }" />
      </div>

      <div class="rec__cols">
        <div v-if="bestTime" class="rec__col">
          <span class="section-title">Waktu terbaik</span>
          <div class="rec__best">
            <AppIcon :name="bestTime.icon || 'clock'" :size="15" :style="{ color: tone(bestTime.tone).hex }" />
            <div>
              <div style="font-weight: 640; font-size: 13.5px">{{ bestTime.label }}</div>
              <div class="hint">{{ bestTime.detail || bestTime.reason }}</div>
            </div>
          </div>
        </div>

        <div class="rec__col">
          <span class="section-title">Kondisi saat ini</span>
          <ul class="rec__list">
            <li v-for="(c, i) in conditions" :key="i" :style="{ '--c': tone(c.tone).hex }">
              <span class="rec__li-dot" />
              <span class="rec__li-label">{{ c.label }}</span>
              <span class="rec__li-value mono">{{ c.value }}</span>
            </li>
          </ul>
        </div>

        <div class="rec__col">
          <span class="section-title">Saran tindakan</span>
          <ul class="rec__list">
            <li v-for="(t, i) in advice" :key="'a' + i" :style="{ '--c': 'var(--sky)' }">
              <span class="rec__li-dot" />
              <span class="rec__li-text">{{ t }}</span>
            </li>
            <li v-for="(r, i) in risks" :key="'r' + i" :style="{ '--c': 'var(--rose)' }" class="rec__risk">
              <span class="rec__li-dot" />
              <span class="rec__li-text">{{ r }}</span>
            </li>
          </ul>
        </div>
      </div>

      <div class="rec__foot">
        <p class="hint" style="flex: 1">
          <AppIcon name="info" :size="12" style="display: inline; vertical-align: -2px" />
          Rekomendasi diperbarui setiap laporan sensor. Waktu merupakan estimasi dan dapat berubah karena cuaca.
        </p>
        <button class="btn btn--sm" @click="emit('detail')">
          <AppIcon name="eye" :size="14" />
          Rincian perhitungan
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.rec {
  --rec: var(--sky);
}

.rec__aura {
  position: absolute;
  top: -30%;
  right: -10%;
  width: 340px;
  height: 240px;
  background: var(--rec);
  opacity: 0.06;
  filter: blur(60px);
  border-radius: 50%;
  transition: background 0.6s var(--ease);
  pointer-events: none;
}

.rec__loading {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 30px 0;
}

.rec__main {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1.05fr);
  align-items: center;
  gap: 22px;
  position: relative;
}

@media (max-width: 860px) {
  .rec__main {
    grid-template-columns: minmax(0, 1fr);
    justify-items: center;
    text-align: center;
  }
}

.rec__eta {
  min-width: 0;
}

.rec__eta-value {
  display: flex;
  align-items: baseline;
  gap: 6px;
  font-size: 42px;
  font-weight: 750;
  letter-spacing: -0.035em;
  line-height: 1.05;
  transition: color 0.5s var(--ease);
}

@media (max-width: 860px) {
  .rec__eta-value {
    justify-content: center;
  }
}

.rec__eta-unit {
  font-size: 17px;
  font-weight: 600;
  opacity: 0.75;
}

.rec__eta-sub {
  font-size: 12.5px;
  color: var(--text-dim);
  margin-top: 4px;
}

.rec__metrics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.rec__metric {
  background: var(--sunken);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 9px 12px;
  transition: border-color 0.3s var(--ease), transform 0.3s var(--ease-out);
}

.rec__metric:hover {
  border-color: var(--border-strong);
  transform: translateY(-2px);
}

.rec__metric-label {
  display: block;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-faint);
  font-weight: 640;
  margin-bottom: 2px;
}

.rec__metric-value {
  font-size: 16px;
  font-weight: 700;
}

.rec__metric-value small {
  font-size: 10.5px;
  color: var(--text-faint);
  font-weight: 600;
}

.rec__bar {
  height: 7px;
  border-radius: 999px;
  background: var(--fill-2);
  overflow: hidden;
  margin: 20px 0 4px;
  position: relative;
}

.rec__bar-fill {
  height: 100%;
  border-radius: 999px;
  transition: width 1.1s var(--ease-out), background 0.5s var(--ease);
  position: relative;
  overflow: hidden;
}

.rec__bar-fill::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent, var(--sheen), transparent);
  animation: shimmerX 2.4s var(--ease) infinite;
}

@keyframes shimmerX {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}

.rec__cols {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 18px;
  margin-top: 18px;
  padding-top: 18px;
  border-top: 1px solid var(--border);
  position: relative;
}

@media (max-width: 900px) {
  .rec__cols {
    grid-template-columns: minmax(0, 1fr);
  }
}

.rec__col {
  min-width: 0;
}

.rec__best {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  margin-top: 10px;
  padding: 10px 12px;
  background: var(--sunken);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
}

.rec__list {
  list-style: none;
  margin: 10px 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.rec__list li {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12.5px;
  color: var(--text-dim);
}

.rec__li-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--c, var(--slate));
  flex: none;
  box-shadow: 0 0 8px -1px var(--c, var(--slate));
}

.rec__li-label {
  flex: 1;
  min-width: 0;
}

.rec__li-value {
  color: var(--text);
  font-weight: 640;
  font-size: 12px;
  white-space: nowrap;
}

.rec__li-text {
  line-height: 1.5;
}

.rec__risk .rec__li-text {
  color: var(--rose);
}

.rec__foot {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
  flex-wrap: wrap;
  position: relative;
}

.gap-6 {
  gap: 6px;
}
</style>
