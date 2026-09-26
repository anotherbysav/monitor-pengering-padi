/** Format & utilitas tampilan. */

export const num = (v, digits = 1, fallback = '--') => {
  const n = Number(v)
  if (v === null || v === undefined || Number.isNaN(n)) return fallback
  return n.toLocaleString('id-ID', {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits
  })
}

export const int = (v, fallback = '--') => {
  const n = parseInt(v, 10)
  return Number.isNaN(n) ? fallback : n.toLocaleString('id-ID')
}

/** 3720 -> "1 jam 2 menit" */
export const duration = (seconds) => {
  const s = Math.max(0, Math.round(Number(seconds) || 0))
  if (s < 60) return `${s} detik`
  const m = Math.floor(s / 60)
  if (m < 60) return `${m} menit`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h} jam ${m % 60} menit`
  const d = Math.floor(h / 24)
  if (d < 7) return `${d} hari ${h % 24} jam`
  return `${Math.round(d / 7)} minggu`
}

/** "3 jam lalu" */
export const ago = (seconds) => {
  const s = Math.max(0, Math.round(Number(seconds) || 0))
  if (s < 10) return 'baru saja'
  if (s < 60) return `${s} detik lalu`
  const m = Math.floor(s / 60)
  if (m < 60) return `${m} menit lalu`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h} jam lalu`
  const d = Math.floor(h / 24)
  return `${d} hari lalu`
}

export const time = (iso) => {
  if (!iso) return '--'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '--'
  return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

export const dateTime = (iso) => {
  if (!iso) return '--'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '--'
  return d.toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  })
}

