<template>
  <AppLayout>
    <div class="min-h-screen bg-slate-900">
      <!-- Header -->
      <div class="bg-slate-800 border-b border-slate-700 p-6">
        <div class="max-w-7xl mx-auto">
          <h1 class="text-3xl font-bold text-white mb-4">📩 Inbox</h1>

          <!-- Stats -->
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-slate-700 rounded p-4">
              <p class="text-slate-400 text-sm">Total Comments</p>
              <p class="text-2xl font-bold text-white">{{ stats.total }}</p>
            </div>
            <div class="bg-slate-700 rounded p-4">
              <p class="text-slate-400 text-sm">New</p>
              <p class="text-2xl font-bold text-blue-400">{{ stats.new }}</p>
            </div>
            <div class="bg-slate-700 rounded p-4">
              <p class="text-slate-400 text-sm">Pending</p>
              <p class="text-2xl font-bold text-yellow-400">{{ stats.pending }}</p>
            </div>
            <div class="bg-slate-700 rounded p-4">
              <p class="text-slate-400 text-sm">Replied</p>
              <p class="text-2xl font-bold text-green-400">{{ stats.replied }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-slate-800 border-b border-slate-700 p-6">
        <div class="max-w-7xl mx-auto">
          <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <!-- Search -->
            <input v-model="filters.search" @input="applyFilters" type="text" placeholder="Search comments..."
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500" />

            <!-- Status Filter -->
            <select v-model="filters.status" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:outline-none focus:border-blue-500">
              <option value="">All Status</option>
              <option value="new">New</option>
              <option value="pending_approval">Pending Approval</option>
              <option value="replied">Replied</option>
              <option value="reviewed">Reviewed</option>
            </select>

            <!-- Sentiment Filter -->
            <select v-model="filters.sentiment" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:outline-none focus:border-blue-500">
              <option value="">All Sentiment</option>
              <option value="positive">Positive</option>
              <option value="neutral">Neutral</option>
              <option value="negative">Negative</option>
            </select>

            <!-- Platform Filter -->
            <select v-model="filters.platform" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:outline-none focus:border-blue-500">
              <option value="">All Platforms</option>
              <option value="facebook">Facebook</option>
              <option value="instagram">Instagram</option>
            </select>

            <!-- Intent Filter -->
            <select v-model="filters.intent" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:outline-none focus:border-blue-500">
              <option value="">All Intent</option>
              <option value="sales">Sales</option>
              <option value="support">Support</option>
              <option value="complaint">Complaint</option>
              <option value="question">Question</option>
              <option value="lead">Lead</option>
              <option value="general">General</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Comments List -->
      <div class="max-w-7xl mx-auto p-6">
        <div v-if="loading" class="text-center py-8">
          <p class="text-slate-400">Loading comments...</p>
        </div>

        <div v-else-if="filteredComments.length === 0" class="text-center py-12">
          <p class="text-slate-400 text-lg">No comments found</p>
        </div>

        <div v-else class="space-y-4">
          <div v-for="comment in filteredComments" :key="comment.id" @click="openCommentDetail(comment)"
            class="bg-slate-800 border border-slate-700 rounded-lg p-6 hover:border-blue-500 cursor-pointer transition-colors">
            <!-- Comment Header -->
            <div class="flex items-start justify-between mb-4">
              <div class="flex-1">
                <h3 class="text-lg font-bold text-white">{{ comment.author_name }}</h3>
                <p class="text-slate-400 text-sm">
                  <span class="capitalize font-mono">{{ comment.platform }}</span>
                  · {{ formatDate(comment.commented_at) }}
                </p>
              </div>

              <!-- Status Badge -->
              <span :class="getStatusColor(comment.status)"
                class="px-3 py-1 rounded-full text-sm font-medium capitalize">
                {{ comment.status }}
              </span>
            </div>

            <!-- Comment Content -->
            <p class="text-slate-200 mb-4 line-clamp-2">{{ comment.content }}</p>

            <!-- Analysis Tags -->
            <div class="flex flex-wrap gap-2 mb-4">
              <span v-if="comment.sentiment" :class="getSentimentColor(comment.sentiment)"
                class="px-2 py-1 rounded text-xs font-medium">
                {{ comment.sentiment }} ({{ comment.sentiment_score }}%)
              </span>
              <span v-if="comment.intent" :class="getIntentColor(comment.intent)"
                class="px-2 py-1 rounded text-xs font-medium capitalize">
                {{ comment.intent }}
              </span>
              <span v-if="comment.is_lead" class="px-2 py-1 rounded text-xs font-medium bg-green-900 text-green-200">
                ✓ Lead ({{ comment.lead_score }}%)
              </span>
            </div>

            <!-- Quick Actions -->
            <div class="flex gap-2">
              <button @click.stop="replyToComment(comment)"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg transition-colors">
                Reply
              </button>
              <button v-if="comment.status !== 'replied'" @click.stop="markAsResponded(comment)"
                class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm rounded-lg transition-colors">
                Mark Replied
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Comment Detail Modal -->
      <CommentDetailModal v-if="selectedComment" :comment="selectedComment" @close="selectedComment = null"
        @reply="handleReply" @marked="refreshComments" />

      <!-- Reply Modal -->
      <ReplyModal v-if="replyingTo" :comment="replyingTo" @close="replyingTo = null" @sent="handleReplySent" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import CommentDetailModal from '@/Components/Comments/CommentDetailModal.vue'
import ReplyModal from '@/Components/Comments/ReplyModal.vue'

const comments = ref([])
const loading = ref(false)
const selectedComment = ref(null)
const replyingTo = ref(null)

const filters = ref({
  search: '',
  status: '',
  sentiment: '',
  platform: '',
  intent: '',
})

const stats = ref({
  total: 0,
  new: 0,
  pending: 0,
  replied: 0,
})

const filteredComments = computed(() => {
  return comments.value.filter(comment => {
    if (filters.value.search && !comment.content.toLowerCase().includes(filters.value.search.toLowerCase())) {
      return false
    }
    if (filters.value.status && comment.status !== filters.value.status) return false
    if (filters.value.sentiment && comment.sentiment !== filters.value.sentiment) return false
    if (filters.value.platform && comment.platform !== filters.value.platform) return false
    if (filters.value.intent && comment.intent !== filters.value.intent) return false
    return true
  })
})

onMounted(() => {
  loadComments()
})

const loadComments = async () => {
  loading.value = true
  try {
    const response = await fetch('/inbox/filter?' + new URLSearchParams(filters.value))
    const data = await response.json()
    comments.value = data.data || []

    // Update stats
    stats.value = {
      total: comments.value.length,
      new: comments.value.filter(c => c.status === 'new').length,
      pending: comments.value.filter(c => c.status === 'pending_approval').length,
      replied: comments.value.filter(c => c.status === 'replied').length,
    }
  } catch (error) {
    console.error('Error loading comments:', error)
  } finally {
    loading.value = false
  }
}

const applyFilters = () => {
  loadComments()
}

const refreshComments = () => {
  loadComments()
}

const openCommentDetail = (comment) => {
  selectedComment.value = comment
}

const replyToComment = (comment) => {
  replyingTo.value = comment
}

const handleReply = async (commentId, message) => {
  try {
    const response = await fetch(`/inbox/${commentId}/reply`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      },
      body: JSON.stringify({ message }),
    })

    if (response.ok) {
      refreshComments()
      selectedComment.value = null
    }
  } catch (error) {
    console.error('Error sending reply:', error)
  }
}

