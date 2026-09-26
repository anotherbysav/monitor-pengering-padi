<script setup>
/** Halaman kontrol: mode, aktuator, batas suhu, dan tombol darurat. */
import { computed, ref, onMounted } from 'vue'
import ControlCard from '@/components/ControlCard.vue'
import ThresholdCard from '@/components/ThresholdCard.vue'
import AppIcon from '@/components/AppIcon.vue'
import { device, setActuator, emergencyStop, saveSettings, ui, loadState, notify, notifyError } from '@/stores'
import { api } from '@/stores'
import { dateTime } from '@/utils/format'

const controls = computed(() => device.controls || {})
const thresholds = computed(() => device.thresholds || {})
/**
 * API mengembalikan controls.mode = 'auto' | 'manual' (bukan mode_auto),
 * jadi Flag auto harus dibaca dari `mode`.
 */
const autoMode = computed(() => controls.value.mode === 'auto')

const settingBusy = ref(false)
const confirmingStop = ref(false)
const logs = ref([])

const limits = computed(() => ({
  min: Number(thresholds.value.temp_min ?? 30),
  max: Number(thresholds.value.temp_max ?? 45),
  optimal: Number(thresholds.value.temp_optimal ?? 38),
  limit: Number(thresholds.value.temp_limit ?? 52)
}))

async function setMode(auto) {
  try {
    settingBusy.value = true
    const res = await api.saveSettings({ auto_mode: auto ? 1 : 0 })
    if (res.data?.state) {
      await loadState({ full: false })
    }
    notify(auto ? 'Mode otomatis diaktifkan.' : 'Mode manual diaktifkan.', 'success')
  } catch (e) {
    notifyError(e)
  } finally {
    settingBusy.value = false
  }
}

async function onSaveThresholds(payload) {
  await saveSettings({
    temp_min: payload.min,
    temp_max: payload.max,
    temp_optimal: payload.optimal,
    temp_limit: payload.limit
  })
}

onMounted(async () => {
  try {
    const r = await api.actuatorLogs(25)
    logs.value = r.data.items
  } catch {
    /* opsional */
  }
})
</script>

