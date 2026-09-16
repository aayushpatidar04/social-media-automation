<template>
    <component
        :is="as"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-all duration-200',
            'disabled:opacity-60 disabled:cursor-not-allowed',
            baseClasses,
            loading ? 'cursor-wait' : '',
        ]"
        @click="$emit('click', $event)"
        v-bind="as === 'a' ? { href } : {}"
    >
        <!-- Loading Spinner -->
        <svg
            v-if="loading"
            class="animate-spin h-4 w-4 flex-shrink-0"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
            ></circle>
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>

        <!-- Slot content -->
        <slot v-if="!loading" name="default" />
        <span v-if="loading" class="flex items-center gap-2">
            <slot v-if="$slots.loading" name="loading" />
            <span v-else>{{ loadingText || "Processing..." }}</span>
        </span>
        <slot v-if="loading" name="loading" />
    </component>
</template>

<script setup>
defineProps({
    loading: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    loadingText: {
        type: String,
        default: "Processing...",
    },
    baseClasses: {
        type: String,
        default: "bg-blue-600 hover:bg-blue-700 text-white",
    },
    as: {
        type: String,
        default: "button",
    },
    href: {
        type: String,
        default: "#",
    },
});

defineEmits(["click"]);
</script>
