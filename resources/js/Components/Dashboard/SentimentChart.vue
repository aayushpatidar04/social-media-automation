<script setup>
import { computed } from 'vue'
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts'

defineProps({
    data: {
        type: Object,
        required: true
    }
})

const chartData = computed(() => [
    { name: 'Positive', count: data.value.positive.count },
    { name: 'Neutral', count: data.value.neutral.count },
    { name: 'Negative', count: data.value.negative.count },
    { name: 'Pending', count: data.value.pending.count }
])
</script>

<template>
    <div class="dashboard-card h-64">
        <h3 class="text-lg font-bold text-white mb-4">Sentiment Distribution</h3>
        <ResponsiveContainer width="100%" height="100%">
            <BarChart :data="chartData">
                <CartesianGrid strokeDasharray="3 3" stroke="#334155" />
                <XAxis dataKey="name" stroke="#94a3b8" />
                <YAxis stroke="#94a3b8" />
                <Tooltip contentStyle={{ backgroundColor: '#1e293b' , border: '1px solid #475569' }} cursor={{
                    fill: 'rgba(59, 130, 246, 0.1)' }} />
                <Bar dataKey="count" fill="#3b82f6" />
            </BarChart>
        </ResponsiveContainer>
    </div>
</template>