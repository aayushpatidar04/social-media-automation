<template>
  <AppLayout>
    <div class="min-h-screen bg-gradient-to-br from-slate-900 to-slate-800">
      <div class="max-w-7xl mx-auto p-6">
        <!-- Header -->
        <div class="mb-8">
          <h1 class="text-4xl font-bold text-white mb-2">Social Inbox</h1>
          <p class="text-slate-400">{{ totalComments }} total comments</p>
        </div>
  
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
          <!-- Filters Sidebar -->
          <div class="lg:col-span-1">
            <div class="dashboard-card sticky top-6">
              <h3 class="text-lg font-bold text-white mb-6">Filters</h3>
  
              <!-- Search -->
              <div class="mb-6">
                <label class="block text-sm font-medium text-slate-300 mb-2">Search</label>
                <input v-model="filters.search" type="text" placeholder="Search comments..."
                  class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white placeholder-slate-400 focus:outline-none focus:border-blue-500"
                  @input="applyFilters" />
              </div>
  
              <!-- Status Filter -->
              <div class="mb-6">
                <label class="block text-sm font-medium text-slate-300 mb-2">Status</label>
                <select v-model="filters.status"
                  class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white focus:outline-none focus:border-blue-500"
                  @change="applyFilters">
                  <option value="">All Status</option>
                  <option value="new">New</option>
                  <option value="reviewed">Reviewed</option>
                  <option value="replied">Replied</option>
                  <option value="dismissed">Dismissed</option>
                </select>
              </div>
  
              <!-- Sentiment Filter -->
              <div class="mb-6">
                <label class="block text-sm font-medium text-slate-300 mb-2">Sentiment</label>
                <select v-model="filters.sentiment"
                  class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white focus:outline-none focus:border-blue-500"
                  @change="applyFilters">
                  <option value="">All Sentiment</option>
                  <option value="positive">Positive</option>
                  <option value="neutral">Neutral</option>
                  <option value="negative">Negative</option>
                  <option value="pending">Pending</option>
                </select>
              </div>
  
              <!-- Intent Filter -->
              <div class="mb-6">
                <label class="block text-sm font-medium text-slate-300 mb-2">Intent</label>
                <select v-model="filters.intent"
                  class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white focus:outline-none focus:border-blue-500"
                  @change="applyFilters">
                  <option value="">All Intent</option>
                  <option value="sales">Sales</option>
                  <option value="support">Support</option>
                  <option value="complaint">Complaint</option>
                  <option value="lead">Lead</option>
                  <option value="question">Question</option>
                </select>
              </div>
  
              <!-- Platform Filter -->
              <div class="mb-6">
                <label class="block text-sm font-medium text-slate-300 mb-2">Platform</label>
                <select v-model="filters.platform"
                  class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white focus:outline-none focus:border-blue-500"
                  @change="applyFilters">
                  <option value="">All Platforms</option>
                  <option value="facebook">Facebook</option>
                  <option value="instagram">Instagram</option>
                  <option value="youtube">YouTube</option>
                  <option value="twitter">Twitter</option>
                  <option value="linkedin">LinkedIn</option>
                </select>
              </div>
  
              <!-- Is Lead Filter -->
              <div class="flex items-center">
                <input v-model="filters.is_lead" type="checkbox" id="is_lead"
                  class="w-4 h-4 text-blue-500 bg-slate-700 border-slate-600 rounded focus:ring-blue-500"
                  @change="applyFilters" />
                <label for="is_lead" class="ml-2 text-sm font-medium text-slate-300">
                  Only Leads
                </label>
              </div>
  
              <!-- Clear Filters Button -->
              <button @click="clearFilters"
                class="w-full mt-6 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded transition-colors">
                Clear Filters
              </button>
            </div>
          </div>
  
          <!-- Comments List -->
          <div class="lg:col-span-3">
            <div v-if="loading" class="text-center py-12">
              <div class="inline-block">
                <div class="w-8 h-8 border-4 border-slate-600 border-t-blue-500 rounded-full animate-spin"></div>
              </div>
              <p class="text-slate-400 mt-4">Loading comments...</p>
            </div>
  
            <div v-else-if="comments.length === 0" class="dashboard-card text-center py-12">
              <p class="text-slate-400 text-lg">No comments found</p>
              <p class="text-slate-500 text-sm mt-2">Connect a social account to start seeing comments</p>
            </div>
  
            <div v-else class="space-y-4">
              <div v-for="comment in comments" :key="comment.id" class="comment-card" @click="selectedComment = comment"
                :class="{ 'ring-2 ring-blue-500': selectedComment?.id === comment.id }">
                <div class="flex justify-between items-start mb-3">
                  <div class="flex-1">
                    <p class="font-semibold text-white">{{ comment.author_name }}</p>
                    <p class="text-xs text-slate-400">
                      {{ comment.socialAccount.platform }} •
                      {{ formatDate(comment.commented_at) }}
                    </p>
                  </div>
                  <div class="flex gap-2">
                    <span :class="['px-2 py-1 rounded text-xs font-medium', getSentimentBadge(comment.sentiment)]">
                      {{ comment.sentiment }}
                    </span>
                    <span :class="['px-2 py-1 rounded text-xs font-medium', getIntentBadge(comment.intent)]">
                      {{ comment.intent }}
                    </span>
                  </div>
                </div>
  
                <p class="text-slate-300 text-sm mb-3">{{ comment.content }}</p>
  
                <div class="flex justify-between items-center text-xs">
                  <div class="flex gap-4 text-slate-400">
                    <span>Lead Score: <span class="text-white font-bold">{{ comment.lead_score }}</span></span>
                    <span v-if="comment.is_lead" class="text-green-400">✓ Qualified Lead</span>
                  </div>
                  <span :class="['px-2 py-1 rounded', getStatusBadge(comment.status)]">
                    {{ comment.status }}
                  </span>
                </div>
              </div>
  
              <!-- Pagination -->
              <div v-if="totalPages > 1" class="mt-8 flex justify-center gap-2">
                <button @click="previousPage" :disabled="currentPage === 1"
                  class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-white rounded">
                  Previous
                </button>
                <span class="px-4 py-2 text-slate-300">
                  Page {{ currentPage }} of {{ totalPages }}
                </span>
                <button @click="nextPage" :disabled="currentPage === totalPages"
                  class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-white rounded">
                  Next
                </button>
              </div>
            </div>
          </div>
        </div>
  
        <!-- Detail Panel -->
        <CommentDetailPanel v-if="selectedComment" :comment="selectedComment" @close="selectedComment = null"
          @updated="refreshComments" />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import CommentDetailPanel from '@/Components/Comments/CommentDetailPanel.vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const page = usePage()