export const dateShort = (iso) => {
  if (!iso) return '--'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '--'
  return d.toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

/** Peta warna status pengeringan. */
export const TONE = {
  optimal: { color: 'var(--emerald)', hex: '#059669', label: 'Optimal' },
  good: { color: 'var(--teal)', hex: '#0f766e', label: 'Baik' },
  warm: { color: 'var(--amber)', hex: '#b45309', label: 'Hangat' },
  hot: { color: 'var(--orange)', hex: '#c2410c', label: 'Panas' },
  risk: { color: 'var(--rose)', hex: '#be123c', label: 'Berisiko' },
  stop: { color: 'var(--slate)', hex: '#64748b', label: 'Selesai' },
  danger: { color: 'var(--rose)', hex: '#be123c', label: 'Bahaya' },
  offline: { color: 'var(--slate)', hex: '#64748b', label: 'Offline' },
  wait: { color: 'var(--sky)', hex: '#0369a1', label: 'Menunggu' },
  ready: { color: 'var(--emerald)', hex: '#059669', label: 'Siap' },
  slow: { color: 'var(--amber)', hex: '#b45309', label: 'Lambat' },
  neutral: { color: 'var(--slate)', hex: '#64748b', label: 'Netral' }
}

export const tone = (key) => TONE[key] || TONE.neutral

/** Peta ikon status (SVG inline). */
export const ICONS = {
  gauge: 'M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm1.5-3.5L18 6',
  chart: 'M4 19V5m0 14h16M8 16v-4m4 4V8m4 8v-6',
  switch: 'M7 8h10a4 4 0 1 1 0 8H7a4 4 0 1 1 0-8Zm0 0V6m0 12v2',
  bulb: 'M9 18h6m-5 3h4M12 3a6 6 0 0 0-3.5 10.9c.5.4.8 1 .8 1.6h5.4c0-.6.3-1.2.8-1.6A6 6 0 0 0 12 3Z',
  sliders: 'M4 7h9m4 0h3M4 17h3m4 0h9M13 4v6M7 14v6',
  chip: 'M9 3v3m6-3v3M9 18v3m6-3v3M3 9h3m-3 6h3m12-6h3m-3 6h3M6 6h12v12H6zM10 10h4v4h-4z',
  check: 'm5 13 4 4 10-10',
  x: 'M6 6l12 12M18 6 6 18',
  alert: 'M12 9v4m0 4h.01M10.3 4.3 2.6 17.6A2 2 0 0 0 4.3 20.6h15.4a2 2 0 0 0 1.7-3l-7.7-13.3a2 2 0 0 0-3.4 0Z',
  info: 'M12 16v-5m0-3h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
  user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
  logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9',
  download: 'M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2',
  refresh: 'M21 12a9 9 0 1 1-2.6-6.4M21 4v5h-5',
  clock: 'M12 7v5l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
  droplet: 'M12 3s6 6.3 6 10.5A6 6 0 0 1 6 13.5C6 9.3 12 3 12 3Z',
  thermo: 'M14 14.8V5a2 2 0 1 0-4 0v9.8a4 4 0 1 0 4 0Z',
  wind: 'M3 8h9a3 3 0 1 0-3-3M3 12h13a3 3 0 1 1-3 3M3 16h7a2.5 2.5 0 1 1-2.5 2.5',
  fire: 'M12 3s5 4.5 5 9a5 5 0 0 1-10 0c0-2 1-3.5 2-5 0 1.5 1 2.5 2 2.5s1.5-1 1-3.5c0-1 .5-2 0-3Z',
  power: 'M12 4v8m6.4-5.6a9 9 0 1 1-12.8 0',
  cloud: 'M7 18a4 4 0 0 1 0-8 5.5 5.5 0 0 1 10.5-1.5A4 4 0 0 1 18 18H7Z',
  rain: 'M7 15a4 4 0 0 1 0-8 5.5 5.5 0 0 1 10.5-1.5A4 4 0 0 1 18 15M9 18l-1 3m6-3-1 3m5-3-1 3',
  history: 'M3 12a9 9 0 1 0 3-6.7M3 4v5h5m4 0v5l3.5 2',
  key: 'M15.5 8.5a4 4 0 1 0-3.9 5L14 16h-3v3H8v3H3v-5l6.6-6.6a4 4 0 0 1 5.9-1.9ZM7.5 14h.01',
  trash: 'M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-9 0 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13',
  plus: 'M12 5v14M5 12h14',
  menu: 'M4 7h16M4 12h16M4 17h16',
  eye: 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  copy: 'M9 9h10v10H9zM5 15H4V4h11v1',
  save: 'M5 4h11l3 3v13H5zM8 4v6h7V4M8 20v-6h8v6',
  target: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-4.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0-3a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
  layers: 'M12 3 3 8l9 5 9-5-9-5ZM3 13l9 5 9-5M3 17.5l9 5 9-5',
  pause: 'M8 5v14M16 5v14',
  play: 'M7 4.5v15l13-7.5-13-7.5Z',
  wifi: 'M2 8.8a15 15 0 0 1 20 0M5 12.3a10.10 0 0 1 14 0M8.5 15.8a5 5 0 0 1 7 0M12 19.5h.01',
  server: 'M4 5h16v5H4zM4 14h16v5H4zM7.5 7.5h.01M7.5 16.5h.01',
  sparkle: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3Z',
  scale: 'M12 3v18M7 7l-4 8h8L7 7Zm10 0-4 8h8l-4-8ZM7 7h10',
  seedling: 'M12 21v-8m0 0c0-3 2-5 5-5 0 3-2 5-5 5Zm0 0c0-2.5-1.6-4.5-4.2-4.5C7.8 11 9.4 13 12 13Z'
}

export const icon = (name, size = 18, stroke = 1.7) =>
  `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" stroke-width="${stroke}" stroke-linecap="round" stroke-linejoin="round"><path d="${ICONS[name] || ICONS.info}"/></svg>`

export const clamp = (v, min, max) => Math.min(max, Math.max(min, v))

/**
 * Ambil nilai custom property dari :root.
 * Dipakai kode JS (Chart.js, SVG) agar warna selalu mengikuti tema CSS,
 * bukan ditulis mati di dalam JavaScript.
 */
export const cssVar = (name, fallback = '#334155') => {
  if (typeof window === 'undefined' || !document?.documentElement) return fallback
  const v = getComputedStyle(document.documentElement).getPropertyValue(name)
  return v ? v.trim() : fallback
}
