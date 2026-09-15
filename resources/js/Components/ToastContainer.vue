<script setup>
import { ref } from "vue";
import { toast } from "@/composables/useToast";

const toasts = ref([]);

toast.onToast((toastData) => {
    toasts.value.push(toastData);
    setTimeout(() => {
        const idx = toasts.value.findIndex((t) => t === toastData);
        if (idx !== -1) toasts.value.splice(idx, 1);
    }, toastData.duration || 4000);
});

const removeToast = (toastData) => {
    const idx = toasts.value.findIndex((t) => t === toastData);
    if (idx !== -1) toasts.value.splice(idx, 1);
};

const toastClasses = {
    success: "border-green-500 bg-green-900/90 text-green-100",
    error: "border-red-500 bg-red-900/90 text-red-100",
    info: "border-blue-500 bg-blue-900/90 text-blue-100",
};

const toastIcon = {
    success: "✓",
    error: "✕",
    info: "ℹ",
};
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed top-4 right-4 z-[9999] flex flex-col gap-3 max-w-sm w-full pointer-events-none"
        >
            <TransitionGroup name="toast">
                <div
                    v-for="(t, i) in toasts"
                    :key="i"
                    :class="[
                        'pointer-events-auto flex items-start gap-3 px-4 py-3 rounded-lg border shadow-xl backdrop-blur-sm',
                        toastClasses[t.type],
                    ]"
                >
                    <span class="text-lg font-bold leading-none mt-0.5">{{
                        toastIcon[t.type]
                    }}</span>
                    <p class="text-sm leading-snug flex-1">{{ t.message }}</p>
                    <button
                        @click="removeToast(t)"
                        class="text-white/60 hover:text-white leading-none mt-0.5 font-bold text-lg"
                    >
                        &times;
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all 0.3s ease;
}
.toast-enter-from {
    opacity: 0;
    transform: translateX(100%);
}
.toast-leave-to {
    opacity: 0;
    transform: translateX(100%);
}
</style>
