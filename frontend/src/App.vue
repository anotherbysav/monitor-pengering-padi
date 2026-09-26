<script setup>
/**
 * Kerangka aplikasi: sidebar + topbar + router view + toast.
 * Mengatur siklus hidup polling data.
 *
 * Tidak ada login: aplikasi langsung memuat dashboard.
 */
import { onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import SideNav from '@/components/SideNav.vue'
import TopBar from '@/components/TopBar.vue'
import ToastStack from '@/components/ToastStack.vue'
import { loadState, startPolling, stopPolling } from '@/stores'

const route = useRoute()

onMounted(async () => {
  await loadState()
  startPolling()
})

onBeforeUnmount(() => stopPolling())
</script>

<template>
  <div class="app">
    <SideNav />
    <div class="app__main">
      <TopBar />
      <main class="app__content">
        <RouterView v-slot="{ Component }">
          <Transition name="slide-up" mode="out-in">
            <component :is="Component" :key="route.path" />
          </Transition>
        </RouterView>
      </main>
    </div>

    <ToastStack />
  </div>
</template>

<style scoped>
.app {
  display: flex;
  min-height: 100vh;
  position: relative;
}

.app__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.app__content {
  flex: 1;
  padding: 20px 22px 40px;
  max-width: 1560px;
  width: 100%;
}

@media (max-width: 900px) {
  .app {
    flex-direction: column;
  }
  .app__content {
    padding: 14px 14px 32px;
  }
}
</style>
