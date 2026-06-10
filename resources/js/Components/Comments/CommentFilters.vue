<!-- resources/js/Components/Comments/CommentFilters.vue -->
<template>
    <div class="bg-slate-800 rounded-lg border border-slate-700 p-6 space-y-4">
        <h3 class="font-bold text-white">Filters</h3>

        <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">Search</label>
            <input :value="filters.search" @input="updateFilter('search', $event.target.value)" type="text"
                placeholder="Search..."
                class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400" />
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">Status</label>
            <select :value="filters.status" @change="updateFilter('status', $event.target.value)"
                class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                <option value="">All</option>
                <option value="new">New</option>
                <option value="reviewed">Reviewed</option>
                <option value="replied">Replied</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">Sentiment</label>
            <select :value="filters.sentiment" @change="updateFilter('sentiment', $event.target.value)"
                class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                <option value="">All</option>
                <option value="positive">Positive</option>
                <option value="neutral">Neutral</option>
                <option value="negative">Negative</option>
            </select>
        </div>

        <button @click="clearFilters" class="w-full px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded">
            Clear Filters
        </button>
    </div>
</template>

<script setup>
const props = defineProps({
    initialFilters: Object,
})

const emit = defineEmits(['update'])

const filters = ref(props.initialFilters || {})

const updateFilter = (key, value) => {
    filters.value[key] = value
    emit('update', filters.value)
}

const clearFilters = () => {
    filters.value = {}
    emit('update', filters.value)
}
</script>