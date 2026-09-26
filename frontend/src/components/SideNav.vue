<script setup>
/** Navigasi samping dengan state aktif beranimasi. */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppIcon from './AppIcon.vue'
import { device, isOnline } from '@/stores'

const route = useRoute()

const items = computed(() => [
  { to: '/', label: 'Dashboard', icon: 'gauge' },
  { to: '/riwayat', label: 'Riwayat', icon: 'chart' },
  { to: '/kontrol', label: 'Kontrol', icon: 'switch' },
  { to: '/rekomendasi', label: 'Rekomendasi', icon: 'bulb' },
  { to: '/pengaturan', label: 'Pengaturan', icon: 'sliders' },
  { to: '/perangkat', label: 'Perangkat & API', icon: 'chip' }
])

const activePath = computed(() => route.path)
</script>

<template>
  <aside class="nav">
    <div class="nav__brand">
      <span class="nav__logo">
        <AppIcon name="seedling" :size="20" />
      </span>
      <div>
        <div class="nav__title">Pengering Padi</div>
        <div class="nav__sub">Suhu &amp; Kelembapan</div>
      </div>
    </div>

    <nav class="nav__list">
      <RouterLink
        v-for="it in items"
        :key="it.to"
        :to="it.to"
        class="nav__item"
        :class="{ 'is-on': activePath === it.to }"
      >
        <span class="nav__bullet" />
        <AppIcon :name="it.icon" :size="17" />
        <span>{{ it.label }}</span>
      </RouterLink>
    </nav>

    <div class="nav__foot">
      <div class="nav__status" :class="isOnline ? 'is-on' : 'is-off'">
        <span class="dot" :class="isOnline ? 'dot--pulse' : ''" />
        <div>
          <div class="nav__status-title">{{ isOnline ? 'Perangkat online' : 'Perangkat offline' }}</div>
          <div class="nav__status-sub mono">{{ device.info?.code || '—' }}</div>
        </div>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.nav {
  width: var(--sidebar-w);
  flex: none;
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding: 18px 14px;
  border-right: 1px solid var(--border);
  background: var(--surface);
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
}

.nav__brand {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 4px 6px 0;
}

.nav__logo {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  display: grid;
  place-items: center;
  background: linear-gradient(140deg, #1e3a8a, #1d4ed8);
  color: var(--on-accent);
  box-shadow: 0 1px 2px rgba(29, 78, 216, 0.3);
  flex: none;
}

.nav__title {
  font-size: 14.5px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.nav__sub {
  font-size: 11px;
  color: var(--text-faint);
}

.nav__list {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
}

.nav__item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border-radius: 10px;
  font-size: 13.5px;
  font-weight: 550;
  color: var(--text-dim);
  transition: all 0.28s var(--ease);
  overflow: hidden;
}

.nav__item:hover {
  color: var(--text);
  background: var(--fill-1);
}

.nav__item.is-on {
  color: var(--text);
  background: linear-gradient(90deg, rgba(3, 105, 161, 0.16), rgba(3, 105, 161, 0.03));
}

.nav__bullet {
  position: absolute;
  left: 0;
  top: 50%;
  width: 3px;
  height: 0;
  border-radius: 0 3px 3px 0;
  background: var(--sky);
  transform: translateY(-50%);
  transition: height 0.32s var(--ease-spring);
}

.nav__item.is-on .nav__bullet {
  height: 60%;
  box-shadow: 0 0 10px var(--sky);
}

.nav__foot {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.nav__status,
.nav__user {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 10px 11px;
  border-radius: 10px;
  background: var(--sunken);
  border: 1px solid var(--border);
  transition: border-color 0.3s var(--ease);
}

.nav__status.is-on {
  border-color: rgba(5, 150, 105, 0.3);
  color: var(--emerald-soft);
}

.nav__status.is-off {
  border-color: rgba(100, 116, 139, 0.3);
  color: var(--slate);
}

.nav__status-title {
  font-size: 12.5px;
  font-weight: 640;
  color: var(--text);
}

.nav__status-sub {
  font-size: 10.5px;
  color: var(--text-faint);
}

.nav__avatar {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  background: rgba(3, 105, 161, 0.14);
  color: var(--sky);
  flex: none;
}

@media (max-width: 900px) {
  .nav {
    width: 100%;
    height: auto;
    position: static;
    flex-direction: row;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-right: none;
    border-bottom: 1px solid var(--border);
    overflow-x: auto;
  }
  .nav__list {
    flex-direction: row;
    flex: 1;
    gap: 4px;
  }
  .nav__item span:not(.nav__bullet) {
    display: none;
  }
  .nav__item {
    padding: 9px 11px;
  }
  .nav__foot {
    display: none;
  }
}
</style>
