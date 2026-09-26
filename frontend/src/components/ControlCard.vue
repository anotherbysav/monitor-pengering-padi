<script setup>
/**
 * Kartu kontrol aktuator: menampilkan status AKTUAL (dari sensor) dan
 * perintah (commanded), plus slider duty dan tombol mode otomatis.
 */
import { computed, ref, watch } from 'vue'
import ToggleSwitch from './ToggleSwitch.vue'
import AppIcon from './AppIcon.vue'
import { num } from '@/utils/format'

const props = defineProps({
  actuator: { type: String, required: true }, // 'heater' | 'fan'
  state: { type: Object, default: null },
  auto: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false }
})

const emit = defineEmits(['toggle', 'duty', 'auto'])

const localDuty = ref(0)
const dragging = ref(false)

const cfg = computed(() =>
  props.actuator === 'heater'
    ? {
        name: 'Heater / Pemanas',
        icon: 'fire',
        color: 'var(--orange)',
        hex: '#c2410c',
        min: 20,
        max: 100,
        defaultOn: 70,
        hint: 'Menghangatkan ruang pengering. Otomatis berhenti bila suhu melewati batas atas.'
      }
    : {
        name: 'Kipas / Fan',
        icon: 'wind',
        color: 'var(--sky)',
        hex: '#0369a1',
        min: 0,
        max: 100,
        defaultOn: 85,
        hint: 'Mensirkasikan udara agar kelembapan turun merata dan mencegah jamur.'
      }
)

const on = computed(() => !!props.state?.on)
const commanded = computed(() => !!props.state?.commanded)
const duty = computed(() => Number(props.state?.commanded_duty ?? 0))
const actualDuty = computed(() => Number(props.state?.duty ?? 0))
const pending = computed(() => !!props.state?.pending)

watch(
  duty,
  (v) => {
    if (!dragging.value) localDuty.value = v
  },
  { immediate: true }
)

function onInput(e) {
  localDuty.value = Number(e.target.value)
}

function onChange(e) {
  dragging.value = false
  const v = Number(e.target.value)
  if (v !== duty.value) emit('duty', v)
}

function fill(v) {
  localDuty.value = v
  if (v !== duty.value) emit('duty', v)
}

/**
 * Saklar on/off. Saat dinyalakan, pakai nilai slider yang sedang tampil
 * (bila sudah diatur user) supaya daya tidak ditimpa nilai default.
 */
function onToggle(next) {
  const d = next ? (localDuty.value > 0 ? localDuty.value : cfg.value.defaultOn) : 0
  if (next) localDuty.value = d
  emit('toggle', next, d)
}
</script>

