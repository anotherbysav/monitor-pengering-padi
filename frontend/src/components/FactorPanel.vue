<script setup>
/**
 * Panel faktor pengeringan.
 * Data berasal dari API (`advisor.data.factors`) berupa list:
 *   { name, value, max, note } -> di sini dinormalisasi menjadi kartu.
 */
import AppIcon from './AppIcon.vue'
import { num, tone } from '@/utils/format'

const props = defineProps({
  advisor: { type: Object, default: null }
})

// Ikon + label baku untuk setiap faktor yang dihitung backend.
const FACTOR_META = {
  Suhu: { label: 'Suhu ruang', icon: 'thermo' },
  'Aliran udara': { label: 'Aliran udara', icon: 'wind' },
  'Gradien lembap': { label: 'Gradien lembap', icon: 'droplet' },
  'Ketebalan lapis': { label: 'Ketebalan lapis', icon: 'layers' },
  'Cuaca / RH': { label: 'Cuaca / RH', icon: 'cloud' }
}

const FACTOR_FALLBACK = { label: 'Faktor', icon: 'scale' }

const factors = () => {
  const list = props.advisor?.data?.factors
  if (!Array.isArray(list)) return []
  return list
    .filter((f) => f && typeof f === 'object')
    .map((f, i) => {
      const meta = FACTOR_META[f.name] || FACTOR_FALLBACK
      const max = Number(f.max) || 1
      const value = Number(f.value) || 0
      const pct = Math.max(0, Math.min(100, Math.round((value / max) * 100)))
      return {
        key: `${f.name || 'faktor'}-${i}`,
        icon: meta.icon,
        label: meta.label,
        value: '×' + num(value, 2),
        detail: f.note || '',
        pct,
        tone: pct >= 90 ? 'good' : pct >= 60 ? 'warn' : 'bad'
      }
    })
}
</script>

<template>
  <div class="card fac">
    <div class="card__head">
      <div>
        <div class="card__title">
          <AppIcon name="scale" :size="16" />
          Faktor Pengeringan
        </div>
        <div class="card__subtitle">Pengaruh tiap faktor terhadap laju pengeringan</div>
      </div>
    </div>

    <div class="fac__list">
      <div v-for="f in factors()" :key="f.key" class="fac__item" :style="{ '--c': (f.tone && tone(f.tone).hex) || 'var(--sky)' }">
        <div class="row row--between" style="margin-bottom: 5px">
          <span class="fac__label">
            <AppIcon :name="f.icon" :size="13" />
            {{ f.label }}
          </span>
          <span class="fac__value mono">{{ f.value }}</span>
        </div>
        <div class="fac__track">
          <div
            class="fac__fill"
            :style="{ width: (f.pct ?? 50) + '%', background: (f.tone && tone(f.tone).hex) || 'var(--sky)' }"
          />
        </div>
        <p v-if="f.detail" class="fac__detail">{{ f.detail }}</p>
      </div>
      <p v-if="!factors().length" class="faint" style="font-size: 12.5px">Belum ada data faktor.</p>
    </div>
  </div>
</template>

<style scoped>
.fac__list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.fac__label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-dim);
}

.fac__value {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--c);
}

.fac__track {
  height: 5px;
  border-radius: 999px;
  background: var(--fill-2);
  overflow: hidden;
}

.fac__fill {
  height: 100%;
  border-radius: 999px;
  transition: width 0.9s var(--ease-out), background 0.5s var(--ease);
  position: relative;
}

.fac__fill::after {
  content: '';
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
  width: 14px;
  background: linear-gradient(90deg, transparent, var(--sheen));
  border-radius: 999px;
}

.fac__detail {
  font-size: 11px;
  color: var(--text-faint);
  margin-top: 4px;
  line-height: 1.45;
}
</style>
