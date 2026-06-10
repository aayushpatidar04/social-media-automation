<script setup>
import { ref } from 'vue'
import CommentCard from './CommentCard.vue'

defineProps({
    comments: {
        type: Array,
        required: true
    }
})

const selectedComment = ref(null)
</script>

<template>
    <div class="dashboard-card">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-white">Recent Comments</h2>
            <a href="/inbox" class="text-sm text-blue-400 hover:text-blue-300">View All</a>
        </div>

        <div class="space-y-4">
            <CommentCard v-for="comment in comments.slice(0, 5)" :key="comment.id" :comment="comment"
                @click="selectedComment = comment" />
        </div>
    </div>
</template>