const handleReplySent = () => {
  replyingTo.value = null
  refreshComments()
}

const markAsResponded = async (comment) => {
  try {
    const response = await fetch(`/inbox/${comment.id}/mark-responded`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      },
    })

    if (response.ok) {
      refreshComments()
    }
  } catch (error) {
    console.error('Error marking as responded:', error)
  }
}

const formatDate = (date) => {
  if (!date) return ''
  return new Date(date).toLocaleDateString() + ' ' + new Date(date).toLocaleTimeString()
}

const getStatusColor = (status) => {
  const colors = {
    new: 'bg-blue-900 text-blue-200',
    pending_approval: 'bg-yellow-900 text-yellow-200',
    replied: 'bg-green-900 text-green-200',
    reviewed: 'bg-slate-700 text-slate-200',
  }
  return colors[status] || 'bg-slate-700 text-slate-200'
}

const getSentimentColor = (sentiment) => {
  const colors = {
    positive: 'bg-green-900 text-green-200',
    neutral: 'bg-slate-700 text-slate-200',
    negative: 'bg-red-900 text-red-200',
  }
  return colors[sentiment] || 'bg-slate-700 text-slate-200'
}

const getIntentColor = (intent) => {
  const colors = {
    sales: 'bg-blue-900 text-blue-200',
    support: 'bg-purple-900 text-purple-200',
    complaint: 'bg-red-900 text-red-200',
    question: 'bg-yellow-900 text-yellow-200',
    lead: 'bg-green-900 text-green-200',
  }
  return colors[intent] || 'bg-slate-700 text-slate-200'
}
</script>