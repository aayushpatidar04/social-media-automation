<script setup>
import { computed } from 'vue'

defineProps({
    metrics: {
        type: Object,
        required: true
    }
})

const metricsList = computed(() => [
    {
        label: 'Total Comments',
        value: metrics.value.total_comments,
        icon: '💬',
        color: 'blue'
    },
    {
        label: 'New Comments',
        value: metrics.value.new_comments,
        icon: '📬',
        color: 'yellow',
        trend: '+12%'
    },
    {
        label: 'Response Rate',
        value: `${metrics.value.response_rate}%`,
        icon: '✅',
        color: 'green'
    },
    {
        label: 'Total Leads',
        value: metrics.value.total_leads,
        icon: '🎯',
        color: 'purple',
        trend: '+5'
    },
    {
        label: 'Qualified Leads',
        value: metrics.value.qualified_leads,
        icon: '⭐',
        color: 'emerald'
    },
    {
        label: 'Avg Sentiment',
        value: `${metrics.value.avg_sentiment_score}/100`,
        icon: '😊',
        color: 'indigo'
    }
])
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
        <div v-for="metric in metricsList" :key="metric.label" class="dashboard-card">
            <div class="text-3xl mb-2">{{ metric.icon }}</div>
            <div class="text-slate-400 text-sm font-medium mb-1">{{ metric.label }}</div>
            <div class="text-2xl font-bold text-white mb-2">{{ metric.value }}</div>
            <div v-if="metric.trend" class="text-xs text-green-400">{{ metric.trend }}</div>
        </div>
    </div>
</template>