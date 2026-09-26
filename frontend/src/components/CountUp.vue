<script setup>
/** Angka yang menghitung naik dengan animasi. */
import { ref, watch, onMounted } from 'vue'

const props = defineProps({
  value: { type: Number, default: 0 },
  decimals: { type: Number, default: 1 },
  duration: { type: Number, default: 700 }
})

const shown = ref(props.value)
let raf = null

function animate(from, to, dur) {
  cancelAnimationFrame(raf)
  const t0 = performance.now()
  const step = (now) => {
    const p = Math.min(1, (now - t0) / dur)
    const eased = 1 - Math.pow(1 - p, 3)
    shown.value = from + (to - from) * eased
    if (p < 1) raf = requestAnimationFrame(step)
  }
  raf = requestAnimationFrame(step)
}

onMounted(() => {
  animate(props.value, props.value, 1)
})

watch(
  () => props.value,
  (v) => {
    if (v === null || v === undefined || Number.isNaN(Number(v))) return
    animate(shown.value, Number(v), props.duration)
  }
)

const display = () =>
  Number(shown.value).toLocaleString('id-ID', {
    minimumFractionDigits: props.decimals,
    maximumFractionDigits: props.decimals
  })
</script>

<template>
  <span class="countup mono">{{ display() }}</span>
</template>

<style scoped>
.countup {
  font-variant-numeric: tabular-nums;
}
</style>
