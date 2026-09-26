<script setup>
/** Lingkaran progres (persentase) dengan animasi. */
import { computed } from 'vue'

const props = defineProps({
  value: { type: Number, default: 0 },
  max: { type: Number, default: 100 },
  size: { type: Number, default: 120 },
  stroke: { type: Number, default: 8 },
  color: { type: String, default: 'var(--emerald)' },
  track: { type: String, default: 'var(--chart-track)' },
  decimals: { type: Number, default: 0 }
})

const R = 52
const C = 2 * Math.PI * R

const ratio = computed(() => {
  if (!props.max) return 0
  return Math.min(1, Math.max(0, props.value / props.max))
})

const offset = computed(() => C * (1 - ratio.value))

const label = computed(() =>
  (ratio.value * 100).toLocaleString('id-ID', {
    minimumFractionDigits: props.decimals,
    maximumFractionDigits: props.decimals
  })
)
</script>

<template>
  <div class="ring" :style="{ width: size + 'px', height: size + 'px' }">
    <svg viewBox="0 0 120 120" class="ring__svg">
      <circle cx="60" cy="60" :r="R" fill="none" :stroke="track" :stroke-width="stroke" />
      <circle
        cx="60"
        cy="60"
        :r="R"
        fill="none"
        :stroke="color"
        :stroke-width="stroke"
        stroke-linecap="round"
        :stroke-dasharray="C"
        :stroke-dashoffset="offset"
        class="ring__bar"
        transform="rotate(-90 60 60)"
      />
    </svg>
    <div class="ring__text">
      <span class="ring__value mono">{{ label }}<small>%</small></span>
      <slot />
    </div>
  </div>
</template>

<style scoped>
.ring {
  position: relative;
  display: grid;
  place-items: center;
  flex: none;
}

.ring__svg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.ring__bar {
  transition: stroke-dashoffset 1s var(--ease-out), stroke 0.5s var(--ease);
  filter: drop-shadow(0 0 4px color-mix(in srgb, currentColor 45%, transparent));
}

.ring__text {
  position: relative;
  text-align: center;
  line-height: 1.15;
}

.ring__value {
  font-size: 22px;
  font-weight: 700;
  letter-spacing: -0.03em;
}

.ring__value small {
  font-size: 11px;
  opacity: 0.6;
  font-weight: 600;
}
</style>
