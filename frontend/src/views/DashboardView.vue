<script setup>
/**
 * Dashboard utama: sensor, rekomendasi, kontrol, batas suhu, grafik,
 * statistik, dan log aktivitas.
 */
import { computed, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import SensorCard from '@/components/SensorCard.vue'
import RecommendationCard from '@/components/RecommendationCard.vue'
import ControlCard from '@/components/ControlCard.vue'
import ThresholdCard from '@/components/ThresholdCard.vue'
import HistoryChart from '@/components/HistoryChart.vue'
import StatGrid from '@/components/StatGrid.vue'
import AlertPanel from '@/components/AlertPanel.vue'
import ActivityTable from '@/components/ActivityTable.vue'
import FactorPanel from '@/components/FactorPanel.vue'
import AppIcon from '@/components/AppIcon.vue'
import {
  device,
  lastSyncAgo,
  loadState,
  loadRecommendation,
  setActuator,
  emergencyStop,
  saveSettings,
  ui
} from '@/stores'
import { api, notify, notifyError } from '@/stores'
import { num } from '@/utils/format'

const router = useRouter()

const confirmingStop = ref(false)
const detailOpen = ref(false)
const actuatorLogs = ref([])
const recHistory = ref([])

const reading = computed(() => device.reading || {})
const controls = computed(() => device.controls || {})
const thresholds = computed(() => device.thresholds || {})
/**
 * API mengembalikan controls.mode = 'auto' | 'manual' (bukan mode_auto),
 * jadi flag auto harus dibaca dari `mode`.
 */
const autoMode = computed(() => controls.value.mode === 'auto')

const tempSafe = computed(() => {
  const t = thresholds.value
  if (t.temp_min == null || t.temp_max == null) return null
  return [Number(t.temp_min), Number(t.temp_max)]
})

const moistTarget = computed(() => thresholds.value.moisture_stop ?? null)

const limits = computed(() => ({
  min: Number(thresholds.value.temp_min ?? 30),
  max: Number(thresholds.value.temp_max ?? 45),
  optimal: Number(thresholds.value.temp_optimal ?? 38),
  limit: Number(thresholds.value.temp_limit ?? 52)
}))

const hasReading = computed(() => reading.value.temp_c !== undefined && reading.value.temp_c !== null)

const health = computed(() => {
  if (!hasReading.value) return { tone: 'var(--slate)', label: 'Belum ada data sensor', icon: 'alert' }
  const t = reading.value.temp_c
  const m = reading.value.moisture_pct
  if (t > Number(thresholds.value.temp_limit ?? 52)) {
    return { tone: 'var(--rose)', label: 'Suhu kritis, sistem dimatikan otomatis', icon: 'alert' }
  }
  if (m != null && m <= Number(thresholds.value.moisture_stop ?? 14)) {
    return { tone: 'var(--emerald)', label: 'Kelembapan tercapai, pengeringan bisa dihentikan', icon: 'check' }
  }
  if (t > Number(thresholds.value.temp_max ?? 45)) {
    return { tone: 'var(--orange)', label: 'Suhu melewati batas atas', icon: 'alert' }
  }
  if (t < Number(thresholds.value.temp_min ?? 30)) {
    return { tone: 'var(--sky)', label: 'Suhu di bawah batas bawah', icon: 'info' }
  }
  return { tone: 'var(--emerald)', label: 'Sistem berjalan normal', icon: 'check' }
})

const RANGES = [
  { v: 60, label: '1 jam' },
  { v: 180, label: '3 jam' },
  { v: 360, label: '6 jam' },
  { v: 720, label: '12 jam' },
  { v: 1440, label: '24 jam' },
  { v: 10080, label: '7 hari' }
]
const range = ref(180)

async function changeRange(v) {
  range.value = v
  await loadState({ minutes: v })
  device.historyMinutes = v
}

async function refresh() {
  await loadRecommendation()
  await loadState({ full: false })
  notify('Rekomendasi dihitung ulang.', 'success', 2200)
}

async function doStop() {
  confirmingStop.value = false
  await emergencyStop()
}

async function onSaveThresholds(payload) {
  await saveSettings({
    temp_min: payload.min,
    temp_max: payload.max,
    temp_optimal: payload.optimal,
    temp_limit: payload.limit
  })
}

async function ackAlert(id) {
  try {
    await api.ackAlert(id)
    await loadState({ full: false })
  } catch (e) {
    notifyError(e)
  }
}

async function clearAlerts() {
  try {
    await api.clearAlerts()
    await loadState({ full: false })
    notify('Riwayat peringatan dibersihkan.', 'success')
  } catch (e) {
    notifyError(e)
  }
}

function openDetail() {
  detailOpen.value = true
}

onMounted(async () => {
  try {
    const [a, b] = await Promise.all([api.actuatorLogs(40), api.recommendationHistory(30)])
    actuatorLogs.value = a.data.items
    recHistory.value = b.data.items
  } catch {
    /* log bersifat opsional */
  }
})
</script>

<template>
  <div class="dash">
    <!-- ============ RINGKASAN KESEHATAN ============ -->
    <div class="dash__health" :style="{ '--h': health.tone }">
      <span class="dash__health-icon"><AppIcon :name="health.icon" :size="16" /></span>
      <span class="dash__health-text">{{ health.label }}</span>
      <div class="dash__health-right">
        <span class="badge">Perangkat: <span class="mono">{{ device.info?.code || '—' }}</span></span>
        <button
          v-if="!confirmingStop"
          class="btn btn--danger btn--sm"
          @click="confirmingStop = true"
        >
          <AppIcon name="power" :size="14" /> Darurat: Matikan Semua
        </button>
        <template v-else>
          <span class="hint">Yakin matikan heater &amp; kipas?</span>
          <button class="btn btn--sm" @click="confirmingStop = false">Batal</button>
          <button class="btn btn--danger btn--sm" @click="doStop">Ya, matikan</button>
        </template>
        <button class="btn btn--sm" :disabled="device.loading" @click="loadState()">
          <AppIcon name="refresh" :size="14" :spin="device.loading" /> Muat ulang
        </button>
      </div>
    </div>

    <!-- ============ SENSOR + REKOMENDASI ============ -->
    <section class="grid grid--main">
      <div class="col gap-16">
        <div class="grid grid--2">
          <SensorCard
            kind="temp"
            :value="reading.temp_c ?? null"
            :trend="reading.trend_temp ?? device.trend?.temp ?? null"
            :online="controls.online"
            :last-sync-seconds="lastSyncAgo"
            :safe="tempSafe"
            :target="thresholds.temp_optimal ?? null"
            subtitle="Thermocouple"
          />
          <SensorCard
            kind="moist"
            :value="reading.moisture_pct ?? null"
            :trend="reading.trend_moist ?? device.trend?.moist ?? null"
            :online="controls.online"
            :last-sync-seconds="lastSyncAgo"
            :target="moistTarget"
            subtitle="Sensor moisture"
          />
        </div>

        <div class="card">
          <div class="card__head">
            <div>
              <div class="card__title">
                <AppIcon name="chart" :size="16" />
                Grafik Monitoring
              </div>
              <div class="card__subtitle">
                {{ device.history.rows.length }} titik data • rentang {{ RANGES.find((r) => r.v === range)?.label }}
                <span v-if="device.history.step"> • rata-rata per {{ device.history.step }} detik</span>
              </div>
            </div>
            <div class="dash__ranges">
              <button
                v-for="r in RANGES"
                :key="r.v"
                class="dash__range"
                :class="{ 'is-on': range === r.v }"
                @click="changeRange(r.v)"
              >
                {{ r.label }}
              </button>
            </div>
          </div>
          <HistoryChart :rows="device.history.rows" :thresholds="thresholds" :height="290" />
          <div class="dash__legend">
            <span class="dash__legend-item"><span style="background: rgba(29,78,216,.22)" /> Periode kipas menyala</span>
            <span class="dash__legend-item"><span style="background: #be123c" /> Batas atas</span>
            <span class="dash__legend-item"><span style="background: #0369a1" /> Batas bawah</span>
            <span class="dash__legend-item"><span style="background: #059669" /> Target kelembapan</span>
          </div>
        </div>

        <div class="grid grid--2">
          <AlertPanel :alerts="device.alerts" @ack="ackAlert" @clear="clearAlerts" />
          <FactorPanel :advisor="device.advisor" />
        </div>

        <ActivityTable :actuators="actuatorLogs" :recommendations="recHistory" />
      </div>

      <!-- ============ SISI KANAN ============ -->
      <div class="col gap-16">
        <RecommendationCard
          :advisor="device.advisor"
          :reading="reading"
          :loading="device.loading"
          @refresh="refresh"
          @detail="openDetail"
        />

        <div class="dash__mode">
          <div class="row row--between">
            <div>
              <div class="card__title">
                <AppIcon :name="autoMode ? 'sparkle' : 'user'" :size="15" />
                {{ autoMode ? 'Mode Otomatis' : 'Mode Manual' }}
              </div>
              <div class="card__subtitle">
                {{ autoMode
                  ? 'Sistem mengatur heater & kipas sesuai batas suhu dan kelembapan.'
                  : 'Anda yang mengendalikan aktuator secara manual.' }}
              </div>
            </div>
            <span class="badge" :class="autoMode ? 'badge--busy' : 'badge--info'">
              {{ autoMode ? 'AUTO' : 'MANUAL' }}
            </span>
          </div>
          <p v-if="!autoMode" class="hint" style="margin-top: 10px">
            <AppIcon name="info" :size="12" style="display: inline; vertical-align: -2px" />
            Kembalikan ke mode otomatis dari halaman Kontrol agar pengeringan berjalan optimal.
          </p>
        </div>

        <ControlCard
          actuator="heater"
          :state="controls.heater"
          :auto="autoMode"
          :disabled="ui.busy > 0"
          @toggle="(v, d) => setActuator('heater', v, d)"
          @duty="(v) => setActuator('heater', v > 0, v)"
        />
        <ControlCard
          actuator="fan"
          :state="controls.fan"
          :auto="autoMode"
          :disabled="ui.busy > 0"
          @toggle="(v, d) => setActuator('fan', v, d)"
          @duty="(v) => setActuator('fan', v > 0, v)"
        />

        <ThresholdCard
          :limits="limits"
          :current="reading.temp_c ?? null"
          :saving="ui.saving"
          @save="onSaveThresholds"
        />

        <StatGrid
          :stats="device.stats"
          :reading="reading"
          :advisor="device.advisor"
          :weather="device.weather"
        />
      </div>
    </section>

    <!-- ============ MODAL RINCIAN ============ -->
    <Transition name="fade">
      <div v-if="detailOpen" class="modal" @click.self="detailOpen = false">
        <div class="modal__box">
          <div class="modal__head">
            <h3><AppIcon name="bulb" :size="16" /> Rincian Perhitungan</h3>
            <button class="btn btn--icon" @click="detailOpen = false"><AppIcon name="x" :size="15" /></button>
          </div>
          <div class="modal__body">
            <div v-if="!device.advisor" class="faint">Belum ada data rekomendasi.</div>
            <template v-else>
              <div class="modal__row">
                <span class="muted">Status</span>
                <strong>{{ device.advisor.label }}</strong>
              </div>
              <div class="modal__row">
                <span class="muted">Sasaran kelembapan</span>
                <strong class="mono">{{ num(device.advisor.target_moisture_pct, 1) }}%</strong>
              </div>
              <div class="modal__row">
                <span class="muted">Laju prediksi</span>
                <strong class="mono">{{ num(device.advisor.drying_rate, 2) }} %/jam</strong>
              </div>
              <div class="modal__row">
                <span class="muted">Estimasi sisa waktu</span>
                <strong class="mono">{{ device.advisor.eta?.text || '--' }}</strong>
              </div>
              <div class="modal__row">
                <span class="muted">Skor kondisi</span>
                <strong class="mono">{{ num(device.advisor.score, 0) }}/100</strong>
              </div>
              <div class="modal__row">
                <span class="muted">Cuaca</span>
                <strong>{{ device.weather ? `${device.weather.description || '—'} · ${num(device.weather.temp, 0)}°C` : 'tidak tersedia' }}</strong>
              </div>
              <div class="modal__note">
                <AppIcon name="info" :size="13" />
                <p class="hint">
                  Perhitungan memperhitungkan suhu ruang, aliran udara, RH ambient, ketebalan lapisan,
                  luas area, dan moisture gradient. Nilai bersifat estimasi dan dapat berbeda dari kondisi nyata.
                </p>
              </div>
            </template>
          </div>
          <div class="modal__foot">
            <button class="btn btn--block" @click="router.push('/rekomendasi')">
              <AppIcon name="eye" :size="14" /> Buka halaman Rekomendasi
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.dash {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.dash__health {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border-radius: var(--radius);
  background: color-mix(in srgb, var(--h) 9%, var(--surface-2));
  border: 1px solid color-mix(in srgb, var(--h) 32%, transparent);
  flex-wrap: wrap;
  transition: background 0.5s var(--ease), border-color 0.5s var(--ease);
}

.dash__health-icon {
  width: 30px;
  height: 30px;
  border-radius: 9px;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--h) 20%, transparent);
  color: var(--h);
  flex: none;
}

