<!-- resources/js/Components/Comments/CommentList.vue -->
<template>
    <div class="space-y-4">
        <div v-for="comment in comments" :key="comment.id" @click="$emit('select', comment)"
            class="bg-slate-800 rounded-lg border border-slate-700 p-4 hover:border-slate-600 cursor-pointer transition-all"
            :class="{ 'ring-2 ring-blue-500': selected?.id === comment.id }">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <p class="font-semibold text-white">{{ comment.author_name }}</p>
                    <p class="text-xs text-slate-400">{{ formatDate(comment.commented_at) }}</p>
                </div>
                <div class="flex gap-2">
                    <Badge :variant="getSentimentVariant(comment.sentiment)">
                        {{ comment.sentiment }}
                    </Badge>
                    <Badge :variant="getIntentVariant(comment.intent)">
                        {{ comment.intent }}
                    </Badge>
                </div>
            </div>
            <p class="text-slate-300 text-sm line-clamp-2">{{ comment.content }}</p>
        </div>
    </div>
</template>

<script setup>
import Badge from '@/Components/Badge.vue'

defineProps({
    comments: Array,
    selected: Object,
})

defineEmits(['select'])

const getSentimentVariant = (sentiment) => {
    const variants = {
        positive: 'success',
        neutral: 'info',
        negative: 'danger',
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
    }
    return variants[intent] || 'info'
}

const formatDate = (date) => {
    const d = new Date(date)
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}
</script>