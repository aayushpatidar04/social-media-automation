<template>
    <button :type="type" :disabled="disabled" :class="[
        'px-4 py-2 rounded-lg font-medium transition-colors',
        variantClasses,
        disabled && 'opacity-50 cursor-not-allowed',
    ]">
        <slot />
    </button>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'secondary', 'danger', 'outline'].includes(value),
    },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
})

const variantClasses = computed(() => {
    const variants = {
        primary: 'bg-blue-600 hover:bg-blue-700 text-white',
        secondary: 'bg-slate-700 hover:bg-slate-600 text-white',
        danger: 'bg-red-600 hover:bg-red-700 text-white',
        outline: 'border border-slate-600 hover:border-slate-500 text-slate-300',
    }
    return variants[props.variant]
})
</script>