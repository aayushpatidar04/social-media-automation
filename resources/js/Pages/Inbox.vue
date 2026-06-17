<template>
  <AppLayout>
    <div class="min-h-screen bg-slate-900">
      <!-- Header -->
      <div class="bg-slate-800 border-b border-slate-700 p-6">
        <div class="max-w-7xl mx-auto">
          <h1 class="text-3xl font-bold text-white mb-4">📩 Inbox</h1>

          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-slate-700 rounded p-4">
              <p class="text-slate-400 text-sm">Total Threads</p>
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
            <input v-model="filters.search" @input="applyFilters" type="text" placeholder="Search conversations..."
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500" />

            <select v-model="filters.status" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:outline-none focus:border-blue-500">
              <option value="">All Status</option>
              <option value="new">New</option>
              <option value="pending_approval">Pending Approval</option>
              <option value="replied">Replied</option>
              <option value="reviewed">Reviewed</option>
            </select>

            <select v-model="filters.sentiment" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:outline-none focus:border-blue-500">
              <option value="">All Sentiment</option>
              <option value="positive">Positive</option>
              <option value="neutral">Neutral</option>
              <option value="negative">Negative</option>
            </select>

            <select v-model="filters.platform" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:outline-none focus:border-blue-500">
              <option value="">All Platforms</option>
              <option value="facebook">Facebook</option>
              <option value="instagram">Instagram</option>
              <option value="youtube">YouTube</option>
              <option value="twitter">X/Twitter</option>
              <option value="linkedin">LinkedIn</option>
            </select>

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

      <!-- Thread List -->
      <div class="max-w-7xl mx-auto p-6">
        <div v-if="loading" class="text-center py-8">
          <p class="text-slate-400">Loading conversations...</p>
        </div>

        <div v-else-if="threads.length === 0" class="text-center py-12">
          <p class="text-slate-400 text-lg">No conversations found</p>
        </div>

        <div v-else class="space-y-4">
          <div v-for="thread in threads" :key="thread.id" @click="openThreadDetail(thread)"
            class="bg-slate-800 border border-slate-700 rounded-lg p-6 hover:border-blue-500 cursor-pointer transition-colors">
            <div class="flex items-start justify-between mb-4">
              <div class="flex-1">
                <h3 class="text-lg font-bold text-white">
                  {{ thread.author_name }}
                </h3>

                <p class="text-slate-400 text-sm">
                  <span class="capitalize font-mono">{{ platformLabel(thread.platform) }}</span>
                  · {{ formatDate(thread.commented_at) }}
                  <span v-if="thread.thread_replies?.length">
                    · 💬 {{ thread.thread_replies.length }} replies
                  </span>
                </p>
              </div>

              <span :class="getStatusColor(thread.status)"
                class="px-3 py-1 rounded-full text-sm font-medium capitalize">
                {{ thread.status }}
              </span>
            </div>

            <!-- Root Message -->
            <p class="text-slate-200 mb-4 line-clamp-2">
              {{ thread.content }}
            </p>

            <!-- Latest Message Preview -->
            <div v-if="latestMessage(thread)" class="bg-slate-900 rounded p-3 mb-4 border border-slate-700">
              <p class="text-xs text-slate-500 mb-1">
                Latest message ·
                {{ latestMessage(thread).direction === 'outbound' ? 'You / AI' : latestMessage(thread).author_name }}
              </p>
              <p class="text-slate-300 text-sm line-clamp-1">
                {{ latestMessage(thread).content }}
              </p>
            </div>

            <!-- Analysis Tags -->
            <div class="flex flex-wrap gap-2 mb-4">
              <span v-if="thread.sentiment" :class="getSentimentColor(thread.sentiment)"
                class="px-2 py-1 rounded text-xs font-medium">
                {{ thread.sentiment }} ({{ thread.sentiment_score }}%)
              </span>

              <span v-if="thread.intent" :class="getIntentColor(thread.intent)"
                class="px-2 py-1 rounded text-xs font-medium capitalize">
                {{ thread.intent }}
              </span>

              <span v-if="thread.is_lead" class="px-2 py-1 rounded text-xs font-medium bg-green-900 text-green-200">
                ✓ Lead ({{ thread.lead_score }}%)
              </span>
            </div>

            <!-- Conversation Mini Timeline -->
            <div v-if="thread.thread_replies?.length" class="mb-4 border-l-2 border-slate-700 pl-4 space-y-2">
              <div v-for="reply in thread.thread_replies.slice(-3)" :key="reply.id" class="text-sm">
                <span :class="reply.direction === 'outbound' ? 'text-blue-300' : 'text-slate-300'"
                  class="font-semibold">
                  {{ reply.direction === 'outbound' ? 'You / AI' : reply.author_name }}:
                </span>
                <span class="text-slate-400 line-clamp-1">
                  {{ reply.content }}
                </span>
              </div>
            </div>

            <!-- Quick Actions -->
            <div class="flex gap-2">
              <button @click.stop="replyToThread(thread)"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg transition-colors">
                Reply
              </button>

              <button v-if="thread.status !== 'replied'" @click.stop="markAsResponded(thread)"
                class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm rounded-lg transition-colors">
                Mark Replied
              </button>
            </div>
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="pagination?.links" class="mt-6 flex flex-wrap gap-2">
          <button v-for="link in pagination.links" :key="link.label" v-html="link.label" :disabled="!link.url"
            @click="goToPage(link.url)" :class="[
              'px-3 py-2 rounded text-sm',
              link.active ? 'bg-blue-600 text-white' : 'bg-slate-700 text-slate-300',
              !link.url ? 'opacity-50 cursor-not-allowed' : 'hover:bg-slate-600'
            ]" />
        </div>
      </div>

      <!-- Thread Detail Modal -->
      <div v-if="selectedThread" class="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50 p-4">
        <div class="bg-slate-800 rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden border border-slate-700">
          <div class="p-6 border-b border-slate-700 flex justify-between items-start">
            <div>
              <h2 class="text-2xl font-bold text-white">Conversation</h2>
              <p class="text-slate-400 text-sm">
                {{ platformLabel(selectedThread.platform) }}
                · {{ selectedThread.author_name }}
              </p>
            </div>

            <button @click="selectedThread = null" class="text-slate-400 hover:text-white text-2xl">
              ×
            </button>
          </div>

          <div class="p-6 overflow-y-auto max-h-[65vh] space-y-4">
            <!-- Root -->
            <MessageBubble :message="selectedThread" />

            <!-- Replies -->
            <MessageBubble v-for="reply in selectedThread.thread_replies" :key="reply.id" :message="reply" />
          </div>

          <div class="p-6 border-t border-slate-700 flex justify-end gap-2">
            <button @click="replyToThread(selectedThread)"
              class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
              Reply to Latest Customer Message
            </button>

            <button @click="selectedThread = null"
              class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg">
              Close
            </button>
          </div>
        </div>
      </div>

      <ReplyModal v-if="replyingTo" :comment="replyingTo" @close="replyingTo = null" @sent="handleReplySent" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, defineComponent, h } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ReplyModal from '@/Components/Comments/ReplyModal.vue'

