/**
 * Mode demo untuk GitHub Pages.
 *
 * GitHub Pages hanya bisa hosting file statis, tidak bisa menjalankan PHP
 * maupun MySQL. Supaya dashboard tetap tampil utuh di sana, modul ini
 * memakai snapshot respons API asli (demo-state.json) lalu menggeser
 * nilainya sedikit setiap polling supaya terlihat "hidup".
 *
 * Fixture diambil dari GET /api/state sungguhan, jadi bentuk datanya
 * dijamin sama dengan yang dipakai aplikasi.
 */
import fixture from './demo-state.json'

/** Mode demo aktif? */
export const DEMO = import.meta.env.VITE_DEMO === '1'

let tick = 0

const round = (v, d = 1) => (v === null || v === undefined ? v : Number(Number(v).toFixed(d)))

/**
 * Bangun state demo dari fixture.
 * Setiap panggilan menggeser suhu/kelembapan dan menggeser waktu ke
 * belakang sehingga grafik tidak terlihat beku.
 */
export function demoState() {
  tick += 1

  const base = JSON.parse(JSON.stringify(fixture))
  const wave = Math.sin(tick / 6)

  // Geser pembacaan terkini
  const r = base.reading || {}
  const tempNow = Number(r.temp_c ?? 38)
  const moistNow = Number(r.moisture_pct ?? 24)

  r.temp_c = round(tempNow + wave * 0.6)
  r.temp = r.temp_c
  r.moisture_pct = round(moistNow + wave * 0.25)
  r.moisture = r.moisture_pct
  r.ambient_rh_pct = round(Number(r.ambient_rh_pct ?? 61) + wave * 0.4)
  r.ambient_temp_c = round(Number(r.ambient_temp_c ?? 28) + wave * 0.3)
  r.fresh = true
  r.source = 'demo'
  r.age_seconds = 0

  const t = new Date()
  const iso = t.toISOString()
  r.recorded_at = iso
  r.t = iso

  // Geser label waktu relatif supaya tidak dianggap data basi
  if (r.trend) {
    r.trend_temp = r.trend_temp || {}
    r.trend_moist = r.trend_moist || {}
    r.trend = { temp_per_min: 0.05, moist_per_hour: -0.4, span_minutes: 10 }
  }

  // Status perangkat: sensor "online" supaya badge hijau
  const c = base.controls || {}
  const auto = c.mode === 'auto'
  c.online = true
  c.last_seen = iso
  if (c.heater) {
    c.heater.on = auto ? tempNow < 43 : !!c.heater.commanded
    c.heater.duty = c.heater.on ? 70 : 0
    c.heater.pending = false
    c.heater.updated_at = iso
  }
  if (c.fan) {
    c.fan.on = auto ? moistNow > 15 : !!c.fan.commanded
    c.fan.duty = c.fan.on ? 85 : 0
    c.fan.pending = false
    c.fan.updated_at = iso
  }

  // Grafik: geser deret waktu supaya sumbu waktu tidak kaku
  const rows = base.history?.rows
  if (Array.isArray(rows) && rows.length) {
    base.history.rows = rows.map((row, i) => {
      const drift = wave * 0.35 + i * 0.004
      return { ...row, temp_c: round(Number(row.temp_c ?? tempNow) + drift) }
    })
  }

  return base
}

/** Balasan endpoint lain saat mode demo. */
export function demoResponse(path) {
  const state = demoState()

  if (path.startsWith('/pulse')) return state
  if (path.startsWith('/recommendation')) return state.advisor
  if (path.startsWith('/readings/history')) return state.history
  if (path.startsWith('/readings/stats')) return state.stats
  if (path.startsWith('/settings')) return { values: {}, metadata: [] }
  if (path.startsWith('/alerts')) return state.alerts || []
  if (path.startsWith('/devices')) return { devices: state.device ? [state.device] : [], api_keys: [] }

  return state
}
