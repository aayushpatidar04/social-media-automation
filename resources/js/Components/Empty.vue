<!-- resources/js/Components/Empty.vue -->
<template>
    <div class="text-center py-12">
        <p class="text-slate-400 text-lg mb-4">{{ title }}</p>
        <p class="text-slate-500 text-sm">{{ message }}</p>
    </div>
</template>

<script setup>
defineProps({
    title: String,
    message: String,
})
</script>