const page = usePage()

const commentsProp = computed(() => page.props.comments || {})
const initialFilters = computed(() => page.props.filters || {})

const loading = ref(false)
const selectedThread = ref(null)
const replyingTo = ref(null)

const filters = ref({
  search: initialFilters.value.search || '',
  status: initialFilters.value.status || '',
  sentiment: initialFilters.value.sentiment || '',
  platform: initialFilters.value.platform || '',
  intent: initialFilters.value.intent || '',
})

const threads = computed(() => {
  return commentsProp.value.data || []
})

const pagination = computed(() => {
  return {
    links: commentsProp.value.links || [],
    meta: commentsProp.value.meta || null,
  }
})

const stats = computed(() => {
  return {
    total: threads.value.length,
    new: threads.value.filter(c => c.status === 'new').length,
    pending: threads.value.filter(c => c.status === 'pending_approval').length,
    replied: threads.value.filter(c => c.status === 'replied').length,
  }
})

onMounted(() => {
  //
})

const applyFilters = () => {
  router.get('/inbox', filters.value, {
    preserveState: true,
    replace: true,
  })
}

const goToPage = (url) => {
  if (!url) return

  router.visit(url, {
    preserveState: true,
    preserveScroll: true,
  })
}

const refreshComments = () => {
  router.reload({
    only: ['comments'],
  })
}

const openThreadDetail = (thread) => {
  selectedThread.value = thread
}

const latestMessage = (thread) => {
  const replies = thread.thread_replies || []

  if (!replies.length) {
    return null
  }

  return replies[replies.length - 1]
}

const getReplyTarget = (thread) => {
  const allMessages = [thread, ...(thread.thread_replies || [])]

  const inboundMessages = allMessages
    .filter(item => item.direction === 'inbound')
    .sort((a, b) => new Date(b.commented_at) - new Date(a.commented_at))

  return inboundMessages[0] || thread
}

const replyToThread = (thread) => {
  replyingTo.value = getReplyTarget(thread)
}

const handleReplySent = () => {
  replyingTo.value = null
  selectedThread.value = null
  refreshComments()
}

const markAsResponded = async (thread) => {
  try {
    const response = await fetch(`/inbox/${thread.id}/mark-responded`, {
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

const platformLabel = (platform) => {
  const labels = {
    facebook: 'Facebook',
    instagram: 'Instagram',
    youtube: 'YouTube',
    twitter: 'X/Twitter',
    linkedin: 'LinkedIn',
  }

  return labels[platform] || platform
}

const getStatusColor = (status) => {
  const colors = {
    new: 'bg-blue-900 text-blue-200',
    pending_approval: 'bg-yellow-900 text-yellow-200',
    replied: 'bg-green-900 text-green-200',
    reviewed: 'bg-slate-700 text-slate-200',
    sent: 'bg-blue-900 text-blue-200',
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

const MessageBubble = defineComponent({
  name: 'MessageBubble',
  props: {
    message: {
      type: Object,
      required: true,
    },
  },
  setup(props) {
    const isOutbound = computed(() => props.message.direction === 'outbound')

    const name = computed(() => {
      if (isOutbound.value) {
        return props.message.sender_type === 'ai' ? 'AI / You' : 'You'
      }

      return props.message.author_name || 'Customer'
    })

    const dateText = computed(() => {
      if (!props.message.commented_at) return ''

      return new Date(props.message.commented_at).toLocaleDateString() +
        ' ' +
        new Date(props.message.commented_at).toLocaleTimeString()
    })

    return () =>
      h(
        'div',
        {
          class: isOutbound.value ? 'flex justify-end' : 'flex justify-start',
        },
        [
          h(
            'div',
            {
              class: [
                'max-w-2xl rounded-lg p-4',
                isOutbound.value
                  ? 'bg-blue-600 text-white'
                  : 'bg-slate-700 text-white',
              ],
            },
            [
              h(
                'p',
                {
                  class: 'text-xs opacity-80 mb-1',
                },
                `${name.value} · ${dateText.value}`
              ),
              h(
                'p',
                {
                  class: 'whitespace-pre-line',
                },
                props.message.content || ''
              ),
            ]
          ),
        ]
      )
  },
})
</script>