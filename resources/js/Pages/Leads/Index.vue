<template>
    <AppLayout>
        <div class="max-w-7xl">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">Leads</h1>
                <p class="text-slate-400">
                    Commenters identified as potential leads by AI
                </p>
            </div>

            <!-- Stats Row -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div
                    class="bg-slate-800 rounded-lg border border-slate-700 p-4"
                >
                    <p class="text-slate-400 text-sm mb-1">Total Leads</p>
                    <p class="text-2xl font-bold text-white">
                        {{ leads.total }}
                    </p>
                </div>
                <div
                    class="bg-slate-800 rounded-lg border border-slate-700 p-4"
                >
                    <p class="text-slate-400 text-sm mb-1">New</p>
                    <p class="text-2xl font-bold text-yellow-400">
                        {{ stats.new }}
                    </p>
                </div>
                <div
                    class="bg-slate-800 rounded-lg border border-slate-700 p-4"
                >
                    <p class="text-slate-400 text-sm mb-1">Contacted</p>
                    <p class="text-2xl font-bold text-blue-400">
                        {{ stats.contacted }}
                    </p>
                </div>
                <div
                    class="bg-slate-800 rounded-lg border border-slate-700 p-4"
                >
                    <p class="text-slate-400 text-sm mb-1">Converted</p>
                    <p class="text-2xl font-bold text-green-400">
                        {{ stats.converted }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Filters -->
                <div class="lg:col-span-1">
                    <div
                        class="bg-slate-800 rounded-lg border border-slate-700 p-4 space-y-4"
                    >
                        <div>
                            <label
                                class="block text-sm font-medium text-slate-300 mb-2"
                                >Status</label
                            >
                            <select
                                v-model="filters.status"
                                @change="applyFilters"
                                class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white text-sm"
                            >
                                <option value="">All Status</option>
                                <option value="new">New</option>
                                <option value="contacted">Contacted</option>
                                <option value="qualified">Qualified</option>
                                <option value="converted">Converted</option>
                                <option value="lost">Lost</option>
                            </select>
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-slate-300 mb-2"
                                >Type</label
                            >
                            <select
                                v-model="filters.type"
                                @change="applyFilters"
                                class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white text-sm"
                            >
                                <option value="">All Types</option>
                                <option value="sales">Sales</option>
                                <option value="support">Support</option>
                                <option value="partnership">Partnership</option>
                            </select>
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-slate-300 mb-2"
                                >Search</label
                            >
                            <input
                                v-model="filters.search"
                                @input="debouncedFilter"
                                type="text"
                                placeholder="Name, email, company..."
                                class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-white text-sm placeholder-slate-400"
                            />
                        </div>
                    </div>
                </div>

                <!-- Leads List -->
                <div class="lg:col-span-3">
                    <div v-if="loading" class="text-center py-12">
                        <div
                            class="inline-block w-8 h-8 border-4 border-slate-600 border-t-blue-500 rounded-full animate-spin"
                        ></div>
                        <p class="text-slate-400 mt-4">Loading leads...</p>
                    </div>

                    <div
                        v-else-if="leads.data.length === 0"
                        class="bg-slate-800 rounded-lg border border-slate-700 p-12 text-center"
                    >
                        <p class="text-slate-400 text-lg mb-2">
                            No leads found
                        </p>
                        <p class="text-slate-500 text-sm">
                            Leads are auto-created when AI detects sales intent
                            or high-value comments.
                        </p>
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            v-for="lead in leads.data"
                            :key="lead.id"
                            class="bg-slate-800 rounded-lg border border-slate-700 p-6 hover:border-slate-600 transition-colors"
                        >
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <h3
                                            class="text-lg font-bold text-white"
                                        >
                                            {{ lead.author_name }}
                                        </h3>
                                        <span
                                            :class="[
                                                'px-2 py-1 rounded text-xs font-medium',
                                                getTypeColor(lead.lead_type),
                                            ]"
                                        >
                                            {{ lead.lead_type }}
                                        </span>
                                        <span
                                            :class="[
                                                'px-2 py-1 rounded text-xs font-medium',
                                                getStatusColor(
                                                    lead.lead_status,
                                                ),
                                            ]"
                                        >
                                            {{ lead.lead_status }}
                                        </span>
                                    </div>

                                    <div
                                        class="flex items-center gap-4 text-sm text-slate-400 mb-3"
                                    >
                                        <span v-if="lead.company_name"
                                            >🏢 {{ lead.company_name }}</span
                                        >
                                        <span v-if="lead.contact_email"
                                            >✉️ {{ lead.contact_email }}</span
                                        >
                                        <span v-if="lead.contact_phone"
                                            >📞 {{ lead.contact_phone }}</span
                                        >
                                        <span
                                            >⭐ Score:
                                            <span
                                                class="text-white font-bold"
                                                >{{ lead.lead_score }}</span
                                            ></span
                                        >
                                        <span v-if="lead.assignedTo"
                                            >👤 {{ lead.assignedTo.name }}</span
                                        >
                                    </div>

                                    <p
                                        class="text-slate-300 text-sm mb-2 line-clamp-2"
                                    >
                                        {{ lead.initial_message }}
                                    </p>

                                    <div
                                        v-if="lead.socialComment"
                                        class="text-xs text-slate-500"
                                    >
                                        <span class="capitalize">{{
                                            lead.socialComment.platform
                                        }}</span>
                                        comment
                                        <span
                                            v-if="
                                                lead.socialComment.socialAccount
                                            "
                                            >on
                                            {{
                                                lead.socialComment.socialAccount
                                                    .platform_account_name
                                            }}</span
                                        >
                                    </div>
                                </div>

                                <div class="flex flex-col items-end gap-2 ml-4">
                                    <button
                                        v-if="lead.lead_status === 'new'"
                                        @click="
                                            updateStatus(lead.id, 'contacted')
                                        "
                                        class="px-3 py-1.5 bg-blue-700 hover:bg-blue-600 text-white rounded text-xs"
                                    >
                                        Mark Contacted
                                    </button>
                                    <button
                                        v-if="lead.lead_status === 'contacted'"
                                        @click="
                                            updateStatus(lead.id, 'qualified')
                                        "
                                        class="px-3 py-1.5 bg-purple-700 hover:bg-purple-600 text-white rounded text-xs"
                                    >
                                        Mark Qualified
                                    </button>
                                    <button
                                        v-if="
                                            lead.lead_status !== 'converted' &&
                                            lead.lead_status !== 'lost'
                                        "
                                        @click="
                                            updateStatus(lead.id, 'converted')
                                        "
                                        class="px-3 py-1.5 bg-green-700 hover:bg-green-600 text-white rounded text-xs"
                                    >
                                        Mark Converted
                                    </button>
                                    <button
                                        v-if="lead.lead_status !== 'lost'"
                                        @click="updateStatus(lead.id, 'lost')"
                                        class="px-3 py-1.5 bg-red-900 hover:bg-red-800 text-red-200 rounded text-xs"
                                    >
                                        Mark Lost
                                    </button>
                                    <a
                                        :href="`/comments/${lead.socialComment?.id}`"
                                        v-if="lead.socialComment"
                                        class="text-xs text-blue-400 hover:text-blue-300"
                                    >
                                        View Comment →
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div
                            v-if="leads.last_page > 1"
                            class="mt-6 flex justify-center gap-2"
                        >
                            <button
                                v-for="page in leads.last_page"
                                :key="page"
                                @click="goToPage(page)"
                                :class="[
                                    'px-3 py-1 rounded text-sm',
                                    page === leads.current_page
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-slate-700 text-slate-300 hover:bg-slate-600',
                                ]"
                            >
                                {{ page }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";
import { router } from "@inertiajs/vue3";

const props = defineProps({
    leads: {
        type: Object,
        default: () => ({ data: [] }),
    },
    stats: {
        type: Object,
        default: () => ({ new: 0, contacted: 0, converted: 0 }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const loading = ref(false);
const filters = reactive({
    status: props.filters.status || "",
    type: props.filters.type || "",
    search: "",
});

let debounceTimer = null;

const debouncedFilter = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => applyFilters(), 300);
};

const applyFilters = () => {
    const params = {};
    if (filters.status) params.status = filters.status;
    if (filters.type) params.type = filters.type;
    if (filters.search) params.search = filters.search;

    router.get("/leads", params, {
        preserveState: true,
        preserveScroll: true,
    });
};

const goToPage = (page) => {
    const params = { page };
    if (filters.status) params.status = filters.status;
    if (filters.type) params.type = filters.type;
    if (filters.search) params.search = filters.search;

    router.get("/leads", params, {
        preserveState: true,
        preserveScroll: true,
    });
};

const updateStatus = async (leadId, status) => {
    try {
        await axios.post(`/leads/${leadId}/update-status`, { status });
        router.reload({ only: ["leads"] });
    } catch (error) {
        console.error(
            "Failed to update lead status:",
            error.response?.data || error.message,
        );
    }
};

const getStatusColor = (status) => {
    const colors = {
        new: "bg-yellow-900 text-yellow-200",
        contacted: "bg-blue-900 text-blue-200",
        qualified: "bg-purple-900 text-purple-200",
        converted: "bg-green-900 text-green-200",
        lost: "bg-red-900 text-red-200",
    };
    return colors[status] || "bg-slate-700 text-slate-200";
};

const getTypeColor = (type) => {
    const colors = {
        sales: "bg-blue-900 text-blue-200",
        support: "bg-purple-900 text-purple-200",
        partnership: "bg-emerald-900 text-emerald-200",
    };
    return colors[type] || "bg-slate-700 text-slate-200";
};

onMounted(() => {
    filters.status = props.filters.status || "";
    filters.type = props.filters.type || "";
});
</script>