<template>
  <div class="ctl">
    <div class="card ctl__mode" :style="{ '--m': autoMode ? 'var(--violet)' : 'var(--sky)' }">
      <div class="ctl__mode-info">
        <span class="ctl__mode-icon">
          <AppIcon :name="autoMode ? 'sparkle' : 'user'" :size="20" />
        </span>
        <div>
          <h2 class="ctl__mode-title">{{ autoMode ? 'Mode Otomatis Aktif' : 'Mode Manual Aktif' }}</h2>
          <p class="card__subtitle">
            {{
              autoMode
                ? 'Heater dan kipas dikendalikan sistem mengikuti batas suhu serta kelembapan target.'
                : 'Perintah aktuator hanya berjalan sesuai tombol yang Anda tekan.'
            }}
          </p>
        </div>
      </div>
      <div class="row gap-8">
        <button class="btn" :class="{ 'btn--primary': !autoMode }" :disabled="settingBusy || !autoMode" @click="setMode(false)">
          <AppIcon name="user" :size="15" /> Manual
        </button>
        <button class="btn" :class="{ 'btn--primary': autoMode }" :disabled="settingBusy || autoMode" @click="setMode(true)">
          <AppIcon name="sparkle" :size="15" /> Otomatis
        </button>
      </div>
    </div>

    <div class="grid grid--2">
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
    </div>

    <div class="grid grid--main">
      <ThresholdCard
        :limits="limits"
        :current="device.reading?.temp_c ?? null"
        :saving="ui.saving"
        @save="onSaveThresholds"
      />

      <div class="col gap-16">
        <div class="card ctl__stop" :class="{ 'is-armed': confirmingStop }">
          <div class="card__head">
            <div>
              <div class="card__title" style="color: var(--rose)">
                <AppIcon name="power" :size="16" />
                Penghentian Darurat
              </div>
              <div class="card__subtitle">Mematikan heater dan kipas serta menonaktifkan mode otomatis.</div>
            </div>
          </div>
          <p class="hint" style="margin-bottom: 14px">
            Gunakan tombol ini bila terjadi supervakum, bakar, atau kondisi berbahaya lainnya. Sistem akan
            kembali normal setelah Anda menyalakan kembali aktuator secara manual.
          </p>
          <button
            v-if="!confirmingStop"
            class="btn btn--danger btn--block"
            style="padding: 12px"
            @click="confirmingStop = true"
          >
            <AppIcon name="alert" :size="16" /> Matikan Semua Sekarang
          </button>
          <div v-else class="col gap-8">
            <span class="hint">Yakin?</span>
            <div class="row gap-8">
              <button class="btn btn--flex1" @click="confirmingStop = false">Batal</button>
              <button class="btn btn--danger btn--flex1" @click="((confirmingStop = false), emergencyStop())">
                Ya, matikan semua
              </button>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card__head">
            <div>
              <div class="card__title"><AppIcon name="history" :size="16" /> Perintah Terakhir</div>
              <div class="card__subtitle">10 aktivitas terakhir</div>
            </div>
          </div>
          <TransitionGroup name="list" tag="div" class="ctl__logs">
            <div v-for="l in logs.slice(0, 10)" :key="l.id" class="ctl__log">
              <span class="ctl__log-icon" :class="l.new_state ? 'is-on' : 'is-off'">
                <AppIcon :name="l.actuator === 'heater' ? 'fire' : 'wind'" :size="13" />
              </span>
              <div style="flex: 1; min-width: 0">
                <div style="font-size: 12.5px; font-weight: 600">
                  {{ l.actuator === 'heater' ? 'Heater' : 'Kipas' }} → {{ l.new_state ? 'Nyala' : 'Mati' }}
                  <span class="mono faint">{{ l.new_state ? `${l.new_duty}%` : '' }}</span>
                </div>
                <div class="faint" style="font-size: 11px">{{ l.reason }}</div>
              </div>
              <span class="badge" :class="l.source === 'auto' ? 'badge--busy' : 'badge--info'">
                {{ l.source }}
              </span>
              <span class="faint mono" style="font-size: 10.5px">{{ dateTime(l.created_at) }}</span>
            </div>
            <p v-if="!logs.length" class="faint text-center" style="padding: 16px; font-size: 12.5px">
              Belum ada aktivitas.
            </p>
          </TransitionGroup>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ctl {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.ctl__mode {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  flex-wrap: wrap;
  --m: var(--sky);
}

.ctl__mode-icon {
  width: 46px;
  height: 46px;
  border-radius: 14px;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--m) 15%, transparent);
  border: 1px solid color-mix(in srgb, var(--m) 30%, transparent);
  color: var(--m);
  flex: none;
}

.ctl__mode-info {
  display: flex;
  align-items: center;
  gap: 14px;
}

.ctl__mode-title {
  font-size: 18px;
  letter-spacing: -0.02em;
}

.ctl__stop.is-armed {
  border-color: rgba(190, 18, 60, 0.5);
  animation: armedPulse 1.6s var(--ease) infinite;
}

@keyframes armedPulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(190, 18, 60, 0.28); }
  50% { box-shadow: 0 0 0 9px rgba(190, 18, 60, 0); }
}

.ctl__logs {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 330px;
  overflow-y: auto;
  position: relative;
}

.ctl__log {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 9px 11px;
  border-radius: var(--radius-sm);
  background: var(--sunken);
  border: 1px solid var(--border);
  font-size: 12.5px;
  transition: border-color 0.25s var(--ease), transform 0.25s var(--ease-out);
}

.ctl__log:hover {
  border-color: var(--border-strong);
  transform: translateX(2px);
}

.ctl__log-icon {
  width: 26px;
  height: 26px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  flex: none;
}

.ctl__log-icon.is-on {
  background: rgba(180, 83, 9, 0.16);
  color: var(--amber);
}

.ctl__log-icon.is-off {
  background: rgba(100, 116, 139, 0.16);
  color: var(--slate);
}

.btn--flex1 {
  flex: 1;
}
</style>
