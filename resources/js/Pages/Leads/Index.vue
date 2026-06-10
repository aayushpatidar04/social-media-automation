<template>
    <AppLayout>
        <div class="max-w-7xl">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">Leads</h1>
                <p class="text-slate-400">Manage and track your sales leads</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Filters -->
                <div class="lg:col-span-1">
                    <div class="bg-slate-800 rounded-lg border border-slate-700 p-4 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Status</label>
                            <select
                                class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white text-sm">
                                <option value="">All Status</option>
                                <option value="new">New</option>
                                <option value="contacted">Contacted</option>
                                <option value="qualified">Qualified</option>
                                <option value="converted">Converted</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Type</label>
                            <select
                                class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white text-sm">
                                <option value="">All Types</option>
                                <option value="sales">Sales</option>
                                <option value="support">Support</option>
                                <option value="partnership">Partnership</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Leads List -->
                <div class="lg:col-span-3">
                    <div class="space-y-4">
                        <div v-if="props.leads && props.leads.length">
                            <div v-for="lead in props.leads" :key="lead.id"
                                class="bg-slate-800 rounded-lg border border-slate-700 p-6 hover:border-slate-600 cursor-pointer transition-colors">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <h3 class="text-lg font-bold text-white">{{ lead.author_name }}</h3>
                                        <p class="text-slate-400 text-sm">{{ lead.company_name || 'N/A' }}</p>
                                        <p class="text-slate-500 text-sm mt-2">{{ lead.initial_message }}</p>
                                    </div>
                                    <div class="flex flex-col items-end gap-2">
                                        <span
                                            :class="['px-3 py-1 rounded text-sm font-medium', getStatusColor(lead.lead_status)]">
                                            {{ lead.lead_status }}
                                        </span>
                                        <span class="text-sm font-bold text-white">Score: {{ lead.lead_score }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="leads.length === 0"
                            class="bg-slate-800 rounded-lg border border-slate-700 p-12 text-center">
                            <p class="text-slate-400">No leads found</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    leads: {
        type: Array,
        default: () => []
    }
})

const getStatusColor = (status) => {
    const colors = {
        new: 'bg-yellow-900 text-yellow-200',
        contacted: 'bg-blue-900 text-blue-200',
        qualified: 'bg-purple-900 text-purple-200',
        converted: 'bg-green-900 text-green-200',
    }
    return colors[status] || 'bg-slate-700 text-slate-200'
}
</script>