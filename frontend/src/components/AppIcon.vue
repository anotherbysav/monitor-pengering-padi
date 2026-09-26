<script setup>
/** Ikon SVG inline based on nama dari utils/format. */
import { computed } from 'vue'
import { ICONS } from '@/utils/format'

const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 18 },
  stroke: { type: [Number, String], default: 1.7 },
  spin: { type: Boolean, default: false }
})

const d = computed(() => ICONS[props.name] || ICONS.info)
</script>

<template>
  <svg
    class="icon"
    :class="{ 'icon--spin': spin }"
    viewBox="0 0 24 24"
    :width="size"
    :height="size"
    fill="none"
    stroke="currentColor"
    :stroke-width="stroke"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <path :d="d" />
  </svg>
</template>

<style scoped>
.icon {
  flex: none;
  display: block;
}

.icon--spin {
  animation: iconSpin 1.1s linear infinite;
}

@keyframes iconSpin {
  to {
    transform: rotate(360deg);
  }
}
</style>
