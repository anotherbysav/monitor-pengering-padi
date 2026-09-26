<script setup>
/**
 * Kartu pengaturan batas suhu (slider + input angka) dengan validasi
 * langsung dan pratinjau visual zona.
 */
import { computed, ref, watch } from 'vue'
import AppIcon from './AppIcon.vue'
import { num, clamp } from '@/utils/format'

const props = defineProps({
  limits: { type: Object, default: () => ({ min: 0, max: 0, optimal: 0, limit: 0 }) },
  current: { type: Number, default: null },
  saving: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false }
})

const emit = defineEmits(['save'])

const draft = ref({ ...props.limits })
const dirty = ref(false)

const FIELDS = ['min', 'max', 'optimal', 'limit']

const same = (a, b) => FIELDS.every((k) => Number(a?.[k]) === Number(b?.[k]))

/**
 * Status polling datang setiap ~2.5s sebagai objek baru. Tanpa penjaga ini,
 * draft yang belum disimpan ikut tertimpa dan tombol Simpan tidak pernah aktif.
 */
watch(
  () => props.limits,
  (v) => {
    if (!v) return
    // Sudah sama dengan draft (mis. setelah simpan berhasil) -> bersihkan status.
    if (same(draft.value, v)) {
      dirty.value = false
      return
    }
    // Masih ada edisi yang belum disimpan: abaikan pembaruan dari server.
    if (dirty.value) return
    draft.value = { ...v }
  },
  { deep: true }
)

const SCALE = { min: 20, max: 80 }

const toPct = (v) => ((clamp(Number(v), SCALE.min, SCALE.max) - SCALE.min) / (SCALE.max - SCALE.min)) * 100
const fromPct = (p) => Math.round(SCALE.min + (p / 100) * (SCALE.max - SCALE.min))

const band = computed(() => ({
  left: toPct(draft.value.min),
  width: Math.max(0, toPct(draft.value.max) - toPct(draft.value.min)),
  optimalLeft: toPct(draft.value.optimal),
  optimalWidth: Math.max(0, toPct(draft.value.max) - toPct(draft.value.optimal))
}))

const errors = computed(() => {
  const e = {}
  if (Number(draft.value.min) >= Number(draft.value.max)) e.max = 'Batas atas harus lebih besar dari batas bawah.'
  if (Number(draft.value.optimal) <= Number(draft.value.min) || Number(draft.value.optimal) >= Number(draft.value.max)) {
    e.optimal = 'Suhu optimal harus di antara batas bawah dan atas.'
  }
  if (Number(draft.value.limit) <= Number(draft.value.max)) e.limit = 'Batas aman (cutoff) harus lebih tinggi dari batas atas.'
  return e
})

const hasError = computed(() => Object.keys(errors.value).length > 0)

function set(field, raw) {
  const v = Number(raw)
  if (Number.isNaN(v)) return
  draft.value[field] = clamp(v, SCALE.min, SCALE.max)
  dirty.value = true
}

/** bila batas bawah naik melewati batas atas, geser yanglawannya */
function onMinChange(v) {
  set('min', v)
  if (draft.value.min >= draft.value.max) draft.value.max = Math.min(SCALE.max, draft.value.min + 1)
}

function onMaxChange(v) {
  set('max', v)
  if (draft.value.max <= draft.value.min) draft.value.min = Math.max(SCALE.min, draft.value.max - 1)
  if (draft.value.optimal >= draft.value.max) draft.value.optimal = draft.value.max - 1
}

const markerPct = computed(() =>
  props.current === null ? null : toPct(props.current)
)
</script>