const comments = ref(page.props.comments?.data || [])
const totalComments = ref(page.props.comments?.total || 0)
const currentPage = ref(1)
const totalPages = computed(() => Math.ceil(totalComments.value / 20))

const selectedComment = ref(null)
const loading = ref(false)

const filters = ref({
  search: '',
  status: '',
  sentiment: '',
  intent: '',
  platform: '',
  is_lead: false,
})

const applyFilters = async () => {
  loading.value = true
  try {
    const response = await fetch('/inbox/filter?' + new URLSearchParams(filters.value))
    const data = await response.json()
    comments.value = data.data || []
    totalComments.value = data.total || 0
    currentPage.value = 1
  } catch (error) {
    console.error('Error filtering comments:', error)
  } finally {
    loading.value = false
  }
}

const clearFilters = () => {
  filters.value = {
    search: '',
    status: '',
    sentiment: '',
    intent: '',
    platform: '',
    is_lead: false,
  }
  applyFilters()
}

const previousPage = () => {
  if (currentPage.value > 1) {
    currentPage.value--
  }
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) {
    currentPage.value++
  }
}

const refreshComments = () => {
  applyFilters()
}

const getSentimentBadge = (sentiment) => {
  const badges = {
    positive: 'bg-green-900 text-green-200',
    neutral: 'bg-slate-700 text-slate-200',
    negative: 'bg-red-900 text-red-200',
    pending: 'bg-yellow-900 text-yellow-200'
  }
  return badges[sentiment] || 'bg-slate-700 text-slate-200'
}

const getIntentBadge = (intent) => {
  const badges = {
    sales: 'bg-blue-900 text-blue-200',
    support: 'bg-purple-900 text-purple-200',
    complaint: 'bg-red-900 text-red-200',
    lead: 'bg-green-900 text-green-200',
    question: 'bg-yellow-900 text-yellow-200',
    general: 'bg-slate-700 text-slate-200'
  }
  return badges[intent] || 'bg-slate-700 text-slate-200'
}

const getStatusBadge = (status) => {
  const badges = {
    new: 'bg-yellow-900/20 text-yellow-200',
    reviewed: 'bg-blue-900/20 text-blue-200',
    replied: 'bg-green-900/20 text-green-200',
    dismissed: 'bg-slate-700/20 text-slate-300'
  }
  return badges[status] || 'bg-slate-700/20 text-slate-300'
}

const formatDate = (date) => {
  const d = new Date(date)
  return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}
</script>

<style scoped>
.dashboard-card {
  @apply bg-slate-800 rounded-lg border border-slate-700 p-6 hover:border-slate-600 transition-colors;
}

.comment-card {
  @apply bg-slate-800 rounded-lg border border-slate-700 p-4 hover:border-slate-600 hover:bg-slate-700/50 transition-all cursor-pointer;
}
</style>