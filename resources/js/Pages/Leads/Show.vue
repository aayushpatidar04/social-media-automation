<template>
    <AppLayout>
        <div class="max-w-4xl">
            <a href="/leads" class="text-blue-400 hover:text-blue-300 mb-8 inline-block">← Back to Leads</a>

            <div class="bg-slate-800 rounded-lg border border-slate-700 p-8">
                <h1 class="text-3xl font-bold text-white mb-2">{{ lead.author_name }}</h1>
                <p class="text-slate-400 mb-6">Lead Score: <span class="text-white font-bold">{{ lead.lead_score
                        }}</span></p>

                <div class="grid md:grid-cols-2 gap-8">
                    <!-- Details -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Email</label>
                            <p class="text-white">{{ lead.contact_email || 'N/A' }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Phone</label>
                            <p class="text-white">{{ lead.contact_phone || 'N/A' }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Company</label>
                            <p class="text-white">{{ lead.company_name || 'N/A' }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Status</label>
                            <select class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                                <option :value="lead.lead_status" selected>{{ lead.lead_status }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Message -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Initial Message</label>
                        <div class="bg-slate-700 rounded p-4 text-slate-300 min-h-40">
                            {{ lead.initial_message }}
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="mt-8">
                    <label class="block text-sm font-medium text-slate-300 mb-2">Notes</label>
                    <textarea rows="6"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">{{ lead.notes }}</textarea>
                </div>

                <button class="mt-6 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                    Update Lead
                </button>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    lead: Object,
})
</script>