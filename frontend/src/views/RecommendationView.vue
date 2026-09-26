<script setup>
/** Halaman rekomendasi: status, proses, checklist, dan riwayat perubahan. */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import RecommendationCard from '@/components/RecommendationCard.vue'
import FactorPanel from '@/components/FactorPanel.vue'
import AppIcon from '@/components/AppIcon.vue'
import CountUp from '@/components/CountUp.vue'
import { device, loadRecommendation, loadState, notifyError, notify } from '@/stores'
import { api } from '@/stores'
import { num, dateTime, tone, duration } from '@/utils/format'

const loading = ref(false)
const history = ref([])
const loadingHistory = ref(false)

const a = computed(() => device.advisor)
const checklist = computed(() => a.value?.data?.checklist || [])
const conditions = computed(() => a.value?.data?.conditions || [])
const advice = computed(() => a.value?.data?.advice || [])
const risks = computed(() => a.value?.data?.risks || [])
const best = computed(() => a.value?.data?.best_time || null)
const material = computed(() => a.value?.data?.material || null)

const CHECK_LABEL = {
  ready_to_dry: 'Kondisi siap memulai',
  warming_up: 'Pemanasan ruang',
  drying_active: 'Pengeringan berjalan',
  low_moisture: 'Kelembapan rendah',
  too_wet: 'Terlalu basah',
  over_heat: 'Suhu terlalu tinggi',
  heater_dry: 'Heater perlu OFF',
  near_done: 'Hampir selesai',
  fully_dry: 'Sudah kering',
  fault: 'Gangguan sensor',
  hold_or_heat: 'Tahan atau panaskan',
  waiting: 'Menunggu kondisi'
}

const CHECK_TONE = {
  ready_to_dry: 'optimal',
  warming_up: 'warm',
  drying_active: 'good',
  low_moisture: 'good',
  too_wet: 'wait',
  over_heat: 'danger',
  heater_dry: 'warn',
  near_done: 'good',
  fully_dry: 'stop',
  fault: 'danger',
  hold_or_heat: 'warm',
  waiting: 'wait'
}

const status = computed(() => tone(a.value?.status || 'wait'))

const summary = computed(() => {
  if (!a.value) return null
  return {
    rate: Number(a.value.drying_rate),
    eta: a.value.eta?.seconds,
    target: Number(a.value.target_moisture_pct),
    current: Number(device.reading?.moisture_pct),
    score: Number(a.value.score)
  }
})

async function refresh() {
  loading.value = true
  try {
    await loadRecommendation()
    await loadState({ full: false })
    notify('Rekomendasi diperbarui.', 'success', 2200)
  } catch (e) {
    notifyError(e)
  } finally {
    loading.value = false
  }
}

async function loadHistory() {
  loadingHistory.value = true
  try {
    const r = await api.recommendationHistory(40)
    history.value = r.data.items
  } catch (e) {
    notifyError(e)
  } finally {
    loadingHistory.value = false
  }
}

let timer = null

onMounted(() => {
  refresh()
  loadHistory()
  timer = setInterval(() => {
    loadHistory()
  }, 60000)
})

onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div class="rec-page">
    <RecommendationCard
      :advisor="a"
      :reading="device.reading"
      :loading="loading"
      @refresh="refresh"
      @detail="() => {}"
    />

    <section class="grid grid--3">
      <!-- checklist -->
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title"><AppIcon name="check" :size="16" /> Checklist Kondisi</div>
            <div class="card__subtitle">Syarat agar pengeringan berjalan ideal</div>
          </div>
          <span class="badge" :style="{ color: status.hex, borderColor: 'currentColor' }">
            {{ checklist.filter((c) => c.ok).length }}/{{ checklist.length }} terpenuhi
          </span>
        </div>

        <TransitionGroup name="list" tag="ul" class="rec-page__list">
          <li
            v-for="(c, i) in checklist"
            :key="c.key || i"
            class="rec-page__check"
            :class="{ 'is-ok': c.ok }"
            :style="{ '--c': c.ok ? 'var(--emerald)' : 'var(--slate)' }"
          >
            <span class="rec-page__check-icon">
              <AppIcon :name="c.ok ? 'check' : 'x'" :size="12" />
            </span>
            <span class="rec-page__check-label">{{ c.label || CHECK_LABEL[c.key] || c.key }}</span>
            <span v-if="c.value" class="rec-page__check-value mono">{{ c.value }}</span>
          </li>
        </TransitionGroup>
        <p v-if="!checklist.length" class="faint" style="font-size: 12.5px">Belum ada checklist.</p>
      </div>

      <!-- syarat + saran -->
      <div class="col gap-16">
        <div class="card">
          <div class="card__head">
            <div>
              <div class="card__title"><AppIcon name="target" :size="16" /> Syarat Pengeringan</div>
              <div class="card__subtitle">Parameter yang dianalisis</div>
            </div>
          </div>
          <ul class="rec-page__list">
            <li v-for="(c, i) in conditions" :key="'c' + i" :style="{ '--c': tone(c.tone).hex }">
              <span class="rec-page__dot" />
              <span class="rec-page__cond-label">{{ c.label }}</span>
              <span class="rec-page__cond-value mono">{{ c.value }}</span>
            </li>
            <li v-if="!conditions.length" class="faint" style="font-size: 12.5px">Belum ada data syarat.</li>
          </ul>
        </div>

        <div class="card">
          <div class="card__head">
            <div>
              <div class="card__title"><AppIcon name="bulb" :size="16" /> Saran &amp; Peringatan</div>
              <div class="card__subtitle">Tindakan yang disarankan</div>
            </div>
          </div>
          <ul class="rec-page__list">
            <li v-for="(t, i) in advice" :key="'a' + i" style="--c: var(--sky)">
              <AppIcon name="info" :size="13" style="color: var(--sky); flex: none" />
              <span>{{ t }}</span>
            </li>
            <li v-for="(r, i) in risks" :key="'r' + i" style="--c: var(--rose)">
              <AppIcon name="alert" :size="13" style="color: var(--rose); flex: none" />
              <span style="color: var(--rose)">{{ r }}</span>
            </li>
            <li v-if="!advice.length && !risks.length" class="faint" style="font-size: 12.5px">
              Tidak ada catatan khusus.
            </li>
          </ul>
        </div>
      </div>

      <!-- ringkasan material + waktu terbaik -->
      <div class="col gap-16">
        <div v-if="best" class="card">
          <div class="card__head">
            <div>
              <div class="card__title"><AppIcon name="clock" :size="16" /> Waktu Terbaik Pengeringan</div>
              <div class="card__subtitle">Rekomendasi jadwal</div>
            </div>
          </div>
          <div class="rec-page__best" :style="{ '--c': tone(best.tone).hex }">
            <span class="rec-page__best-icon"><AppIcon :name="best.icon || 'clock'" :size="18" /></span>
            <div>
              <div class="rec-page__best-label">{{ best.label }}</div>
              <p class="hint" style="margin-top: 3px">{{ best.detail || best.reason }}</p>
            </div>
          </div>
          <div v-if="best.window" class="rec-page__window">
            <AppIcon name="info" :size="12" /> Jendela waktu: <strong>{{ best.window }}</strong>
          </div>
        </div>

        <FactorPanel :advisor="a" />

        <div v-if="material" class="card">
          <div class="card__head">
            <div>
              <div class="card__title"><AppIcon name="seedling" :size="16" /> Profil Material</div>
              <div class="card__subtitle">Parameter aktif yang dipakai perhitungan</div>
            </div>
          </div>
          <ul class="rec-page__list rec-page__list--mat">
            <li><span>Nama</span><strong>{{ material.name }}</strong></li>
            <li><span>Target kelembapan akhir</span><strong class="mono">{{ num(material.target_moisture_pct, 1) }}%</strong></li>
            <li v-if="material.notes"><span>Catatan</span><span class="muted" style="text-align: right">{{ material.notes }}</span></li>
          </ul>
        </div>
      </div>
    </section>

    <!-- riwayat perubahan -->
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title"><AppIcon name="history" :size="16" /> Riwayat Status Pengeringan</div>
          <div class="card__subtitle">Perubahan status tercatat otomatis (maksimal 1 entri per 5 menit)</div>
        </div>
        <button class="btn btn--sm" :disabled="loadingHistory" @click="loadHistory">
          <AppIcon name="refresh" :size="13" :spin="loadingHistory" /> Muat ulang
        </button>
      </div>

      <div v-if="history.length" class="rec-page__timeline">
        <div v-for="(h, i) in history" :key="h.id" class="rec-page__tl" :style="{ '--c': tone(h.status).hex }">
          <span class="rec-page__tl-dot" :class="{ 'is-first': i === 0 }" />
          <div class="rec-page__tl-body">
            <div class="row row--between" style="gap: 10px; flex-wrap: wrap">
              <strong style="font-size: 13px">{{ h.status_label || h.status }}</strong>
              <span class="faint mono" style="font-size: 11px">{{ dateTime(h.at) }}</span>
            </div>
            <div class="rec-page__tl-meta">
              <span class="mono">{{ num(h.moisture_pct, 1) }}%</span>
              <span class="mono">{{ num(h.temp_c, 1) }}°C</span>
              <span class="muted">{{ h.eta_text || '—' }}</span>
            </div>
            <p v-if="h.note" class="hint" style="margin-top: 3px">{{ h.note }}</p>
          </div>
        </div>
      </div>
      <p v-else class="faint text-center" style="padding: 24px; font-size: 13px">
        Belum ada riwayat perubahan status.
      </p>
    </div>
  </div>