<template>
  <div class="card th">
    <div class="card__head">
      <div>
        <div class="card__title">
          <AppIcon name="sliders" :size="16" />
          Batas Suhu Pengering
        </div>
        <div class="card__subtitle">Atur langsung dari web • berlaku untuk kontrol otomatis</div>
      </div>
      <span v-if="dirty" class="badge badge--warn"><span class="dot dot--pulse" /> Belum disimpan</span>
    </div>

    <!-- skala visual -->
    <div class="th__scale">
      <div class="th__band" :style="{ left: band.left + '%', width: band.width + '%' }" />
      <div class="th__optimal" :style="{ left: band.optimalLeft + '%', width: band.optimalWidth + '%' }" />
      <div v-if="markerPct !== null" class="th__marker" :style="{ left: markerPct + '%' }">
        <span class="th__marker-flag mono">{{ num(current, 1) }}°</span>
      </div>
      <div class="th__ticks">
        <span v-for="v in [20, 30, 40, 50, 60, 70, 80]" :key="v" :style="{ left: toPct(v) + '%' }" class="mono">
          {{ v }}°
        </span>
      </div>
    </div>

    <div class="th__grid">
      <div class="field">
        <label class="field__label" for="th-min">
          <span><span class="th__swatch th__swatch--min" /> Batas bawah</span>
          <span class="mono">{{ num(draft.min, 0) }} °C</span>
        </label>
        <input
          id="th-min"
          class="range"
          type="range"
          min="20"
          max="80"
          step="1"
          :value="draft.min"
          :disabled="disabled"
          style="--track-color: var(--sky)"
          @input="onMinChange($event.target.value)"
        />
        <input
          class="input input--num"
          type="number"
          min="20"
          max="80"
          :value="draft.min"
          :disabled="disabled"
          @input="set('min', $event.target.value)"
        />
      </div>

      <div class="field">
        <label class="field__label" for="th-max">
          <span><span class="th__swatch th__swatch--max" /> Batas atas</span>
          <span class="mono">{{ num(draft.max, 0) }} °C</span>
        </label>
        <input
          id="th-max"
          class="range"
          type="range"
          min="20"
          max="80"
          step="1"
          :value="draft.max"
          :disabled="disabled"
          style="--track-color: var(--rose)"
          @input="onMaxChange($event.target.value)"
        />
        <input
          class="input input--num"
          type="number"
          min="20"
          max="80"
          :value="draft.max"
          :disabled="disabled"
          :class="{ 'input--error': errors.max }"
          @input="set('max', $event.target.value)"
        />
        <span v-if="errors.max" class="hint hint--error">{{ errors.max }}</span>
      </div>

      <div class="field">
        <label class="field__label" for="th-opt">
          <span><span class="th__swatch th__swatch--opt" /> Suhu optimal</span>
          <span class="mono">{{ num(draft.optimal, 0) }} °C</span>
        </label>
        <input
          id="th-opt"
          class="range"
          type="range"
          min="20"
          max="80"
          step="1"
          :value="draft.optimal"
          :disabled="disabled"
          style="--track-color: var(--emerald)"
          @input="set('optimal', $event.target.value)"
        />
        <input
          class="input input--num"
          type="number"
          min="20"
          max="80"
          :value="draft.optimal"
          :disabled="disabled"
          :class="{ 'input--error': errors.optimal }"
          @input="set('optimal', $event.target.value)"
        />
        <span v-if="errors.optimal" class="hint hint--error">{{ errors.optimal }}</span>
      </div>

      <div class="field">
        <label class="field__label" for="th-limit">
          <span><span class="th__swatch th__swatch--limit" /> Batas aman / cutoff</span>
          <span class="mono">{{ num(draft.limit, 0) }} °C</span>
        </label>
        <input
          id="th-limit"
          class="range"
          type="range"
          min="20"
          max="80"
          step="1"
          :value="draft.limit"
          :disabled="disabled"
          style="--track-color: var(--amber)"
          @input="set('limit', $event.target.value)"
        />
        <input
          class="input input--num"
          type="number"
          min="20"
          max="80"
          :value="draft.limit"
          :disabled="disabled"
          :class="{ 'input--error': errors.limit }"
          @input="set('limit', $event.target.value)"
        />
        <span v-if="errors.limit" class="hint hint--error">{{ errors.limit }}</span>
        <span v-else class="hint">Heater &amp; fan otomatis berhenti bila suhu mencapai nilai ini.</span>
      </div>
    </div>

    <div class="th__foot">
      <p class="hint" style="flex: 1">
        <AppIcon name="info" :size="12" style="display: inline; vertical-align: -2px" />
        Nilai ini berlaku umum untuk semua sensor; setiap perangkat tetap punya ambang sensor sendiri.
      </p>
      <button class="btn btn--primary" :disabled="disabled || !dirty || hasError || saving" @click="emit('save', { ...draft })">
        <AppIcon name="save" :size="15" />
        {{ saving ? 'Menyimpan…' : 'Simpan Batas' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.th__scale {
  position: relative;
  height: 46px;
  margin: 6px 0 20px;
  border-radius: var(--radius-sm);
  background: linear-gradient(90deg, rgba(3, 105, 161, 0.12), var(--fill-1) 30%, rgba(190, 18, 60, 0.14));
  border: 1px solid var(--border);
  overflow: visible;
}

.th__band {
  position: absolute;
  top: 8px;
  bottom: 20px;
  background: rgba(5, 150, 105, 0.16);
  border-left: 2px solid var(--sky);
  border-right: 2px solid var(--rose);
  border-radius: 4px;
  transition: all 0.28s var(--ease);
}

.th__optimal {
  position: absolute;
  top: 8px;
  bottom: 8px;
  background: rgba(5, 150, 105, 0.22);
  border-left: 1px dashed rgba(5, 150, 105, 0.8);
  transition: all 0.28s var(--ease);
}

.th__marker {
  position: absolute;
  top: -4px;
  bottom: 4px;
  width: 2px;
  background: var(--text);
  transition: left 0.9s var(--ease-spring);
  z-index: 2;
}

.th__marker-flag {
  position: absolute;
  top: -20px;
  left: 50%;
  transform: translateX(-50%);
  font-size: 10.5px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 5px;
  background: var(--text);
  color: var(--on-accent);
  white-space: nowrap;
}

.th__ticks {
  position: absolute;
  bottom: 2px;
  left: 0;
  right: 0;
  height: 14px;
}

.th__ticks span {
  position: absolute;
  transform: translateX(-50%);
  font-size: 9.5px;
  color: var(--text-faint);
}

.th__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px 20px;
}

@media (max-width: 620px) {
  .th__grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

.th__swatch {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 3px;
  margin-right: 6px;
  vertical-align: 0;
}
.th__swatch--min { background: var(--sky); }
.th__swatch--max { background: var(--rose); }
.th__swatch--opt { background: var(--emerald); }
.th__swatch--limit { background: var(--amber); }

.th__foot {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--border);
  flex-wrap: wrap;
}
</style>
