/**
 * Store global (reactive, tanpa Pinia agar ringan).
 */

import { reactive, computed } from 'vue'
import { api, ApiError } from '@/api'

/* Re-export agar komponen cukup mengimpor dari '@/stores'. */
export { api, ApiError }

/* ====================== TOAST ====================== */

let toastSeq = 0
export const toasts = reactive({ items: [] })

export function notify(message, type = 'info', timeout = 4200) {
  if (!message) return
  const id = ++toastSeq
  toasts.items.push({ id, message: String(message), type })
  setTimeout(() => dismissToast(id), timeout)
  return id
}

export function dismissToast(id) {
  const i = toasts.items.findIndex((t) => t.id === id)
  if (i !== -1) toasts.items.splice(i, 1)
}

export const notifyError = (err) => {
  const msg = err instanceof ApiError ? err.message : 'Terjadi kesalahan yang tidak diketahui.'
  notify(msg, 'error', 6000)
  return msg
}

/* ====================== DEVICE / DASHBOARD ====================== */

export const device = reactive({
  loading: false,
  booted: false,
  error: null,
  info: null,
  reading: null,
  trend: null,
  weather: null,
  controls: null,
  target: null,
  advisor: null,
  alerts: [],
  thresholds: null,
  history: { rows: [], step: 0, total: 0 },
  stats: null,
  lastSync: null,
  historyMinutes: 180,
  paused: false
})

export const isOnline = computed(() => {
  const c = device.controls
  return !!c?.online
})

export const hasData = computed(() => device.reading !== null)

export const lastSyncAgo = computed(() => {
  if (!device.lastSync) return null
  return Math.max(0, Math.round((Date.now() - device.lastSync.getTime()) / 1000))
})

function applyState(data) {
  if (!data) return
  device.info = data.device ?? device.info
  device.reading = data.reading ?? null
  device.trend = data.trend ?? device.trend
  device.weather = data.weather ?? device.weather
  device.controls = data.controls ?? device.controls
  device.target = data.target ?? device.target
  device.advisor = data.advisor ?? device.advisor
  device.alerts = data.alerts ?? []
  device.thresholds = data.thresholds ?? device.thresholds
  if (data.history) device.history = data.history
  if (data.stats) device.stats = data.stats
  device.lastSync = new Date()
  device.error = null
}

export async function loadState({ full = true, minutes } = {}) {
  if (device.loading) return
  device.loading = true
  try {
    const res = await api.state({
      history: full ? 1 : 0,
      minutes: minutes ?? device.historyMinutes,
      limit: 700
    })
    applyState(res.data)
    device.booted = true
  } catch (err) {
    device.error = err.message
  } finally {
    device.loading = false
  }
}

let pollTimer = null

export function startPolling(intervalMs = 2500) {
  stopPolling()
  pollTimer = setInterval(async () => {
    if (device.paused || document.hidden) return
    try {
      const res = await api.pulse()
      applyState(res.data)
    } catch (err) {
      device.error = err.message
    }
  }, intervalMs)
}

export function stopPolling() {
  if (pollTimer) clearInterval(pollTimer)
  pollTimer = null
}

/* ====================== ACTIONS ====================== */

let busy = 0
export const ui = reactive({ busy: 0, saving: false })
export const isBusy = computed(() => ui.busy > 0)

function track(promise) {
  ui.busy++
  return promise.finally(() => {
    ui.busy--
  })
}

/**
 * Kirim perintah aktuator dari dashboard. Backend otomatis keluar dari mode
 * auto untuk perintah sumber "user", jadi satu request sudah cukup.
 */
export async function setActuator(actuator, on, duty) {
  const wasAuto = isAuto()
  try {
    const res = await track(api.control(actuator, on, duty))
    applyState(res.data.state)
    if (wasAuto && !isAuto()) {
      notify('Mode otomatis dimatikan agar perintah manual berlaku.', 'info', 4000)
    }
    notify(res.message, 'success', 3000)
    return res
  } catch (err) {
    notifyError(err)
    throw err
  }
}

/** Mode kontrol saat ini: API memakai controls.mode = 'auto' | 'manual'. */
export function isAuto() {
  return (device.controls || {}).mode === 'auto'
}

export async function emergencyStop() {
  try {
    const res = await track(api.allOff('Tombol darurat ditekan dari dashboard'))
    device.controls = res.data.controls
    notify(res.message, 'warning', 5000)
    return res
  } catch (err) {
    notifyError(err)
    throw err
  }
}

export async function saveSettings(payload, { silent = false } = {}) {
  ui.saving = true
  try {
    const res = await api.saveSettings(payload)
    if (res.data?.state) applyState(res.data.state)
    // Pastikan kartu batas suhu langsung memakai nilai yang baru disimpan,
    // walau respons state tidak menyertakan thresholds.
    syncThresholdsFromSettings(payload, res.data?.values)
    const failed = res.data?.failed ?? []
    if (failed.length) {
      failed.forEach((f) => notify(`${f.key}: ${f.error}`, 'error', 6000))
    } else if (!silent) {
      notify(res.message || 'Pengaturan disimpan.', 'success')
    }
    return res
  } catch (err) {
    notifyError(err)
    throw err
  } finally {
    ui.saving = false
  }
}

const TEMP_KEYS = {
  temp_min: 'temp_min',
  temp_max: 'temp_max',
  temp_optimal: 'temp_optimal',
  temp_limit: 'temp_limit'
}

/** Terapkan nilai batas suhu yang baru disimpan ke state lokal. */
function syncThresholdsFromSettings(payload, values) {
  const next = { ...(device.thresholds || {}) }
  let touched = false
  for (const [key, field] of Object.entries(TEMP_KEYS)) {
    if (payload[key] === undefined) continue
    const v = Number(payload[key])
    if (!Number.isFinite(v)) continue
    next[field] = v
    touched = true
  }
  if (!touched) return
  if (values) {
    for (const [key, field] of Object.entries(TEMP_KEYS)) {
      const v = Number(values[key])
      if (Number.isFinite(v)) next[field] = v
    }
  }
  device.thresholds = next
}

export async function loadHistory(minutes = 180) {
  device.historyMinutes = minutes
  const res = await api.history({ minutes, limit: 700 })
  device.history = res.data
  return res.data
}

export async function loadStats(minutes = 60) {
  const res = await api.stats(minutes)
  device.stats = res.data
  return res.data
}

export async function loadRecommendation() {
  const res = await api.recommendation()
  device.advisor = res.data.advisor
  device.weather = res.data.weather
  device.lastSync = new Date()
  return res.data.advisor
}