.dash__health-text {
  font-size: 13.5px;
  font-weight: 620;
  color: var(--text);
  flex: 1;
  min-width: 180px;
}

.dash__health-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.dash__ranges {
  display: flex;
  gap: 2px;
  background: var(--sunken);
  padding: 3px;
  border-radius: 9px;
  border: 1px solid var(--border);
  flex-wrap: wrap;
}

.dash__range {
  padding: 5px 10px;
  font-size: 11.5px;
  font-weight: 600;
  border-radius: 6px;
  color: var(--text-faint);
  transition: all 0.25s var(--ease);
}

.dash__range:hover {
  color: var(--text-dim);
}

.dash__range.is-on {
  background: rgba(3, 105, 161, 0.18);
  color: var(--sky);
}

.dash__legend {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}

.dash__legend-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11.5px;
  color: var(--text-faint);
}

.dash__legend-item span {
  width: 12px;
  height: 3px;
  border-radius: 2px;
}

.dash__mode {
  padding: 16px 18px;
  border-radius: var(--radius);
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-sm);
}

/* modal */
.modal {
  position: fixed;
  inset: 0;
  background: var(--overlay);
  display: grid;
  place-items: center;
  padding: 20px;
  z-index: 60;
}

.modal__box {
  width: min(520px, 100%);
  max-height: 84vh;
  display: flex;
  flex-direction: column;
  background: var(--surface-solid);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  overflow: hidden;
}

.modal__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 18px;
  border-bottom: 1px solid var(--border);
}

.modal__head h3 {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
}

.modal__body {
  padding: 18px;
  overflow-y: auto;
}

.modal__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 9px 0;
  border-bottom: 1px dashed var(--border);
  font-size: 13.5px;
}

.modal__note {
  display: flex;
  gap: 9px;
  margin-top: 16px;
  padding: 11px 12px;
  border-radius: var(--radius-sm);
  background: rgba(3, 105, 161, 0.08);
  border: 1px solid rgba(3, 105, 161, 0.22);
  color: var(--sky);
}

.modal__note p {
  color: var(--text-dim);
  line-height: 1.55;
}

.modal__foot {
  padding: 14px 18px;
  border-top: 1px solid var(--border);
}
</style>
