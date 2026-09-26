<script setup>
/** Panel alert: aktif (dengan tombolsoid) dan riwayat. */
import { ref } from 'vue'
import AppIcon from './AppIcon.vue'
import { dateTime } from '@/utils/format'

const props = defineProps({
  alerts: { type: Array, default: () => [] }
})

const emit = defineEmits(['ack', 'clear'])
const tab = ref('active')

const active = () => props.alerts.filter((a) => !a.acknowledged_at)
const history = () => props.alerts.filter((a) => a.acknowledged_at)

const iconFor = (type) =>
  ({ danger: 'alert', error: 'alert', warning: 'alert', info: 'info' })[type] || 'info'

const toneFor = (type) =>
  type === 'danger' || type === 'error'
    ? 'var(--rose)'
    : type === 'warning'
      ? 'var(--amber)'
      : 'var(--sky)'
</script>

<template>
  <div class="card al">
    <div class="card__head">
      <div>
        <div class="card__title">
          <AppIcon name="alert" :size="16" />
          Notifikasi & Peringatan
        </div>
        <div class="card__subtitle">Peringatan otomatis dari sistem pengering</div>
      </div>
      <span v-if="active().length" class="badge badge--danger">
        <span class="dot dot--pulse" /> {{ active().length }} aktif
      </span>
      <span v-else class="badge badge--live"><AppIcon name="check" :size="12" /> Semua normal</span>
    </div>

    <div class="al__tabs">
      <button class="al__tab" :class="{ 'is-on': tab === 'active' }" @click="tab = 'active'">
        Aktif <span class="al__count">{{ active().length }}</span>
      </button>
      <button class="al__tab" :class="{ 'is-on': tab === 'history' }" @click="tab = 'history'">
        Riwayat <span class="al__count">{{ history().length }}</span>
      </button>
      <button
        v-if="history().length && tab === 'history'"
        class="btn btn--sm btn--ghost"
        style="margin-left: auto"
        @click="emit('clear')"
      >
        <AppIcon name="trash" :size="13" /> Bersihkan
      </button>
    </div>

    <TransitionGroup name="list" tag="div" class="al__list">
      <div
        v-for="a in (tab === 'active' ? active() : history())"
        :key="a.id"
        class="al__item"
        :class="{ 'is-ack': a.acknowledged_at }"
        :style="{ '--c': toneFor(a.type) }"
      >
        <span class="al__icon"><AppIcon :name="iconFor(a.type)" :size="15" /></span>
        <div class="al__body">
          <div class="al__msg">{{ a.message }}</div>
          <div class="al__meta">
            <span class="al__level">{{ a.type }}</span>
            <span>{{ dateTime(a.created_at) }}</span>
            <span v-if="a.acknowledged_at">• ditutup</span>
          </div>
        </div>
        <button
          v-if="!a.acknowledged_at"
          class="btn btn--sm"
          :disabled="a.ack_pending"
          @click="emit('ack', a.id)"
        >
          Tutup
        </button>
        <AppIcon v-else name="check" :size="15" class="al__done" />
      </div>
    </TransitionGroup>

    <p v-if="!(tab === 'active' ? active() : history()).length" class="al__empty">
      <AppIcon name="check" :size="22" />
      <span>{{ tab === 'active' ? 'Tidak ada peringatan aktif.' : 'Belum ada riwayat peringatan.' }}</span>
    </p>
  </div>
</template>

<style scoped>
.al__tabs {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 12px;
}

.al__tab {
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-faint);
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: color 0.25s var(--ease), border-color 0.25s var(--ease);
}

.al__tab:hover {
  color: var(--text-dim);
}

.al__tab.is-on {
  color: var(--text);
  border-color: var(--sky);
}

.al__count {
  font-size: 10.5px;
  padding: 1px 6px;
  border-radius: 999px;
  background: var(--fill-2);
  font-family: var(--mono);
}

.al__list {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 320px;
  overflow-y: auto;
}

.al__item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 11px 12px;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, var(--c) 8%, var(--sunken));
  border: 1px solid color-mix(in srgb, var(--c) 25%, transparent);
  transition: all 0.3s var(--ease);
}

.al__item:hover {
  border-color: color-mix(in srgb, var(--c) 45%, transparent);
  transform: translateX(2px);
}

.al__item.is-ack {
  opacity: 0.55;
  filter: saturate(0.6);
}

.al__icon {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--c) 18%, transparent);
  color: var(--c);
  flex: none;
}

.al__body {
  flex: 1;
  min-width: 0;
}

.al__msg {
  font-size: 13px;
  font-weight: 550;
  line-height: 1.45;
}

.al__meta {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 11px;
  color: var(--text-faint);
  margin-top: 3px;
  flex-wrap: wrap;
}

.al__level {
  text-transform: uppercase;
  font-weight: 700;
  letter-spacing: 0.05em;
  color: var(--c);
  font-size: 9.5px;
}

.al__done {
  color: var(--emerald);
  margin-top: 5px;
}

.al__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 26px 0;
  color: var(--text-faint);
  font-size: 13px;
}
</style>
