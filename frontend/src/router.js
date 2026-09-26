import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'dashboard',
    component: () => import('@/views/DashboardView.vue'),
    meta: { title: 'Dashboard', icon: 'gauge' }
  },
  {
    path: '/riwayat',
    name: 'riwayat',
    component: () => import('@/views/HistoryView.vue'),
    meta: { title: 'Riwayat', icon: 'chart' }
  },
  {
    path: '/kontrol',
    name: 'kontrol',
    component: () => import('@/views/ControlView.vue'),
    meta: { title: 'Kontrol', icon: 'switch' }
  },
  {
    path: '/rekomendasi',
    name: 'rekomendasi',
    component: () => import('@/views/RecommendationView.vue'),
    meta: { title: 'Rekomendasi', icon: 'bulb' }
  },
  {
    path: '/pengaturan',
    name: 'pengaturan',
    component: () => import('@/views/SettingsView.vue'),
    meta: { title: 'Pengaturan', icon: 'sliders' }
  },
  {
    path: '/perangkat',
    name: 'perangkat',
    component: () => import('@/views/DeviceView.vue'),
    meta: { title: 'Perangkat & API', icon: 'chip' }
  },
  { path: '/login', redirect: '/' },
  { path: '/:pathMatch(.*)*', redirect: '/' }
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 })
})

router.afterEach((to) => {
  document.title = to.meta.title
    ? `${to.meta.title} · Monitor Suhu & Kelembapan`
    : 'Monitor Suhu & Kelembapan'
})

export default router
