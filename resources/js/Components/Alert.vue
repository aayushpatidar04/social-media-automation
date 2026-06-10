<!-- resources/js/Components/Alert.vue -->
<template>
    <div :class="['px-4 py-3 rounded-lg border', variantClasses]">
        <div class="flex items-start gap-3">
            <span class="text-xl">{{ icon }}</span>
            <div>
                <p v-if="title" class="font-bold">{{ title }}</p>
                <p>{{ message }}</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    variant: {
        type: String,
        default: 'info',
        validator: (v) => ['success', 'warning', 'error', 'info'].includes(v),
    },
    title: String,
    message: String,
})

const iconMap = {
    success: '✓',
    warning: '⚠',
    error: '✕',
    info: 'ℹ',
}

const icon = computed(() => iconMap[props.variant])

const variantClasses = computed(() => {
    const classes = {
        success: 'bg-green-900/30 border-green-700 text-green-200',
        warning: 'bg-yellow-900/30 border-yellow-700 text-yellow-200',
        error: 'bg-red-900/30 border-red-700 text-red-200',
        info: 'bg-blue-900/30 border-blue-700 text-blue-200',
    }
    return classes[props.variant]
})
</script>