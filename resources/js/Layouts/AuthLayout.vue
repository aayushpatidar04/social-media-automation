<template>
    <div
        class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 flex items-center justify-center px-4">
        <div class="w-full max-w-md">
            <!-- Logo/Header -->
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold text-white mb-2">
                    <Link href="/" class="text-white hover:text-blue-400 transition-colors">
                        Social Media Automation
                    </Link>
                </h1>
                <p class="text-slate-400">{{ title }}</p>
            </div>

            <!-- Card -->
            <div class="bg-slate-800 rounded-lg border border-slate-700 shadow-2xl p-8">
                <slot />
            </div>

            <!-- Footer -->
            <div class="text-center text-sm text-slate-400 mt-8">
                <p>© 2024 Social Media Automation. All rights reserved.</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import Link from '@/Components/Link.vue'

defineProps({
    title: {
        type: String,
        required: true,
    },
})
</script>