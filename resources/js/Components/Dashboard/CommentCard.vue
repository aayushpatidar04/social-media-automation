<script setup>
import { computed } from 'vue'

const props = defineProps({
    comment: {
        type: Object,
        required: true
    }
})

const emit = defineEmits(['click'])

const sentimentColor = computed(() => {
    const sentiment = props.comment.sentiment
    return {
        positive: 'text-green-400',
        neutral: 'text-slate-400',
        negative: 'text-red-400',
        pending: 'text-yellow-400'
    }[sentiment] || 'text-slate-400'
})

const intentBadge = computed(() => {
    const intent = props.comment.intent
    const badges = {
        sales: 'bg-blue-900 text-blue-200',
        support: 'bg-purple-900 text-purple-200',
        complaint: 'bg-red-900 text-red-200',
        lead: 'bg-green-900 text-green-200',
        question: 'bg-yellow-900 text-yellow-200',
        general: 'bg-slate-700 text-slate-200'
    }
    return badges[intent] || badges.general
})
</script>

<template>
    <div class="p-4 border border-slate-700 rounded-lg hover:border-slate-600 cursor-pointer transition-all hover:bg-slate-700/50"
        @click="emit('click')">
        <div class="flex justify-between items-start mb-2">
            <div class="flex-1">
                <p class="font-medium text-white">{{ comment.author_name }}</p>
                <p class="text-xs text-slate-400">{{ comment.socialAccount.platform }}</p>
            </div>
            <div class="flex gap-2">
                <span :class="['text-sm', sentimentColor]">{{ comment.sentiment }}</span>
                <span :class="['px-2 py-1 rounded text-xs font-medium', intentBadge]">
                    {{ comment.intent }}
                </span>
            </div>
        </div>

        <p class="text-slate-300 text-sm mb-3 line-clamp-2">{{ comment.content }}</p>

        <div class="flex justify-between items-center text-xs text-slate-500">
            <span>Lead Score: <span class="text-white font-bold">{{ comment.lead_score }}</span></span>
            <span>{{ new Date(comment.commented_at).toLocaleDateString() }}</span>
        </div>
    </div>
</template>