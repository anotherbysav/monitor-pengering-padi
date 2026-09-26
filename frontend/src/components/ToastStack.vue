<script setup>
/** Tumpukan toast dengan animasi masuk/keluar. */
import AppIcon from './AppIcon.vue'
import { toasts, dismissToast } from '@/stores'

const ICON = {
  success: 'check',
  error: 'alert',
  warning: 'alert',
  info: 'info'
}
</script>

<template>
  <div class="toasts">
    <TransitionGroup name="toast">
      <div v-for="t in toasts.items" :key="t.id" class="toast" :class="`toast--${t.type}`">
        <span class="toast__icon">
          <AppIcon :name="ICON[t.type] || 'info'" :size="15" />
        </span>
        <span class="toast__msg">{{ t.message }}</span>
        <button class="toast__close" aria-label="Tutup" @click="dismissToast(t.id)">
          <AppIcon name="x" :size="13" />
        </button>
        <span class="toast__timer" />
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toasts {
  position: fixed;
  right: 18px;
  bottom: 18px;
  z-index: 90;
  display: flex;
  flex-direction: column;
  gap: 9px;
  max-width: min(380px, calc(100vw - 36px));
  pointer-events: none;
}

.toast {
  pointer-events: auto;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 13px;
  border-radius: var(--radius-sm);
  background: var(--popover);
  border: 1px solid var(--border-strong);
  box-shadow: var(--shadow-lg);
  font-size: 13px;
  line-height: 1.45;
  position: relative;
  overflow: hidden;
}

.toast__icon {
  width: 24px;
  height: 24px;
  border-radius: 7px;
  display: grid;
  place-items: center;
  flex: none;
  background: var(--fill-2);
  color: var(--text-dim);
}

.toast--success { border-color: rgba(5, 150, 105, 0.4); }
.toast--success .toast__icon { background: rgba(5, 150, 105, 0.18); color: var(--emerald-soft); }

.toast--error { border-color: rgba(190, 18, 60, 0.45); }
.toast--error .toast__icon { background: rgba(190, 18, 60, 0.18); color: var(--rose); }

.toast--warning { border-color: rgba(180, 83, 9, 0.45); }
.toast--warning .toast__icon { background: rgba(180, 83, 9, 0.18); color: var(--amber); }

.toast--info { border-color: rgba(3, 105, 161, 0.4); }
.toast--info .toast__icon { background: rgba(3, 105, 161, 0.18); color: var(--sky); }

.toast__msg {
  flex: 1;
  min-width: 0;
  padding-right: 4px;
}

.toast__close {
  color: var(--text-faint);
  padding: 2px;
  border-radius: 5px;
  transition: color 0.2s, background 0.2s;
  flex: none;
}

.toast__close:hover {
  color: var(--text);
  background: var(--fill-2);
}

.toast__timer {
  position: absolute;
  left: 0;
  bottom: 0;
  height: 2px;
  background: currentColor;
  opacity: 0.5;
  animation: toastTimer 4.2s linear forwards;
  color: var(--sky);
}

.toast--success .toast__timer { color: var(--emerald); }
.toast--error .toast__timer { color: var(--rose); }
.toast--warning .toast__timer { color: var(--amber); }

@keyframes toastTimer {
  from { width: 100%; }
  to { width: 0%; }
}

.toast-enter-active {
  transition: all 0.42s var(--ease-spring);
}
.toast-leave-active {
  transition: all 0.28s var(--ease);
  position: absolute;
  right: 0;
  width: 100%;
}
.toast-enter-from {
  opacity: 0;
  transform: translateX(60px) scale(0.9);
}
.toast-leave-to {
  opacity: 0;
  transform: translateX(60px) scale(0.92);
}
.toast-move {
  transition: transform 0.35s var(--ease-out);
}

@media (max-width: 560px) {
  .toasts {
    right: 12px;
    left: 12px;
    bottom: 12px;
    max-width: none;
  }
}
</style>
