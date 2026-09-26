<script setup>
/** Halaman riwayat: grafik, statistik, dan ekspor CSV. */
import { ref, computed, onMounted, watch } from 'vue'
import HistoryChart from '@/components/HistoryChart.vue'
import StatGrid from '@/components/StatGrid.vue'
import AppIcon from '@/components/AppIcon.vue'
import { device, loadState, loadStats, notifyError, notify } from '@/stores'
import { api } from '@/stores'
import { num, int, dateTime } from '@/utils/format'

const RANGES = [
  { v: 60, label: '1 jam' },
  { v: 180, label: '3 jam' },
  { v: 360, label: '6 jam' },
  { v: 720, label: '12 jam' },
  { v: 1440, label: '24 jam' },
  { v: 10080, label: '7 hari' }
]

const minutes = ref(360)
const statsMinutes = ref(360)
const metric = ref('both')
const autoRefresh = ref(true)
const tableRows = ref([])
const loadingTable = ref(false)

const STAT_RANGES = [
  { v: 15, label: '15 menit' },
  { v: 60, label: '1 jam' },
  { v: 360, label: '6 jam' },
  { v: 1440, label: '24 jam' }
]

const thresholds = computed(() => device.thresholds || {})

const tableWindow = computed(() => ({
  start: device.history.rows[0]?.t,
  end: device.history.rows[device.history.rows.length - 1]?.t
}))

let timer = null

async function reload() {
  try {
    await loadState({ minutes: minutes.value })
    await loadStats(statsMinutes.value)
  } catch (e) {
    notifyError(e)
  }
}

async function fetchTable() {
  loadingTable.value = true
  try {
    const r = await api.history({ minutes: minutes.value, limit: 200 })
    tableRows.value = r.data.rows
  } catch (e) {
    notifyError(e)
  } finally {
    loadingTable.value = false
  }
}

function exportCsv() {
  window.location.href = api.exportUrl(minutes.value)
  notify('Mengunduh data CSV…', 'info')
}

function toggleAuto(v) {
  autoRefresh.value = v
  setupTimer()
}

function setupTimer() {
  clearInterval(timer)
  if (autoRefresh.value) timer = setInterval(reload, 15000)
}

watch([minutes, statsMinutes], () => {
  reload()
  fetchTable()
})

onMounted(() => {
  reload()
  fetchTable()
  setupTimer()
})
</script>

