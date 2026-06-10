<template>
    <AppLayout>
        <div>
            <h1 class="text-3xl font-bold text-white mb-8">Analytics</h1>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <p class="text-slate-400 text-sm mb-2">Total Comments</p>
                    <p class="text-3xl font-bold text-white">{{ summary.total_comments }}</p>
                </div>
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <p class="text-slate-400 text-sm mb-2">Response Rate</p>
                    <p class="text-3xl font-bold text-green-400">{{ summary.response_rate }}%</p>
                </div>
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <p class="text-slate-400 text-sm mb-2">Total Leads</p>
                    <p class="text-3xl font-bold text-blue-400">{{ summary.total_leads }}</p>
                </div>
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <p class="text-slate-400 text-sm mb-2">Avg Sentiment</p>
                    <p class="text-3xl font-bold text-purple-400">{{ summary.avg_sentiment_score }}/100</p>
                </div>
            </div>

            <!-- Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <h3 class="text-lg font-bold text-white mb-4">Sentiment Distribution</h3>
                    <div class="space-y-2">
                        <div v-for="(item, key) in sentiment" :key="key">
                            <div class="flex justify-between text-sm mb-1">
                                <span class="capitalize text-slate-300">{{ key }}</span>
                                <span class="text-white">{{ item.count }}</span>
                            </div>
                            <div class="w-full h-2 bg-slate-700 rounded-full">
                                <div class="h-full bg-blue-500 rounded-full" :style="{ width: item.percentage + '%' }">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <h3 class="text-lg font-bold text-white mb-4">By Platform</h3>
                    <div class="space-y-2">
                        <div v-for="(item, platform) in byPlatform" :key="platform">
                            <div class="flex justify-between text-sm mb-1">
                                <span class="capitalize text-slate-300">{{ platform }}</span>
                                <span class="text-white">{{ item.count }}</span>
                            </div>
                            <div class="w-full h-2 bg-slate-700 rounded-full">
                                <div class="h-full bg-green-500 rounded-full" :style="{ width: item.percentage + '%' }">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { ref } from 'vue'

const summary = ref({
    total_comments: 0,
    response_rate: 0,
    total_leads: 0,
    avg_sentiment_score: 0,
})

const sentiment = ref({
    positive: { count: 0, percentage: 0 },
    neutral: { count: 0, percentage: 0 },
    negative: { count: 0, percentage: 0 },
})

const byPlatform = ref({
    facebook: { count: 0, percentage: 0 },
    instagram: { count: 0, percentage: 0 },
})
</script>