</template>

<style scoped>
.rec-page {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.rec-page__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 7px;
  position: relative;
}

.rec-page__list li {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 12.5px;
  color: var(--text-dim);
  line-height: 1.5;
}

.rec-page__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--c, var(--slate));
  flex: none;
  box-shadow: 0 0 8px -1px var(--c, var(--slate));
}

.rec-page__cond-label {
  flex: 1;
  min-width: 0;
}

.rec-page__cond-value {
  color: var(--text);
  font-weight: 640;
  font-size: 12px;
  white-space: nowrap;
}

.rec-page__check {
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  background: var(--sunken);
  border: 1px solid var(--border);
  transition: all 0.3s var(--ease);
}

.rec-page__check.is-ok {
  border-color: color-mix(in srgb, var(--c) 35%, transparent);
  background: color-mix(in srgb, var(--c) 7%, var(--sunken));
}

.rec-page__check-icon {
  width: 19px;
  height: 19px;
  border-radius: 6px;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--c) 18%, transparent);
  color: var(--c);
  flex: none;
}

.rec-page__check-label {
  flex: 1;
  min-width: 0;
  color: var(--text);
  font-weight: 550;
}

.rec-page__check-value {
  font-size: 11.5px;
  color: var(--text-faint);
  white-space: nowrap;
}

.rec-page__best {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 13px;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, var(--c) 9%, var(--sunken));
  border: 1px solid color-mix(in srgb, var(--c) 28%, transparent);
}

.rec-page__best-icon {
  width: 38px;
  height: 38px;
  border-radius: 11px;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--c) 18%, transparent);
  color: var(--c);
  flex: none;
}

.rec-page__best-label {
  font-size: 15px;
  font-weight: 680;
  color: var(--c);
}

.rec-page__window {
  margin-top: 11px;
  font-size: 12px;
  color: var(--text-faint);
  display: flex;
  align-items: center;
  gap: 6px;
}

.rec-page__list--mat li {
  justify-content: space-between;
  padding: 6px 0;
  border-bottom: 1px dashed var(--border);
}

.rec-page__list--mat li:last-child {
  border-bottom: none;
}

.rec-page__timeline {
  display: flex;
  flex-direction: column;
  gap: 0;
  position: relative;
  padding-left: 4px;
}

.rec-page__tl {
  display: flex;
  gap: 14px;
  padding: 0 0 16px 0;
  position: relative;
}

.rec-page__tl::before {
  content: '';
  position: absolute;
  left: 5px;
  top: 14px;
  bottom: 0;
  width: 1px;
  background: linear-gradient(180deg, var(--c), transparent);
  opacity: 0.35;
}

.rec-page__tl:last-child::before {
  display: none;
}

.rec-page__tl-dot {
  width: 11px;
  height: 11px;
  border-radius: 50%;
  background: var(--c);
  flex: none;
  margin-top: 3px;
  position: relative;
  z-index: 1;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 22%, transparent);
}

.rec-page__tl-dot.is-first {
  animation: dotPulse 1.8s var(--ease) infinite;
}

.rec-page__tl-body {
  flex: 1;
  min-width: 0;
}

.rec-page__tl-meta {
  display: flex;
  gap: 12px;
  font-size: 11.5px;
  color: var(--text-faint);
  margin-top: 3px;
  flex-wrap: wrap;
}
</style>
