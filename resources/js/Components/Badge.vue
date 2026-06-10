<template>
    <span :class="['px-3 py-1 rounded-full text-sm font-medium', variantClasses]">
        <slot />
    </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (value) =>
            ['primary', 'success', 'warning', 'danger', 'info'].includes(value),
    },
})

const variantClasses = computed(() => {
    const variants = {
        primary: 'bg-blue-900 text-blue-200',
        success: 'bg-green-900 text-green-200',
        warning: 'bg-yellow-900 text-yellow-200',
        danger: 'bg-red-900 text-red-200',
        info: 'bg-slate-700 text-slate-200',
    }
    return variants[props.variant]
})
</script>