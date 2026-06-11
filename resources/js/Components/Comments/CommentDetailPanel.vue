<!-- resources/js/Components/Comments/CommentDetailPanel.vue -->
<template>
    <Teleport to="body">
        <div v-if="comment" class="fixed inset-0 z-50 lg:relative lg:z-0">
            <!-- Mobile: Full screen backdrop -->
            <div class="lg:hidden absolute inset-0 bg-black/50" @click="$emit('close')"></div>

            <!-- Panel -->
            <div
                class="fixed inset-0 right-0 w-full lg:static lg:w-96 bg-slate-800 border-l border-slate-700 overflow-y-auto z-50 lg:z-auto">
                <!-- Close Button (Mobile) -->
                <button @click="$emit('close')"
                    class="lg:hidden absolute top-4 left-4 text-slate-400 hover:text-slate-300 z-10">
                    ← Back
                </button>

                <!-- Header -->
                <div class="sticky top-0 bg-slate-800 border-b border-slate-700 p-6 pt-16 lg:pt-6">
                    <h3 class="text-lg font-bold text-white">{{ comment.author_name }}</h3>
                    <p class="text-slate-400 text-sm">{{ comment.social_account.platform }}</p>
                </div>

                <!-- Content -->
                <div class="p-6 space-y-6">
                    <!-- Comment -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Comment</label>
                        <div class="bg-slate-700 rounded p-4 text-slate-300">
                            {{ comment.content }}
                        </div>
                    </div>

                    <!-- Analysis Results -->
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Sentiment</label>
                            <Badge :variant="getSentimentVariant(comment.sentiment)">
                                {{ comment.sentiment }} ({{ comment.sentiment_score }}/100)
                            </Badge>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Intent</label>
                            <Badge :variant="getIntentVariant(comment.intent)">
                                {{ comment.intent }}
                            </Badge>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Lead Score</label>
                            <p class="text-white font-bold">{{ comment.lead_score }}/100</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Status</label>
                            <p class="text-white capitalize">{{ comment.status }}</p>
                        </div>
                    </div>

                    <!-- AI Response (if available) -->
                    <div v-if="aiConversation" class="border-t border-slate-700 pt-6">
                        <h4 class="font-bold text-white mb-3">AI Response</h4>

                        <div v-if="aiConversation.ai_response" class="bg-slate-700 rounded p-4 text-slate-300 mb-4">
                            {{ aiConversation.ai_response }}
                        </div>

                        <div v-if="aiConversation.requires_human_review"
                            class="bg-yellow-900/30 border border-yellow-700 rounded p-3 mb-4">
                            <p class="text-yellow-200 text-sm">⚠️ {{ aiConversation.review_reason }}</p>
                        </div>

                        <div v-if="aiConversation.response_status === 'pending'" class="space-y-2">
                            <button @click="approveResponse"
                                class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded font-medium">
                                Approve & Send
                            </button>
                            <button @click="showRejectForm = true"
                                class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium">
                                Reject
                            </button>
                        </div>

                        <div v-if="aiConversation.response_status === 'approved'"
                            class="bg-green-900/30 border border-green-700 rounded p-3">
                            <p class="text-green-200 text-sm">✓ Response approved and queued for sending</p>
                        </div>

                        <div v-if="aiConversation.response_status === 'auto_sent'"
                            class="bg-blue-900/30 border border-blue-700 rounded p-3">
                            <p class="text-blue-200 text-sm">✓ Response sent automatically</p>
                            <p class="text-blue-200 text-xs mt-1">Sent at: {{ formatDate(aiConversation.sent_at) }}</p>
                        </div>
                    </div>

                    <!-- Manual Reply Option -->
                    <div v-else class="border-t border-slate-700 pt-6">
                        <h4 class="font-bold text-white mb-3">Send Manual Reply</h4>
                        <textarea v-model="manualReply" placeholder="Write your reply..." rows="4"
                            class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400"></textarea>
                        <button @click="sendManualReply"
                            class="mt-2 w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium">
                            Send Reply
                        </button>
                    </div>

                    <!-- Mark as Responded -->
                    <div v-if="comment.status !== 'replied'">
                        <button @click="markAsResponded"
                            class="w-full px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded font-medium">
                            Mark as Responded
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reject Modal -->
        <Modal v-model="showRejectForm" title="Reject Response">
            <textarea v-model="rejectReason" placeholder="Why are you rejecting this response?" rows="4"
                class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"></textarea>
            <template #footer>
                <button @click="submitReject" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded">
                    Reject
                </button>
                <button @click="showRejectForm = false"
                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded">
                    Cancel
                </button>
            </template>
        </Modal>
    </Teleport>
</template>

<script setup>
import { ref } from 'vue'
import Modal from '@/Components/Modal.vue'
import Badge from '@/Components/Badge.vue'

const props = defineProps({
    comment: Object,
    aiConversation: Object,
})

const emit = defineEmits(['close', 'updated'])

const manualReply = ref('')
const showRejectForm = ref(false)
const rejectReason = ref('')

const getSentimentVariant = (sentiment) => {
    const variants = {
        positive: 'success',
        neutral: 'info',
        negative: 'danger',
        pending: 'warning',
    }
    return variants[sentiment] || 'info'
}

const getIntentVariant = (intent) => {
    const variants = {
        sales: 'primary',
        support: 'info',
        complaint: 'danger',
        lead: 'success',
        question: 'warning',
        general: 'info',
    }
    return variants[intent] || 'info'
}

const approveResponse = async () => {
    console.log('Approving response...')
    emit('updated')
}

const submitReject = async () => {
    console.log('Rejecting with reason:', rejectReason.value)
    showRejectForm.value = false
    emit('updated')
}

const sendManualReply = async () => {
    console.log('Sending manual reply:', manualReply.value)
    manualReply.value = ''
    emit('updated')
}

const markAsResponded = async () => {
    console.log('Marking as responded...')
    emit('updated')
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString()
}
</script>