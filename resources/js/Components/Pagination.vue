<!-- resources/js/Components/Pagination.vue -->
<template>
    <div class="flex items-center justify-center gap-2 mt-8">
        <button @click="previousPage" :disabled="currentPage === 1"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-white rounded">
            Previous
        </button>

        <span class="text-slate-300">
            Page {{ currentPage }} of {{ totalPages }}
        </span>

        <button @click="nextPage" :disabled="currentPage === totalPages"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-white rounded">
            Next
        </button>
    </div>
</template>

<script setup>
const props = defineProps({
    currentPage: Number,
    totalPages: Number,
})

const emit = defineEmits(['update'])

const previousPage = () => {
    if (props.currentPage > 1) {
        emit('update', props.currentPage - 1)
    }
}

const nextPage = () => {
    if (props.currentPage < props.totalPages) {
        emit('update', props.currentPage + 1)
    }
}
</script>