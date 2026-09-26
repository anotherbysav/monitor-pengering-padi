/**
 * Klien API terpusat.
 *
 * Prefix API bisa diarahkan lewat env VITE_API_BASE. Ini dipakai saat
 * frontend di-host terpisah dari backend (mis. GitHub Pages untuk
 * tampilan, Render/Railway untuk PHP + MySQL):
 *
 *   VITE_API_BASE=https://aplikasi.onrender.com/api
 *
 * Kosongkan untuk memakai /api pada domain yang sama.
 */
const BASE = import.meta.env.VITE_API_BASE || '/api'


/** Galat API yang membawa pesan dari server. */
export class ApiError extends Error {
  constructor(message, status, code, extra = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    Object.assign(this, extra)
  }
}

async function request(path, { method = 'GET', body, timeout = 20000, signal } = {}) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeout)
  if (signal) signal.addEventListener('abort', () => controller.abort(), { once: true })

  try {
    const res = await fetch(BASE + path, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(body ? { 'Content-Type': 'application/json' } : {})
      },
      body: body ? JSON.stringify(body) : undefined,
      signal: controller.signal
    })

    const text = await res.text()
    let json = null
    try {
      json = text ? JSON.parse(text) : null
    } catch {
      throw new ApiError('Respons server bukan JSON yang valid.', res.status, 'bad_response', { raw: text })
    }

    if (!res.ok || json?.success === false) {
      throw new ApiError(json?.message || `Permintaan gagal (HTTP ${res.status}).`, res.status, json?.code, json || {})
    }
    return json
  } catch (err) {
    if (err instanceof ApiError) throw err
    if (err.name === 'AbortError') throw new ApiError('Permintaan terlalu lama (timeout).', 0, 'timeout')
    if (err instanceof TypeError) {
      throw new ApiError('Tidak dapat terhubung ke server. Pastikan backend PHP sedang berjalan.', 0, 'network')
    }
    throw err
  } finally {
    clearTimeout(timer)
  }
}

const qs = (params = {}) => {
  const p = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') p.append(k, v)
  })
  const s = p.toString()
  return s ? `?${s}` : ''
}

export const api = {
  /* dashboard */
  health: () => request('/health'),
  state: (params = {}) => request('/state' + qs(params)),
  pulse: () => request('/pulse'),

  /* pengaturan */
  settings: () => request('/settings'),
  saveSettings: (payload) => request('/settings', { method: 'PUT', body: payload }),

  /* kontrol */
  control: (actuator, on, duty) => request('/controls', { method: 'POST', body: { actuator, on, duty } }),
  allOff: (reason) => request('/controls/all-off', { method: 'POST', body: { reason } }),

  /* rekomendasi & history */
  recommendation: () => request('/recommendation'),
  recommendationHistory: (limit = 40) => request('/recommendation/history' + qs({ limit })),
  history: (params = {}) => request('/readings/history' + qs(params)),
  stats: (minutes = 60) => request('/readings/stats' + qs({ minutes })),
  exportUrl: (minutes = 1440) => BASE + '/readings/export' + qs({ minutes }),

  /* alert & aktivitas */
  alerts: () => request('/alerts'),
  ackAlert: (id) => request('/alerts/acknowledge', { method: 'POST', body: { id } }),
  clearAlerts: () => request('/alerts/clear', { method: 'POST' }),
  actuatorLogs: (limit = 50) => request('/logs/actuators' + qs({ limit })),

  /* perangkat */
  devices: () => request('/devices'),
  createApiKey: (label, deviceCode) => request('/devices/api-key', { method: 'POST', body: { label, device_code: deviceCode } }),
  deleteApiKey: (id) => request('/devices/api-key', { method: 'DELETE', body: { id } }),

  /* utilitas */
  weather: () => request('/weather'),
  purge: (days) => request('/maintenance/purge', { method: 'POST', body: { days } })
}

export default api
