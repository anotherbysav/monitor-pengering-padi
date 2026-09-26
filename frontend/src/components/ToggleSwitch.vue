<script setup>
/** Saklar on/off dengan animasi dan efek ripple. */
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  color: { type: String, default: 'var(--emerald)' },
  label: { type: String, default: '' }
})
const emit = defineEmits(['update:modelValue'])

function toggle() {
  if (props.disabled) return
  emit('update:modelValue', !props.modelValue)
}
</script>

<template>
  <button
    type="button"
    class="switch"
    :class="{ 'switch--on': modelValue, 'switch--off': !modelValue }"
    :style="{ '--sw-color': color }"
    :disabled="disabled"
    :aria-pressed="modelValue"
    :aria-label="label"
    @click="toggle"
  >
    <span class="switch__track">
      <span class="switch__glow" />
      <span class="switch__thumb">
        <span class="switch__shine" />
      </span>
    </span>
    <span v-if="label" class="switch__label">{{ label }}</span>
  </button>
</template>

<style scoped>
.switch {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  padding: 0;
  transition: transform 0.2s var(--ease-spring);
}

.switch:active:not(:disabled) .switch__track {
  transform: scale(0.96);
}

.switch:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.switch__track {
  position: relative;
  width: 50px;
  height: 27px;
  border-radius: 999px;
  background: var(--fill-3);
  border: 1px solid var(--border);
  transition: background 0.4s var(--ease), border-color 0.4s var(--ease);
  flex: none;
}

.switch--on .switch__track {
  background: color-mix(in srgb, var(--sw-color) 32%, transparent);
  border-color: color-mix(in srgb, var(--sw-color) 55%, transparent);
  box-shadow: 0 0 18px -4px var(--sw-color);
}

.switch__glow {
  position: absolute;
  inset: 0;
  border-radius: 999px;
  background: var(--sw-color);
  opacity: 0;
  transition: opacity 0.4s var(--ease);
  filter: blur(9px);
}

.switch--on .switch__glow {
  opacity: 0.45;
  animation: glowBreathe 2.6s var(--ease) infinite;
}

@keyframes glowBreathe {
  0%, 100% { opacity: 0.32; }
  50% { opacity: 0.55; }
}

.switch__thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 21px;
  height: 21px;
  border-radius: 50%;
  background: var(--text);
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.45);
  transition: transform 0.42s var(--ease-spring), background 0.4s var(--ease);
  display: grid;
  place-items: center;
}

.switch--on .switch__thumb {
  transform: translateX(23px);
  background: var(--sw-color);
}

.switch__shine {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--sheen);
  filter: blur(1px);
}

.switch__label {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text-dim);
  transition: color 0.3s var(--ease);
}

.switch--on .switch__label {
  color: var(--text);
}
</style>
