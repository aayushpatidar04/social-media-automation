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
                <!-- Loading State -->
                <div v-if="loading" class="text-center py-8">
                    <p class="text-slate-400">Loading AI response...</p>
                </div>

                <div v-else class="space-y-4">
                    <!-- Original Comment -->
                    <div class="bg-slate-700 rounded p-4">
                        <p class="text-slate-400 text-sm mb-2">Original Comment:</p>
                        <p class="text-white">{{ comment.content }}</p>
                    </div>

                    <!-- AI Response Options -->
                    <div v-if="aiResponse.has_ai_response" class="space-y-2">
                        <p class="text-sm font-medium text-slate-300">Response Options:</p>

                        <label
                            class="flex items-start gap-3 p-3 bg-slate-700 rounded cursor-pointer hover:bg-slate-600 transition-colors">
                            <input type="radio" v-model="replyType" value="manual" class="w-4 h-4 mt-1" />
                            <span class="text-white text-sm">Write manual reply</span>
                        </label>

                        <label
                            class="flex items-start gap-3 p-3 bg-slate-700 rounded cursor-pointer hover:bg-slate-600 transition-colors">
                            <input type="radio" v-model="replyType" value="ai" class="w-4 h-4 mt-1" />
                            <div>
                                <span class="text-white text-sm block">Use AI suggested response</span>
                                <span class="text-slate-400 text-xs">
                                    Confidence: {{ Math.round(aiResponse.confidence * 100) }}% •
                                    Model: {{ aiResponse.model_used }}
                                </span>
                            </div>
                        </label>
                    </div>

                    <!-- Reply Input -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-3">
                            {{ replyType === 'ai' ? 'AI Suggested Reply' : 'Your Reply' }}
                        </label>
                        <textarea v-model="replyMessage"
                            :placeholder="replyType === 'ai' ? 'AI response loaded' : 'Write your reply here...'"
                            rows="6" maxlength="500"
                            class="w-full px-4 py-3 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-blue-500"></textarea>
                        <p class="text-slate-500 text-sm mt-2">{{ replyMessage.length }}/500 characters</p>
                    </div>

                    <!-- Info Message -->
                    <div v-if="replyType === 'ai' && aiResponse.has_ai_response"
                        class="bg-blue-900/30 border border-blue-700 rounded p-3">
                        <p class="text-blue-200 text-sm">
                            💡 You can edit the AI suggestion before sending. We'll note that this response was
                            AI-assisted.
                        </p>
                    </div>

                    <div v-if="replyType === 'manual'" class="bg-slate-700/50 rounded p-3">
                        <p class="text-slate-300 text-sm">
                            ✍️ This will be recorded as a manual reply from you.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div v-if="!loading" class="bg-slate-700 border-t border-slate-600 p-6 flex gap-3">
                <button @click="$emit('close')"
                    class="flex-1 px-4 py-3 bg-slate-600 hover:bg-slate-700 text-white rounded-lg font-medium transition-colors">
                    Cancel
                </button>
                <button @click="sendReply" :disabled="!replyMessage.trim() || replyMessage.length > 500 || sending"
                    class="flex-1 px-4 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-600 text-white rounded-lg font-medium transition-colors">
                    {{ sending ? 'Sending...' : 'Send Reply' }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const props = defineProps({
  comment: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['close', 'sent'])

const loading = ref(true)
const sending = ref(false)
const replyType = ref('manual')
const replyMessage = ref('')

const aiResponse = ref({
  has_ai_response: false,
  ai_response: null,
  confidence: 0,
  model_used: null,
  created_at: null,
  status: null,
})

// Watch replyType and update message accordingly
const watchReplyType = () => {
  if (replyType.value === 'ai' && aiResponse.value.ai_response) {
    replyMessage.value = aiResponse.value.ai_response
  } else if (replyType.value === 'manual') {
    replyMessage.value = ''
  }
}

onMounted(async () => {
  await fetchAiConversation()
  
  // If there's an AI response, default to using it
  if (aiResponse.value.has_ai_response) {
    replyType.value = 'ai'
    watchReplyType()
  }
  
  loading.value = false
})

const fetchAiConversation = async () => {
  try {
    const response = await fetch(`/inbox/${props.comment.id}/ai-conversation`, {
      method: 'GET',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      },
    })

    if (response.ok) {
      aiResponse.value = await response.json()
      console.log('AI Response loaded:', aiResponse.value)
    } else {
      console.error('Failed to fetch AI conversation')
      aiResponse.value = {
        has_ai_response: false,
        ai_response: null,
        confidence: 0,
        model_used: null,
      }
    }
  } catch (error) {
    console.error('Error fetching AI conversation:', error)
    aiResponse.value = {
      has_ai_response: false,
      ai_response: null,
      confidence: 0,
      model_used: null,
    }
  }
}

const sendReply = async () => {
  if (!replyMessage.value.trim() || replyMessage.value.length > 500) {
    return
  }

  sending.value = true
  try {
    const response = await fetch(`/inbox/${props.comment.id}/reply`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      },
      body: JSON.stringify({
        message: replyMessage.value,
        is_ai_response: replyType.value === 'ai',
      }),
    })

    const data = await response.json()

    if (response.ok) {
      console.log('Reply sent successfully')
      emit('sent')
      emit('close')
    } else {
      alert(data.error || 'Failed to send reply')
    }
  } catch (error) {
    console.error('Error sending reply:', error)
    alert('Error sending reply: ' + error.message)
  } finally {
    sending.value = false
  }
}

// Watch for reply type changes
const handleReplyTypeChange = () => {
  watchReplyType()
}
</script>

<style scoped>
input[type="radio"]:checked+span {
    color: #60a5fa;
}
</style>