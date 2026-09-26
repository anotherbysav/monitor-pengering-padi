<script setup>
/** Halaman pengaturan lengkap: semua setting dikelompokkan per grup. */
import { ref, computed, onMounted, watch } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import { api, saveSettings, notifyError, notify } from '@/stores'
import { num } from '@/utils/format'

const loading = ref(true)
const saving = ref(false)
const values = ref({})
const metadata = ref([])
const profiles = ref([])
const activeProfile = ref('padi')
const changed = ref(new Set())

const showKeys = ref({})

const GROUPS = [
  { key: 'suhu', title: 'Batas Suhu', icon: 'thermo', desc: 'Ambang suhu yang dipakai kontrol otomatis dan rekomendasi.' },
  { key: 'kelembapan', title: 'Kelembapan', icon: 'droplet', desc: 'Target, batas basah, dan titik berhenti pengeringan.' },
  { key: 'kontrol', title: 'Kontrol Aktuator', icon: 'switch', desc: 'Daya default heater, kipas, dan mode kerja.' },
  { key: 'keamanan', title: 'Keamanan & Keselamatan', icon: 'alert', desc: 'Proteksi terhadap sensor rusak, panas berlebih, dan hubung singkat.' },
  { key: 'sistem', title: 'Sistem & Perangkat', icon: 'server', desc: 'Lokasi, nama perangkat, interval kirim, dan retensi data.' }
]

const byGroup = computed(() => {
  const out = {}
  GROUPS.forEach((g) => {
    out[g.key] = metadata.value.filter((m) => m.group_name === g.key)
  })
  return out
})

const countChanges = computed(() => changed.value.size)

function cast(m, raw) {
  if (m.value_type === 'bool') return raw === '1' || raw === true ? 1 : 0
  if (m.value_type === 'int') return parseInt(raw, 10) || 0
  if (m.value_type === 'float') return Number(raw) || 0
  return raw
}

function isChanged(key) {
  return changed.value.has(key)
}

function set(key, meta, raw) {
  const v = cast(meta, raw)
  if (String(values.value[key]) === String(v)) {
    changed.value.delete(key)
  } else {
    values.value[key] = v
    changed.value.add(key)
  }
}

function revert(key) {
  const meta = metadata.value.find((m) => m.setting_key === key)
  if (meta) {
    values.value[key] = cast(meta, meta.setting_value)
    changed.value.delete(key)
  }
}

function revertAll() {
  metadata.value.forEach((m) => {
    values.value[m.setting_key] = cast(m, m.setting_value)
  })
  changed.value = new Set()
}

/** validasi lokal sebelum kirim */
function localErrors() {
  const e = []
  const v = values.value
  if (Number(v.temp_min) >= Number(v.temp_max)) e.push('Batas bawah suhu harus lebih kecil dari batas atas.')
  if (Number(v.temp_optimal) <= Number(v.temp_min) || Number(v.temp_optimal) >= Number(v.temp_max)) {
    e.push('Suhu optimal harus berada di antara batas bawah dan batas atas.')
  }
  if (Number(v.temp_limit) <= Number(v.temp_max)) e.push('Batas keras harus lebih tinggi dari batas atas suhu.')
  if (Number(v.moisture_stop) > Number(v.moisture_target)) e.push('Batas berhenti harus lebih kecil atau sama dengan kelembapan target.')
  if (Number(v.moisture_stop) >= Number(v.moisture_dry)) e.push('Batas kering harus lebih kecil dari batas berhenti.')
  return e
}

async function save() {
  const errs = localErrors()
  if (errs.length) {
    errs.forEach((e) => notify(e, 'error', 6000))
    return
  }
  saving.value = true
  try {
    const payload = {}
    changed.value.forEach((k) => (payload[k] = values.value[k]))
    const res = await saveSettings(payload)
    if (!(res.data?.failed ?? []).length) {
      changed.value = new Set()
      await load()
    }
  } catch (e) {
    notifyError(e)
  } finally {
    saving.value = false
  }
}

