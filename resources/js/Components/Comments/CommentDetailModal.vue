<template>
    <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto hide-scrollbar">
            <!-- Header -->
            <div class="bg-slate-700 border-b border-slate-600 p-6 sticky top-0">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-white">{{ comment.author_name }}</h2>
                        <p class="text-slate-400 text-sm mt-1">
                            {{ formatDate(comment.commented_at) }} •
                            <span class="capitalize">{{ comment.platform }}</span>
                        </p>
                    </div>
                    <button @click="$emit('close')" class="text-slate-400 hover:text-white text-2xl">
                        ✕
                    </button>
                </div>
            </div>

            <!-- Content -->
            <div class="p-6 space-y-6">
                <!-- Comment -->
                <div>
                    <h3 class="text-lg font-bold text-white mb-3">Comment</h3>
                    <div class="bg-slate-700 rounded p-4 text-slate-200">
                        {{ comment.content }}
                    </div>
                </div>

                <!-- Analysis Results -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-slate-700 rounded p-4">
                        <p class="text-slate-400 text-sm mb-2">Sentiment</p>
                        <p class="text-xl font-bold text-white capitalize">
                            {{ comment.sentiment || 'Pending' }}
                        </p>
                        <p class="text-slate-400 text-xs mt-1">
                            {{ comment.sentiment_score || 0 }}/100
                        </p>
                    </div>

                    <div class="bg-slate-700 rounded p-4">
                        <p class="text-slate-400 text-sm mb-2">Intent</p>
                        <p class="text-xl font-bold text-white capitalize">
                            {{ comment.intent || 'Pending' }}
                        </p>
                    </div>

                    <div class="bg-slate-700 rounded p-4">
                        <p class="text-slate-400 text-sm mb-2">Lead Score</p>
                        <p class="text-xl font-bold text-white">
                            {{ comment.lead_score || 0 }}/100
                        </p>
                    </div>

                    <div class="bg-slate-700 rounded p-4">
                        <p class="text-slate-400 text-sm mb-2">Status</p>
                        <p class="text-xl font-bold text-white capitalize">
                            {{ comment.status }}
                        </p>
                    </div>
                </div>

                <!-- Lead Badge -->
                <div v-if="comment.is_lead" class="bg-green-900/30 border border-green-700 rounded p-4">
                    <p class="text-green-200">✓ This is a qualified lead</p>
                </div>

                <!-- AI Response (if exists) -->
                <div v-if="comment.ai_response_text" class="bg-slate-700 rounded p-4">
                    <h3 class="text-lg font-bold text-white mb-3">AI Response</h3>
                    <p class="text-slate-200 mb-4">{{ comment.ai_response_text }}</p>
                    <div class="flex gap-2">
                        <button @click="approveResponse"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded transition-colors">
                            Approve & Send
                        </button>
                        <button @click="rejectResponse"
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded transition-colors">
                            Reject
                        </button>
                    </div>
                </div>

                <!-- Reply Form -->
                <div>
                    <h3 class="text-lg font-bold text-white mb-3">Send Reply</h3>
                    <textarea v-model="replyMessage" placeholder="Write your reply here..." rows="4"
                        class="w-full px-4 py-3 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500"></textarea>
                    <p class="text-slate-500 text-sm mt-2">{{ replyMessage.length }}/500</p>
                    <button @click="sendReply" :disabled="!replyMessage.trim() || replyMessage.length > 500"
                        class="mt-4 px-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-600 text-white rounded-lg font-medium transition-colors w-full">
                        Send Reply
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
    comment: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['close', 'reply', 'marked'])

const replyMessage = ref('')

const formatDate = (date) => {
    if (!date) return ''
    return new Date(date).toLocaleDateString() + ' ' + new Date(date).toLocaleTimeString()
}

const sendReply = async () => {
    try {
        const response = await fetch(`/inbox/${props.comment.id}/reply`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ message: replyMessage.value }),
        })

        if (response.ok) {
            replyMessage.value = ''
            emit('reply', props.comment.id, replyMessage.value)
            emit('close')
        }
    } catch (error) {
        console.error('Error sending reply:', error)
    }
}

const approveResponse = async () => {
    try {
        const response = await fetch(`/inbox/${props.comment.id}/approve`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })

        if (response.ok) {
            emit('marked')
            emit('close')
        }
    } catch (error) {
        console.error('Error approving response:', error)
    }
}

const rejectResponse = async () => {
    try {
        const response = await fetch(`/inbox/${props.comment.id}/reject`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ reason: 'User rejected' }),
        })

        if (response.ok) {
            emit('marked')
            emit('close')
        }
    } catch (error) {
        console.error('Error rejecting response:', error)
    }
}
</script>