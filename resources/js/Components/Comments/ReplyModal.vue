<template>
    <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-lg max-w-lg w-full">
            <!-- Header -->
            <div class="bg-slate-700 border-b border-slate-600 p-6">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-white">Reply to {{ comment.author_name }}</h2>
                        <p class="text-slate-400 text-sm mt-1">{{ comment.platform === 'facebook' ? '📘 Facebook' : '📷 Instagram' }}</p>
                    </div>
                    <button @click="$emit('close')" class="text-slate-400 hover:text-white text-2xl">
                        ✕
                    </button>
                </div>
            </div>

            <!-- Body -->
            <div class="p-6 space-y-4">
                <!-- Original Comment -->
                <div class="bg-slate-700 rounded p-4">
                    <p class="text-slate-400 text-sm mb-2">Original Comment:</p>
                    <p class="text-white">{{ comment.content }}</p>
                </div>

                <!-- Reply Input -->
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-3">Your Reply</label>
                    <textarea v-model="replyMessage" placeholder="Write a thoughtful reply..." rows="6" maxlength="500"
                        class="w-full px-4 py-3 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500"></textarea>
                    <p class="text-slate-500 text-sm mt-2">{{ replyMessage.length }}/500 characters</p>
                </div>

                <!-- Options -->
                <div class="space-y-2">
                    <label class="flex items-center gap-3 p-3 bg-slate-700 rounded cursor-pointer hover:bg-slate-600">
                        <input type="radio" v-model="sendType" value="manual" class="w-4 h-4" />
                        <span class="text-white text-sm">Send reply manually</span>
                    </label>

                    <label v-if="comment.ai_response_text"
                        class="flex items-center gap-3 p-3 bg-slate-700 rounded cursor-pointer hover:bg-slate-600">
                        <input type="radio" v-model="sendType" value="ai" class="w-4 h-4" />
                        <span class="text-white text-sm">Use AI suggested response</span>
                    </label>
                </div>
            </div>

            <!-- Footer -->
            <div class="bg-slate-700 border-t border-slate-600 p-6 flex gap-3">
                <button @click="$emit('close')"
                    class="flex-1 px-4 py-3 bg-slate-600 hover:bg-slate-700 text-white rounded-lg font-medium transition-colors">
                    Cancel
                </button>
                <button @click="sendReply" :disabled="!replyMessage.trim() || sending"
                    class="flex-1 px-4 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-600 text-white rounded-lg font-medium transition-colors">
                    {{ sending ? 'Sending...' : 'Send Reply' }}
                </button>
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

const emit = defineEmits(['close', 'sent'])

const replyMessage = ref('')
const sendType = ref('manual')
const sending = ref(false)

const sendReply = async () => {
    sending.value = true
    try {
        const message = sendType.value === 'ai' ? props.comment.ai_response_text : replyMessage.value

        const response = await fetch(`/inbox/${props.comment.id}/reply`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ message }),
        })

        if (response.ok) {
            emit('sent')
            emit('close')
        }
    } catch (error) {
        console.error('Error sending reply:', error)
    } finally {
        sending.value = false
    }
}
</script>