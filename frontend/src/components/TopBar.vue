<script setup>
/** Bilah atas: status perangkat, judul halaman, jam, dan menu pintasan. */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import AppIcon from './AppIcon.vue'
import { device, isOnline, loadState, notify, notifyError } from '@/stores'
import { lastSyncAgo } from '@/stores'
import { ago } from '@/utils/format'

const route = useRoute()
const menuOpen = ref(false)
const now = ref(new Date())
let timer = null

onMounted(() => {
  timer = setInterval(() => (now.value = new Date()), 1000)
})
onBeforeUnmount(() => clearInterval(timer))

const clock = computed(() =>
  now.value.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
)

const dateLabel = computed(() =>
  now.value.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
)

const title = computed(() => route.meta.title || 'Dashboard')

const syncText = computed(() => {
  const s = lastSyncAgo.value
  if (s === null) return 'Menunggu data'
  return s < 4 ? 'Data baru saja' : `Update ${ago(s)}`
})

async function refreshNow() {
  menuOpen.value = false
  try {
    await loadState({ full: false })
    notify('Data diperbarui.', 'success', 1800)
  } catch (e) {
    notifyError(e)
  }
}
</script>

<template>
  <header class="top">
    <div class="top__left">
      <h1 class="top__title">{{ title }}</h1>
      <div class="top__meta">
        <span v-if="device.demo" class="badge badge--warn" title="Data contoh, backend belum tersambung">
          <AppIcon name="alert" :size="11" /> Mode demo
        </span>
        <span class="badge" :class="isOnline ? 'badge--live' : 'badge--danger'">
          <span class="dot" :class="isOnline ? 'dot--pulse' : ''" />
          {{ isOnline ? 'Sensors online' : 'Sensors offline' }}
        </span>
        <span class="badge" :class="device.loading ? 'badge--busy' : ''">
          <AppIcon name="refresh" :size="11" :spin="device.loading" />
          {{ syncText }}
        </span>
        <span v-if="device.reading?.source" class="badge">
          Sumber: {{ device.reading.source }}
        </span>
      </div>
    </div>

    <div class="top__right">
      <div class="top__clock">
        <div class="top__time mono">{{ clock }}</div>
        <div class="top__date">{{ dateLabel }}</div>
      </div>

      <div class="top__menu-wrap">
        <button class="top__user" title="Menu" @click="menuOpen = !menuOpen">
          <AppIcon name="menu" :size="16" />
        </button>
        <Transition name="fade-scale">
          <div v-if="menuOpen" class="top__menu" @click.self="menuOpen = false">
            <button class="top__menu-item" @click="refreshNow">
              <AppIcon name="refresh" :size="15" /> Muat ulang data
            </button>
            <RouterLink to="/kontrol" class="top__menu-item" @click="menuOpen = false">
              <AppIcon name="switch" :size="15" /> Kontrol aktuator
            </RouterLink>
            <RouterLink to="/pengaturan" class="top__menu-item" @click="menuOpen = false">
              <AppIcon name="sliders" :size="15" /> Pengaturan
            </RouterLink>
            <RouterLink to="/perangkat" class="top__menu-item" @click="menuOpen = false">
              <AppIcon name="key" :size="15" /> API Key
            </RouterLink>
          </div>
        </Transition>
      </div>
    </div>
  </header>
</template>

<style scoped>
.top {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  min-height: var(--topbar-h);
  padding: 12px 22px;
  border-bottom: 1px solid var(--border);
  background: var(--popover);
}

.top__title {
  font-size: 20px;
  letter-spacing: -0.03em;
}

.top__meta {
  display: flex;
  gap: 6px;
  margin-top: 6px;
  flex-wrap: wrap;
}

.top__right {
  display: flex;
  align-items: center;
  gap: 14px;
}

.top__clock {
  text-align: right;
  line-height: 1.25;
}

.top__time {
  font-size: 16px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.top__date {
  font-size: 11px;
  color: var(--text-faint);
}

.top__user {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 11px 5px 5px;
  border-radius: var(--radius-full);
  border: 1px solid var(--border);
  background: var(--fill-1);
  color: var(--text-dim);
  transition: all 0.25s var(--ease);
}

.top__user:hover {
  border-color: var(--border-strong);
  background: var(--fill-2);
  color: var(--text);
}

.top__avatar {
  width: 27px;
  height: 27px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: var(--blue);
  color: var(--on-accent);
  font-weight: 800;
  font-size: 12.5px;
}

.top__menu-wrap {
  position: relative;
}

.top__menu {
  position: absolute;
  right: 0;
  top: calc(100% + 8px);
  min-width: 190px;
  padding: 6px;
  border-radius: var(--radius-sm);
  background: var(--popover);
  border: 1px solid var(--border-strong);
  box-shadow: var(--shadow-lg);
  z-index: 40;
}

.top__menu-head {
  padding: 9px 10px 10px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 5px;
}

.top__menu-item {
  display: flex;
  align-items: center;
  gap: 9px;
  width: 100%;
  padding: 8px 10px;
  border-radius: 7px;
  font-size: 13px;
  font-weight: 550;
  color: var(--text-dim);
  text-align: left;
  transition: all 0.2s var(--ease);
}

.top__menu-item:hover {
  background: var(--fill-2);
  color: var(--text);
}

.top__menu-item--danger:hover {
  background: rgba(190, 18, 60, 0.14);
  color: var(--rose);
}

@media (max-width: 760px) {
  .top {
    padding: 10px 14px;
  }
  .top__title {
    font-size: 17px;
  }
  .top__clock,
  .top__meta .badge:nth-child(n + 3) {
    display: none;
  }
}
</style>
