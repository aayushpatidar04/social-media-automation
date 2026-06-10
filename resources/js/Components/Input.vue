<template>
    <div>
        <label v-if="label" :for="id" class="block text-sm font-medium text-slate-300 mb-2">
            {{ label }}
        </label>
        <input :id="id" :value="modelValue" :type="type" :placeholder="placeholder"
            @input="$emit('update:modelValue', $event.target.value)"
            class="w-full px-4 py-2 bg-slate-800 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
        <p v-if="error" class="text-red-400 text-sm mt-1">
            {{ error }}
        </p>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    placeholder: {
        type: String,
        default: '',
    },
    error: {
        type: String,
        default: '',
    },
    id: {
        type: String,
        default: () => Math.random().toString(36).substr(2, 9),
    },
})

defineEmits(['update:modelValue'])
</script>