<template>
  <div class="hist">
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title"><AppIcon name="chart" :size="16" /> Grafik Riwayat</div>
          <div class="card__subtitle">
            {{ device.history.rows.length }} titik • total {{ int(device.history.total) }} baris di database
            <span v-if="device.history.step"> • agregasi {{ device.history.step }} detik</span>
          </div>
        </div>
        <div class="row gap-8 row--wrap">
          <div class="hist__seg">
            <button
              v-for="m in ['both', 'temp', 'moist']"
              :key="m"
              class="hist__seg-btn"
              :class="{ 'is-on': metric === m }"
              @click="metric = m"
            >
              {{ m === 'both' ? 'Semua' : m === 'temp' ? 'Suhu' : 'Kelembapan' }}
            </button>
          </div>
          <button class="btn btn--sm" :class="{ 'btn--primary': autoRefresh }" @click="toggleAuto(!autoRefresh)">
            <AppIcon :name="autoRefresh ? 'pause' : 'play'" :size="13" />
            {{ autoRefresh ? 'Auto-refresh' : 'Refresh' }}
          </button>
          <button class="btn btn--sm" @click="exportCsv">
            <AppIcon name="download" :size="14" /> Ekspor CSV
          </button>
        </div>
      </div>

      <div class="hist__ranges">
        <span class="section-title" style="margin-right: 4px">Rentang</span>
        <button
          v-for="r in RANGES"
          :key="r.v"
          class="hist__range"
          :class="{ 'is-on': minutes === r.v }"
          @click="minutes = r.v"
        >
          {{ r.label }}
        </button>
      </div>

      <HistoryChart :rows="device.history.rows" :thresholds="thresholds" :height="360" :metric="metric" />
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title"><AppIcon name="scale" :size="16" /> Statistik Rentang</div>
          <div class="card__subtitle">Ringkasan angka lectura sensor</div>
        </div>
        <div class="hist__seg">
          <button
            v-for="r in STAT_RANGES"
            :key="r.v"
            class="hist__seg-btn"
            :class="{ 'is-on': statsMinutes === r.v }"
            @click="statsMinutes = r.v"
          >
            {{ r.label }}
          </button>
        </div>
      </div>
      <StatGrid :stats="device.stats" :reading="device.reading" :advisor="device.advisor" :weather="device.weather" />
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title"><AppIcon name="layers" :size="16" /> Tabel Data</div>
          <div class="card__subtitle">
            200 titik terbaru dalam rentang {{ RANGES.find((r) => r.v === minutes)?.label }}
            <span v-if="tableWindow.start"> • {{ dateTime(tableWindow.start) }} → {{ dateTime(tableWindow.end) }}</span>
          </div>
        </div>
        <button class="btn btn--sm" :disabled="loadingTable" @click="fetchTable">
          <AppIcon name="refresh" :size="13" :spin="loadingTable" /> Muat tabel
        </button>
      </div>

      <div class="table-wrap scroll-y" style="max-height: 460px">
        <table class="table">
          <thead>
            <tr>
              <th>Waktu</th>
              <th>Suhu</th>
              <th>Kelembapan</th>
              <th>RH</th>
              <th>Heater</th>
              <th>Kipas</th>
              <th>Sumber</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in tableRows" :key="r.id ?? r.t">
              <td class="mono faint">{{ dateTime(r.t) }}</td>
              <td class="mono">{{ num(r.temp_c, 1) }}°</td>
              <td class="mono">{{ num(r.moisture_pct, 1) }}%</td>
              <td class="mono faint">{{ r.ambient_rh_pct != null ? num(r.ambient_rh_pct, 0) + '%' : '—' }}</td>
              <td>
                <span class="badge" :class="r.heater_on ? 'badge--warn' : ''">
                  <span class="dot" /> {{ r.heater_on ? `ON ${num(r.heater_duty, 0)}%` : 'OFF' }}
                </span>
              </td>
              <td>
                <span class="badge" :class="r.fan_on ? 'badge--info' : ''">
                  <span class="dot" /> {{ r.fan_on ? `ON ${num(r.fan_duty, 0)}%` : 'OFF' }}
                </span>
              </td>
              <td class="faint">{{ r.source || '—' }}</td>
            </tr>
            <tr v-if="!tableRows.length">
              <td colspan="7" class="text-center faint" style="padding: 24px">
                {{ loadingTable ? 'Memuat…' : 'Belum ada data.' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.hist {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.hist__seg {
  display: flex;
  gap: 2px;
  background: var(--sunken);
  padding: 3px;
  border-radius: 9px;
  border: 1px solid var(--border);
}

.hist__seg-btn {
  padding: 5px 11px;
  font-size: 11.5px;
  font-weight: 600;
  border-radius: 6px;
  color: var(--text-faint);
  transition: all 0.25s var(--ease);
}

.hist__seg-btn:hover {
  color: var(--text-dim);
}

.hist__seg-btn.is-on {
  background: rgba(3, 105, 161, 0.18);
  color: var(--sky);
}

.hist__ranges {
  display: flex;
  align-items: center;
  gap: 3px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}

.hist__range {
  padding: 5px 11px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 7px;
  color: var(--text-faint);
  border: 1px solid transparent;
  transition: all 0.25s var(--ease);
}

.hist__range:hover {
  color: var(--text-dim);
  background: var(--fill-1);
}

.hist__range.is-on {
  background: rgba(3, 105, 161, 0.16);
  color: var(--sky);
  border-color: rgba(3, 105, 161, 0.3);
}
</style>