<template>
  <div class="card card--hover act" :class="{ 'act--on': on || commanded }" :style="{ '--act-color': cfg.color }">
    <div class="act__glow" />

    <div class="act__head">
      <div class="row gap-10" style="--c: v-bind(cfg.color)">
        <span class="act__icon">
          <AppIcon :name="cfg.icon" :size="19" />
        </span>
        <div>
          <div class="card__title">{{ cfg.name }}</div>
          <div class="card__subtitle">
            <span class="badge badge--info" style="padding: 2px 8px; font-size: 10.5px">
              {{ auto ? 'Otomatis' : 'Manual' }}
            </span>
          </div>
        </div>
      </div>
      <ToggleSwitch
        :model-value="commanded"
        :disabled="disabled"
        :color="cfg.color"
        :label="commanded ? 'Nyala' : 'Mati'"
        @update:model-value="onToggle"
      />
    </div>

    <!-- status aktual vs perintah -->
    <div class="act__states">
      <div class="act__state" :class="{ 'is-on': on }">
        <span class="act__state-label">Status perangkat</span>
        <span class="act__state-value">
          <span class="dot" :style="{ background: on ? 'var(--emerald)' : 'var(--slate)' }" />
          {{ on ? 'Nyala' : 'Mati' }}
        </span>
        <span v-if="on" class="act__state-dim mono">Daya {{ num(actualDuty, 0) }}%</span>
        <span v-else class="act__state-dim">tidak melapor</span>
      </div>
      <div class="act__arrow">
        <AppIcon name="target" :size="15" />
      </div>
      <div class="act__state act__state--cmd" :class="{ 'is-on': commanded }">
        <span class="act__state-label">Perintah</span>
        <span class="act__state-value">
          <span class="dot dot--pulse" :style="{ background: commanded ? cfg.color : 'var(--slate)' }" />
          {{ commanded ? `Nyala ${num(duty, 0)}%` : 'Mati' }}
        </span>
        <span v-if="pending" class="act__state-dim pending">
          <AppIcon name="clock" :size="11" /> menunggu konfirmasi
        </span>
        <span v-else-if="commanded" class="act__state-dim">terkonfirmasi</span>
        <span v-else class="act__state-dim">tidak aktif</span>
      </div>
    </div>

    <!-- slider duty -->
    <div class="act__duty">
      <div class="row row--between" style="margin-bottom: 8px">
        <span class="section-title">Daya / duty</span>
        <span class="mono" style="font-size: 13px; color: var(--c, var(--text))">
          {{ num(auto ? duty : localDuty, 0) }}<small class="faint">%</small>
        </span>
      </div>
      <input
        class="range"
        type="range"
        :min="cfg.min"
        max="100"
        step="5"
        :value="localDuty"
        :disabled="disabled"
        :style="{ '--track-color': cfg.color }"
        @input="onInput"
        @pointerdown="dragging = true"
        @change="onChange"
      />
      <div class="act__quick">
        <button class="btn btn--sm" :disabled="disabled" @click="fill(30)">30%</button>
        <button class="btn btn--sm" :disabled="disabled" @click="fill(60)">60%</button>
        <button class="btn btn--sm" :disabled="disabled" @click="fill(100)">100%</button>
        <button class="btn btn--sm" :disabled="disabled" @click="fill(cfg.min)">Minimum</button>
      </div>
      <p v-if="auto" class="hint" style="margin-top: 8px">
        Menggeser slider atau meng klik saklar akan beralih ke mode manual.
      </p>
    </div>

    <p class="act__hint">{{ cfg.hint }}</p>
  </div>
</template>

<style scoped>
.act {
  display: flex;
  flex-direction: column;
  gap: 15px;
  transition: transform 0.35s var(--ease-out), box-shadow 0.35s var(--ease-out), border-color 0.35s;
}

.act__glow {
  position: absolute;
  inset: -40% 30% 60% -20%;
  background: var(--act-color);
  opacity: 0;
  filter: blur(46px);
  transition: opacity 0.5s var(--ease);
  pointer-events: none;
}

.act--on .act__glow {
  opacity: 0.07;
}

.act__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  position: relative;
}

.act__icon {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  display: grid;
  place-items: center;
  color: var(--act-color);
  background: color-mix(in srgb, var(--act-color) 14%, transparent);
  border: 1px solid color-mix(in srgb, var(--act-color) 28%, transparent);
  transition: all 0.4s var(--ease);
}

.act--on .act__icon {
  box-shadow: 0 0 20px -4px var(--act-color);
}

.act__states {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 10px;
  background: var(--sunken);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 12px;
  position: relative;
}

.act__state {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.act__state-label {
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--text-faint);
  font-weight: 640;
}

.act__state-value {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13.5px;
  font-weight: 640;
  color: var(--text-dim);
  transition: color 0.35s var(--ease);
}

.act__state.is-on .act__state-value {
  color: var(--text);
}

.act__state--cmd .act__state-value {
  color: var(--act-color);
}

.act__state-dim {
  font-size: 11px;
  color: var(--text-faint);
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.act__state-dim.pending {
  color: var(--amber);
}

.act__arrow {
  color: var(--text-faint);
  display: grid;
  place-items: center;
  opacity: 0.6;
}

.act__duty {
  position: relative;
}

.act__quick {
  display: flex;
  gap: 6px;
  margin-top: 10px;
  flex-wrap: wrap;
}

.act__hint {
  font-size: 11.5px;
  color: var(--text-faint);
  line-height: 1.5;
  border-top: 1px solid var(--border);
  padding-top: 12px;
  position: relative;
}

.gap-10 {
  gap: 10px;
}
</style>
