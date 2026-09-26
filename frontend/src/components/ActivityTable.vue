<script setup>
/** Tabel aktivitas: log perintah aktuator + riwayat rekomendasi. */
import { ref, computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { dateTime, num } from '@/utils/format'

const props = defineProps({
  actuators: { type: Array, default: () => [] },
  recommendations: { type: Array, default: () => [] }
})

const tab = ref('actuators')

const STATUS_TONE = {
  optimal: 'var(--emerald)',
  good: 'var(--teal)',
  warm: 'var(--amber)',
  hot: 'var(--orange)',
  risk: 'var(--rose)',
  stop: 'var(--slate)',
  wait: 'var(--sky)'
}

const rows = computed(() => tab.value === 'actuators' ? props.actuators : props.recommendations)
</script>

<template>
  <div class="card act">
    <div class="card__head">
      <div>
        <div class="card__title">
          <AppIcon name="history" :size="16" />
          Log Aktivitas
        </div>
        <div class="card__subtitle">Perintah aktuator dan perubahan rekomendasi</div>
      </div>
      <div class="act__tabs">
        <button class="act__tab" :class="{ 'is-on': tab === 'actuators' }" @click="tab = 'actuators'">
          Aktuator
        </button>
        <button class="act__tab" :class="{ 'is-on': tab === 'recommendations' }" @click="tab = 'recommendations'">
          Rekomendasi
        </button>
      </div>
    </div>

    <div class="table-wrap scroll-y" style="max-height: 340px">
      <table v-if="tab === 'actuators'" class="table">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Aktuator</th>
            <th>Status</th>
            <th>Daya</th>
            <th>Sumber</th>
            <th>Alasan</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td class="mono faint">{{ dateTime(r.created_at) }}</td>
            <td>
              <span class="row gap-6">
                <AppIcon :name="r.actuator === 'heater' ? 'fire' : 'wind'" :size="13" />
                <strong>{{ r.actuator === 'heater' ? 'Heater' : 'Kipas' }}</strong>
              </span>
            </td>
            <td>
              <span class="badge" :class="r.new_state ? 'badge--live' : ''">
                <span class="dot" />
                {{ r.new_state ? 'Nyala' : 'Mati' }}
              </span>
            </td>
            <td class="mono">{{ num(r.new_duty, 0) }}%</td>
            <td>
              <span class="badge" :class="r.source === 'auto' ? 'badge--busy' : 'badge--info'">
                {{ r.source === 'auto' ? 'Auto' : r.source === 'safety' ? 'Safety' : r.source === 'web' ? 'Web' : r.source }}
              </span>
            </td>
            <td class="muted" style="white-space: normal; max-width: 300px">{{ r.reason }}</td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="6" class="text-center faint" style="padding: 22px">Belum ada aktivitas.</td>
          </tr>
        </tbody>
      </table>

      <table v-else class="table">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Status</th>
            <th>Kelembapan</th>
            <th>Suhu</th>
            <th>Estimasi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td class="mono faint">{{ dateTime(r.at) }}</td>
            <td>
              <span class="badge" :style="{ color: STATUS_TONE[r.status], borderColor: 'currentColor' }">
                <span class="dot" />
                {{ r.status_label || r.status }}
              </span>
            </td>
            <td class="mono">{{ num(r.moisture_pct, 1) }}%</td>
            <td class="mono">{{ num(r.temp_c, 1) }}°C</td>
            <td class="muted">{{ r.eta_text || '--' }}</td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="5" class="text-center faint" style="padding: 22px">Belum ada riwayat rekomendasi.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.act__tabs {
  display: flex;
  gap: 3px;
  background: var(--sunken);
  padding: 3px;
  border-radius: 9px;
  border: 1px solid var(--border);
}

.act__tab {
  padding: 5px 11px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 6px;
  color: var(--text-faint);
  transition: all 0.25s var(--ease);
}

.act__tab:hover {
  color: var(--text-dim);
}

.act__tab.is-on {
  background: var(--fill-2);
  color: var(--text);
}

.gap-6 {
  gap: 6px;
}
</style>