async function load() {
  loading.value = true
  try {
    const res = await api.settings()
    const map = {}
    res.data.metadata.forEach((m) => {
      values.value[m.setting_key] =
        m.value_type === 'bool'
          ? Number(res.data.values[m.setting_key]) === 1 || res.data.values[m.setting_key] === true
            ? 1
            : 0
          : m.value_type === 'float'
            ? Number(res.data.values[m.setting_key])
            : res.data.values[m.setting_key]
      map[m.setting_key] = m.setting_value
    })
    metadata.value = res.data.metadata
    profiles.value = res.data.profiles
    activeProfile.value = res.data.values.material_profile || 'padi'
  } catch (e) {
    notifyError(e)
  } finally {
    loading.value = false
  }
}

watch(pw, () => {}, { deep: true })

onMounted(load)
</script>

<template>
  <div class="set">
    <div v-if="loading" class="grid grid--2">
      <div v-for="i in 4" :key="i" class="card">
        <span class="skeleton" style="display:block;width:60%;height:14px;margin-bottom:16px" />
        <span v-for="j in 4" :key="j" class="skeleton" style="display:block;height:36px;margin-bottom:10px" />
      </div>
    </div>

    <template v-else>
      <div v-if="countChanges" class="set__dirty">
        <AppIcon name="info" :size="16" />
        <span><strong>{{ countChanges }}</strong> pengaturan belum disimpan.</span>
        <div class="row gap-8" style="margin-left: auto">
          <button class="btn btn--sm" @click="revertAll">Batalkan</button>
          <button class="btn btn--primary btn--sm" :disabled="saving" @click="save">
            <AppIcon name="save" :size="14" /> {{ saving ? 'Menyimpan…' : 'Simpan Semua' }}
          </button>
        </div>
      </div>

      <div v-for="g in GROUPS" :key="g.key" class="card">
        <div class="card__head">
          <div>
            <div class="card__title"><AppIcon :name="g.icon" :size="16" /> {{ g.title }}</div>
            <div class="card__subtitle">{{ g.desc }}</div>
          </div>
          <span class="badge">{{ byGroup[g.key].length }} item</span>
        </div>

        <div class="set__list">
          <div
            v-for="m in byGroup[g.key]"
            :key="m.setting_key"
            class="set__item"
            :class="{ 'is-changed': isChanged(m.setting_key) }"
          >
            <div class="set__label">
              <span class="set__label-text">{{ m.label }}</span>
              <span class="set__key mono">{{ m.setting_key }}</span>
            </div>

            <div class="set__control">
              <template v-if="m.value_type === 'bool'">
                <ToggleSwitch
                  :model-value="Number(values[m.setting_key]) === 1"
                  :color="m.setting_key.includes('fault') || m.setting_key.includes('auto_off') ? 'var(--rose)' : 'var(--emerald)'"
                  @update:model-value="set(m.setting_key, m, $event ? 1 : 0)"
                />
              </template>

              <template v-else-if="m.value_type === 'string' && m.setting_key === 'material_profile'">
                <select
                  class="select"
                  :value="values[m.setting_key]"
                  @change="set(m.setting_key, m, $event.target.value)"
                >
                  <option v-for="p in profiles" :key="p.code" :value="p.code">{{ p.name }}</option>
                </select>
              </template>

              <template v-else-if="m.value_type === 'string'">
                <input
                  class="input"
                  :type="showKeys[m.setting_key] ? 'text' : 'password'"
                  :value="values[m.setting_key]"
                  @input="set(m.setting_key, m, $event.target.value)"
                />
              </template>

              <template v-else>
                <div class="set__num">
                  <input
                    class="input input--num"
                    type="number"
                    :step="m.value_type === 'int' ? 1 : 0.1"
                    :min="m.min_value ?? undefined"
                    :max="m.max_value ?? undefined"
                    :value="values[m.setting_key]"
                    @input="set(m.setting_key, m, $event.target.value)"
                  />
                  <span v-if="m.unit" class="set__unit">{{ m.unit }}</span>
                </div>
                <input
                  v-if="m.min_value !== null && m.max_value !== null && m.value_type !== 'int'"
                  class="range"
                  type="range"
                  :min="m.min_value"
                  :max="m.max_value"
                  step="0.1"
                  :value="values[m.setting_key]"
                  style="--track-color: var(--sky); max-width: 180px"
                  @input="set(m.setting_key, m, $event.target.value)"
                />
              </template>

              <button
                v-if="isChanged(m.setting_key)"
                class="set__revert"
                title="Kembalikan nilai awal"
                @click="revert(m.setting_key)"
              >
                <AppIcon name="refresh" :size="13" />
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- profil material -->
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title"><AppIcon name="seedling" :size="16" /> Profil Material</div>
            <div class="card__subtitle">Parameter acuan yang dipakai mesin rekomendasi</div>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Profil</th>
                <th>Kelembapan awal</th>
                <th>Target</th>
                <th>Berhenti</th>
                <th>Suhu aman</th>
                <th>Optimal</th>
                <th>Laju dasar</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in profiles" :key="p.code" :class="{ 'is-active': p.code === activeProfile }">
                <td>
                  <div class="row gap-6">
                    <AppIcon v-if="p.code === activeProfile" name="check" :size="14" style="color: var(--emerald)" />
                    <strong>{{ p.name }}</strong>
                  </div>
                  <div class="hint" style="max-width: 320px; white-space: normal">{{ p.description }}</div>
                </td>
                <td class="mono">{{ num(p.initial_moisture, 1) }}%</td>
                <td class="mono">{{ num(p.target_moisture, 1) }}%</td>
                <td class="mono">{{ num(p.stop_moisture, 1) }}%</td>
                <td class="mono">{{ num(p.safe_temp_min, 0) }}–{{ num(p.safe_temp_max, 0) }}°C</td>
                <td class="mono">{{ num(p.optimal_temp, 0) }}°C</td>
                <td class="mono">{{ num(p.base_rate, 2) }}%/jam</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.set {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.set__dirty {
  position: sticky;
  top: calc(var(--topbar-h) + 8px);
  z-index: 15;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 11px 16px;
  border-radius: var(--radius);
  background: rgba(180, 83, 9, 0.07);
  border: 1px solid rgba(180, 83, 9, 0.28);
  color: var(--amber);
  font-size: 13px;
  box-shadow: var(--shadow-sm);
  flex-wrap: wrap;
}

.set__list {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.set__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 11px 12px;
  border-radius: var(--radius-sm);
  border: 1px solid transparent;
  transition: background 0.25s var(--ease), border-color 0.25s var(--ease);
}

.set__item:hover {
  background: var(--fill-1);
}

.set__item.is-changed {
  background: rgba(180, 83, 9, 0.08);
  border-color: rgba(180, 83, 9, 0.28);
}

.set__label {
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}

.set__label-text {
  font-size: 13.5px;
  font-weight: 600;
}

.set__key {
  font-size: 10.5px;
  color: var(--text-faint);
}

.set__control {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: none;
}

.set__num {
  position: relative;
  display: flex;
  align-items: center;
  width: 110px;
}

.set__num .input {
  padding-right: 34px;
}

.set__unit {
  position: absolute;
  right: 10px;
  font-size: 11px;
  color: var(--text-faint);
  pointer-events: none;
}

.set__revert {
  color: var(--text-faint);
  padding: 5px;
  border-radius: 6px;
  display: grid;
  place-items: center;
  transition: all 0.2s var(--ease);
}

.set__revert:hover {
  color: var(--amber);
  background: rgba(180, 83, 9, 0.14);
}

.table tbody tr.is-active {
  background: rgba(5, 150, 105, 0.07);
}

.table tbody tr.is-active td {
  border-top-color: rgba(5, 150, 105, 0.2);
}

.gap-6 {
  gap: 6px;
}

@media (max-width: 720px) {
  .set__item {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
  .set__control {
    width: 100%;
  }
  .set__num {
    flex: 1;
  }
}
</style>
