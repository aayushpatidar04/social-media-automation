<template>
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="modelValue" class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
                <div class="bg-slate-800 rounded-lg border border-slate-700 shadow-2xl max-w-md w-full">
                    <!-- Header -->
                    <div class="flex items-center justify-between p-6 border-b border-slate-700">
                        <h2 class="text-xl font-bold text-white">{{ title }}</h2>
                        <button @click="$emit('update:modelValue', false)" class="text-slate-400 hover:text-slate-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Content -->
                    <div class="p-6">
                        <slot />
                    </div>

                    <!-- Footer -->
                    <div v-if="$slots.footer" class="flex gap-3 p-6 border-t border-slate-700">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
defineProps({
    modelValue: {
        type: Boolean,
        required: true,
    },
    title: {
        type: String,
        default: '',
    },
})

defineEmits(['update:modelValue'])
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
</style>