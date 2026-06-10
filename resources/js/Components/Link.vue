<template>
    <InertiaLink :href="href" :as="as" class="text-blue-400 hover:text-blue-300 transition-colors">
        <slot />
    </InertiaLink>
</template>

<script setup>
import { Link as InertiaLink } from '@inertiajs/vue3'

defineProps({
    href: {
        type: String,
        required: true,
    },
    as: {
        type: String,
        default: 'a',
    },
})
</script>