<template>
    <AppLayout>
        <div class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 p-6">
            <!-- Header -->
            <div class="mb-12">
                <h1 class="text-4xl font-bold text-white mb-2">Analytics Dashboard</h1>
                <p class="text-slate-400">Real-time social media insights powered by AI</p>
            </div>

            <!-- Loading State -->
            <div v-if="loading" class="text-center py-12">
                <div class="inline-block">
                    <div class="w-8 h-8 border-4 border-slate-600 border-t-blue-500 rounded-full animate-spin"></div>
                </div>
                <p class="text-slate-400 mt-4">Loading analytics...</p>
            </div>

            <div v-else>
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
                    <div class="dashboard-card">
                        <div class="text-3xl mb-2">💬</div>
                        <p class="text-slate-400 text-sm font-medium mb-1">Total Comments</p>
                        <p class="text-2xl font-bold text-white">{{ metrics.summary.total_comments }}</p>
                    </div>

                    <div class="dashboard-card">
                        <div class="text-3xl mb-2">📬</div>
                        <p class="text-slate-400 text-sm font-medium mb-1">New Comments</p>
                        <p class="text-2xl font-bold text-yellow-400">{{ metrics.summary.new_comments }}</p>
                    </div>

                    <div class="dashboard-card">
                        <div class="text-3xl mb-2">✅</div>
                        <p class="text-slate-400 text-sm font-medium mb-1">Response Rate</p>
                        <p class="text-2xl font-bold text-green-400">{{ metrics.summary.response_rate }}%</p>
                    </div>

                    <div class="dashboard-card">
                        <div class="text-3xl mb-2">🎯</div>
                        <p class="text-slate-400 text-sm font-medium mb-1">Total Leads</p>
                        <p class="text-2xl font-bold text-blue-400">{{ metrics.summary.total_leads }}</p>
                    </div>

                    <div class="dashboard-card">
                        <div class="text-3xl mb-2">⭐</div>
                        <p class="text-slate-400 text-sm font-medium mb-1">Qualified Leads</p>
                        <p class="text-2xl font-bold text-emerald-400">{{ metrics.summary.qualified_leads }}</p>
                    </div>

                    <div class="dashboard-card">
                        <div class="text-3xl mb-2">😊</div>
                        <p class="text-slate-400 text-sm font-medium mb-1">Avg Sentiment</p>
                        <p class="text-2xl font-bold text-purple-400">{{ metrics.summary.avg_sentiment_score }}/100</p>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    <!-- Sentiment Chart -->
                    <div class="dashboard-card">
                        <h3 class="text-lg font-bold text-white mb-4">Sentiment Distribution</h3>
                        <div class="space-y-3">
                            <div v-for="(value, key) in metrics.sentiment_distribution" :key="key">
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-slate-300 capitalize">{{ key }}</span>
                                    <span class="text-white font-bold">{{ value.count }}</span>
                                </div>
                                <div class="w-full bg-slate-700 rounded-full h-2">
                                    <div class="h-2 rounded-full transition-all" :class="getSentimentColor(key)"
                                        :style="{ width: getSentimentPercentage(key) + '%' }"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Platform Breakdown -->
                    <div class="dashboard-card">
                        <h3 class="text-lg font-bold text-white mb-4">By Platform</h3>
                        <div class="space-y-3">
                            <div v-for="(data, platform) in metrics.by_platform" :key="platform">
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-slate-300 capitalize">{{ platform }}</span>
                                    <span class="text-white font-bold">{{ data.count }}</span>
                                </div>
                                <div class="w-full bg-slate-700 rounded-full h-2">
                                    <div class="h-2 rounded-full bg-blue-500" :style="{ width: data.percentage + '%' }">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Lead Metrics -->
                    <div class="dashboard-card">
                        <h3 class="text-lg font-bold text-white mb-4">Lead Status</h3>
                        <div class="space-y-3">
                            <div v-for="(count, status) in metrics.lead_metrics.by_status" :key="status">
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-slate-300 capitalize">{{ status }}</span>
                                    <span class="text-white font-bold">{{ count }}</span>
                                </div>
                                <div class="w-full bg-slate-700 rounded-full h-2">
                                    <div class="h-2 rounded-full bg-emerald-500"
                                        :style="{ width: (count / metrics.lead_metrics.total_leads * 100) + '%' }">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Timeline -->
                <div class="dashboard-card mb-6">
                    <h3 class="text-lg font-bold text-white mb-4">Activity (24 Hours)</h3>
                    <div class="flex items-end justify-between h-32 gap-2">
                        <div v-for="(item, idx) in metrics.activity_timeline" :key="idx"
                            class="flex-1 flex flex-col items-center group cursor-pointer">
                            <div class="w-full bg-slate-700 rounded-t hover:bg-blue-600 transition-colors"
                                :style="{ height: (item.count / maxActivityCount * 100) + '%', minHeight: '20px' }">
                            </div>
                            <span class="text-xs text-slate-500 mt-2 text-center">{{ item.time }}</span>
                            <span class="text-xs text-slate-400 opacity-0 group-hover:opacity-100">{{ item.count
                                }}</span>
                        </div>
                    </div>
                </div>

                <!-- Recent Comments -->
                <div class="dashboard-card">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-bold text-white">Recent Comments</h3>
                        <a href="/inbox" class="text-sm text-blue-400 hover:text-blue-300">View All →</a>
                    </div>

                    <div v-if="recentComments.length === 0" class="text-center py-8">
                        <p class="text-slate-400">No comments yet. Connect your first social account to get started!</p>
                        <a href="/settings/social-accounts" class="text-blue-400 hover:text-blue-300 text-sm mt-2">
                            Connect Account
                        </a>
                    </div>

                    <div v-else class="space-y-4">
                        <div v-for="comment in recentComments" :key="comment.id"
                            class="p-4 border border-slate-700 rounded-lg hover:border-slate-600 hover:bg-slate-700/30 transition-all cursor-pointer"
                            @click="$router.push('/inbox')">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <p class="font-medium text-white">{{ comment.author_name }}</p>
                                    <p class="text-xs text-slate-400">{{ comment.socialAccount.platform }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <span class="px-2 py-1 rounded text-xs font-medium"
                                        :class="getSentimentBadgeClass(comment.sentiment)">
                                        {{ comment.sentiment }}
                                    </span>
                                    <span class="px-2 py-1 rounded text-xs font-medium"
                                        :class="getIntentBadgeClass(comment.intent)">
                                        {{ comment.intent }}
                                    </span>
                                </div>
                            </div>
                            <p class="text-slate-300 text-sm mb-3 line-clamp-2">{{ comment.content }}</p>
                            <div class="flex justify-between items-center text-xs text-slate-500">
                                <span>Lead Score: <span class="text-white font-bold">{{ comment.lead_score
                                        }}</span></span>
                                <span>{{ formatDate(comment.commented_at) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import Pusher from 'pusher-js'
import AppLayout from '@/Layouts/AppLayout.vue'


const page = usePage()
const metrics = ref(page.props.metrics)
const loading = ref(false)
let pusher = null

// Calculate max activity for scaling
const maxActivityCount = computed(() => {
    return Math.max(...metrics.value.activity_timeline.map(t => t.count), 1)
})

const recentComments = computed(() => {
    // Get last 5 comments from all platforms
    return []
})

onMounted(() => {
    // Initialize Pusher for real-time updates
    pusher = new Pusher(page.props.pusher_key, {
        cluster: page.props.pusher_cluster,
        forceTLS: true,
    })

    const channel = pusher.subscribe(`analytics.org.${page.props.auth.user.organization_id}`)

    channel.bind('metric.updated', (data) => {
        // Update the specific metric
        if (metrics.value[data.type]) {
            metrics.value[data.type] = data.data
        }
    })
})

onUnmounted(() => {
    if (pusher) {
        pusher.disconnect()
    }
})

// Helper functions
const getSentimentColor = (sentiment) => {
    const colors = {
        positive: 'bg-green-500',
        neutral: 'bg-slate-500',
        negative: 'bg-red-500',
        pending: 'bg-yellow-500'
    }
    return colors[sentiment] || 'bg-slate-500'
}

const getSentimentPercentage = (sentiment) => {
    const total = Object.values(metrics.value.sentiment_distribution)
        .reduce((sum, item) => sum + item.count, 0)
    const count = metrics.value.sentiment_distribution[sentiment]?.count || 0
    return total > 0 ? (count / total * 100) : 0
}

const getSentimentBadgeClass = (sentiment) => {
    const classes = {
        positive: 'bg-green-900 text-green-200',
        neutral: 'bg-slate-700 text-slate-200',
        negative: 'bg-red-900 text-red-200',
        pending: 'bg-yellow-900 text-yellow-200'
    }
    return classes[sentiment] || 'bg-slate-700 text-slate-200'
}

const getIntentBadgeClass = (intent) => {
    const classes = {
        sales: 'bg-blue-900 text-blue-200',
        support: 'bg-purple-900 text-purple-200',
        complaint: 'bg-red-900 text-red-200',
        lead: 'bg-green-900 text-green-200',
        question: 'bg-yellow-900 text-yellow-200',
        general: 'bg-slate-700 text-slate-200'
    }
    return classes[intent] || 'bg-slate-700 text-slate-200'
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString()
}
</script>

<style scoped>
.dashboard-card {
    @apply bg-slate-800 rounded-lg border border-slate-700 p-6 hover:border-slate-600 transition-colors;
}

.line-clamp-2 {
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
</style>