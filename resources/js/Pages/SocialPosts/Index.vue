<template>
    <AppLayout>
        <div class="min-h-screen bg-slate-900">
            <div class="bg-slate-800 border-b border-slate-700 p-6">
                <div class="max-w-7xl mx-auto">
                    <h1 class="text-3xl font-bold text-white mb-2">📝 Social Posts</h1>
                    <p class="text-slate-400">Manage post-specific knowledge for AI replies.</p>
                </div>
            </div>

            <div class="bg-slate-800 border-b border-slate-700 p-6">
                <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-4 gap-4">
                    <input v-model="filters.search" @input="applyFilters" placeholder="Search posts..."
                        class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />

                    <select v-model="filters.platform" @change="applyFilters"
                        class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                        <option value="">All Platforms</option>
                        <option value="facebook">Facebook</option>
                        <option value="instagram">Instagram</option>
                        <option value="youtube">YouTube</option>
                        <option value="twitter">X/Twitter</option>
                        <option value="linkedin">LinkedIn</option>
                    </select>

                    <select v-model="filters.has_knowledge" @change="applyFilters"
                        class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                        <option value="">All Posts</option>
                        <option value="1">With Knowledge</option>
                        <option value="0">Without Knowledge</option>
                    </select>

                    <button @click="applyFilters" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                        Filter
                    </button>
                </div>
            </div>

            <div class="max-w-7xl mx-auto p-6">
                <div v-if="posts.length === 0" class="text-center py-12 text-slate-400">
                    No social posts found.
                </div>

                <div v-else class="space-y-4">
                    <div v-for="post in posts" :key="post.id"
                        class="bg-slate-800 border border-slate-700 rounded-lg p-6">
                        <div class="flex justify-between gap-4 mb-4">
                            <div style="max-width: 95%;">
                                <p class="text-slate-400 text-sm mb-1">
                                    <span class="capitalize font-mono">{{ platformLabel(post.platform) }}</span>
                                    · {{ formatDate(post.posted_at) }}
                                </p>
                                <h3 class="text-white text-lg font-semibold line-clamp-2">
                                    {{ post.content || 'No caption/content' }}
                                </h3>
                                <p class="text-slate-500 text-xs mt-1">{{ post.platform_post_id }}</p>
                            </div>

                            <span class="bg-slate-700 text-slate-200 text-center px-3 py-1 rounded-full text-xs h-fit">
                                {{ post.knowledge_sources?.length || 0 }} sources
                            </span>
                        </div>

                        <div class="border-t border-slate-700 pt-4">
                            <p class="text-slate-400 text-sm mb-2">Linked Knowledge</p>

                            <div v-if="post.knowledge_sources?.length" class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                <div v-for="source in post.knowledge_sources" :key="source.id"
                                    class="bg-slate-900 rounded p-3 flex justify-between gap-3">
                                    <div>
                                        <p class="text-white text-sm font-medium">{{ source.name }}</p>
                                        <p class="text-slate-500 text-xs uppercase">{{ source.type }} · {{ source.scope
                                            || 'global' }}</p>
                                    </div>

                                    <button @click="unlinkSource(post, source)"
                                        class="text-red-400 hover:text-red-300 text-xs">
                                        Unlink
                                    </button>
                                </div>
                            </div>

                            <p v-else class="text-slate-500 text-sm">No knowledge source linked.</p>
                        </div>

                        <div class="flex flex-wrap gap-2 mt-5">
                            <button @click="openAttachModal(post)"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                                Attach Existing Source
                            </button>

                            <button @click="openUploadForPost(post)"
                                class="px-4 py-2 bg-green-700 hover:bg-green-800 text-white text-sm rounded">
                                Upload Source For This Post
                            </button>

                            <button @click="openPostDetail(post)"
                                class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm rounded">
                                View Details
                            </button>
                        </div>
                    </div>

                    <div v-if="pagination.links?.length > 3" class="mt-8 flex flex-wrap gap-2 justify-center">
                        <button v-for="link in pagination.links" :key="link.label" v-html="link.label"
                            :disabled="!link.url" @click="goToPage(link.url)" :class="[
                                'px-4 py-2 rounded-lg text-sm',
                                link.active
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-slate-700 text-slate-300',
                                !link.url
                                    ? 'opacity-50 cursor-not-allowed'
                                    : 'hover:bg-slate-600'
                            ]" />
                    </div>
                </div>
            </div>

            <!-- Attach Existing Modal -->
            <div v-if="attachPost" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
                <div class="bg-slate-800 border border-slate-700 rounded-lg max-w-3xl w-full p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-white">Attach Knowledge Source</h2>
                        <button @click="attachPost = null" class="text-slate-400 text-2xl">×</button>
                    </div>

                    <div class="max-h-96 overflow-y-auto space-y-2">
                        <label v-for="source in sources" :key="source.id"
                            class="flex gap-3 bg-slate-900 rounded p-3 cursor-pointer">
                            <input type="checkbox" :value="source.id" v-model="selectedSourceIds" class="mt-1" />
                            <div>
                                <p class="text-white text-sm">{{ source.name }}</p>
                                <p class="text-slate-500 text-xs uppercase">{{ source.type }} · {{ source.scope ||
                                    'global' }}</p>
                            </div>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 mt-4">
                        <button @click="attachPost = null"
                            class="px-4 py-2 bg-slate-700 text-white rounded">Cancel</button>
                        <button @click="attachSources" class="px-4 py-2 bg-blue-600 text-white rounded">Attach</button>
                    </div>
                </div>
            </div>

            <!-- Upload For Post Modal -->
            <div v-if="uploadPost" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
                <div class="bg-slate-800 border border-slate-700 rounded-lg max-w-2xl w-full p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-white">Upload Source For Post</h2>
                        <button @click="uploadPost = null" class="text-slate-400 text-2xl">×</button>
                    </div>

                    <form @submit.prevent="uploadSourceForPost" class="space-y-4">
                        <input v-model="uploadForm.name" required placeholder="Source name"
                            class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded text-white" />

                        <textarea v-model="uploadForm.description" placeholder="Description"
                            class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded text-white"></textarea>

                        <select v-model="uploadForm.type" required
                            class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded text-white">
                            <option value="">Select Type</option>
                            <option value="pdf">PDF</option>
                            <option value="docx">DOCX</option>
                            <option value="faq">FAQ</option>
                            <option value="script">Script</option>
                            <option value="policy">Policy</option>
                            <option value="template">Template</option>
                            <option value="brochure">Brochure</option>
                        </select>

                        <input type="file" @change="handleUploadFile"
                            class="w-full text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:bg-blue-600 file:text-white" />

                        <div class="flex justify-end gap-2">
                            <button type="button" @click="uploadPost = null"
                                class="px-4 py-2 bg-slate-700 text-white rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-green-700 hover:bg-green-800 text-white rounded">
                                Upload & Link
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Detail Modal -->
            <div v-if="detailPost" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
                <div
                    class="bg-slate-800 border border-slate-700 rounded-lg max-w-4xl w-full p-6 max-h-[90vh] overflow-y-auto">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h2 class="text-xl font-bold text-white">Post Detail</h2>
                            <p class="text-slate-400 text-sm">{{ platformLabel(detailPost.platform) }} · {{
                                detailPost.platform_post_id }}</p>
                        </div>
                        <button @click="detailPost = null" class="text-slate-400 text-2xl">×</button>
                    </div>

                    <div class="bg-slate-900 rounded p-4 mb-4">
                        <p class="text-white whitespace-pre-line">{{ detailPost.content || 'No content' }}</p>
                    </div>

                    <h3 class="text-white font-semibold mb-2">Linked Knowledge Sources</h3>
                    <div class="space-y-2">
                        <div v-for="source in detailPost.knowledge_sources || []" :key="source.id"
                            class="bg-slate-900 rounded p-3">
                            <p class="text-white">{{ source.name }}</p>
                            <p class="text-slate-500 text-sm">{{ source.description }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const page = usePage()

const posts = computed(() => page.props.posts.data || [])
const pagination = computed(() => page.props.posts)
const sources = computed(() => page.props.sources || [])
const currentFilters = computed(() => page.props.filters || {})

const filters = ref({
    search: currentFilters.value.search || '',
    platform: currentFilters.value.platform || '',
    has_knowledge: currentFilters.value.has_knowledge || '',
})

const attachPost = ref(null)
const uploadPost = ref(null)
const detailPost = ref(null)
const selectedSourceIds = ref([])

const uploadForm = ref({
    name: '',
    description: '',
    type: '',
    file: null,
})

const applyFilters = () => {
    router.get('/social-posts', filters.value, {
        preserveState: true,
        replace: true,
    })
}

const openAttachModal = (post) => {
    attachPost.value = post
    selectedSourceIds.value = post.knowledge_sources?.map(source => source.id) || []
}

const attachSources = () => {
    router.post(`/social-posts/${attachPost.value.id}/knowledge-sources`, {
        source_ids: selectedSourceIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            attachPost.value = null
        },
    })
}

const goToPage = (url) => {
    if (!url) return

    router.visit(url, {
        preserveState: true,
        preserveScroll: true,
    })
}

const unlinkSource = (post, source) => {
    router.delete(`/social-posts/${post.id}/knowledge-sources/${source.id}`, {
        preserveScroll: true,
    })
}

const openUploadForPost = (post) => {
    uploadPost.value = post
}

const handleUploadFile = (event) => {
    uploadForm.value.file = event.target.files[0]
}

const uploadSourceForPost = () => {
    router.post(`/social-posts/${uploadPost.value.id}/knowledge-sources/upload`, {
        ...uploadForm.value,
        scope: 'post_specific',
    }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadPost.value = null
            uploadForm.value = {
                name: '',
                description: '',
                type: '',
                file: null,
            }
        },
    })
}

const openPostDetail = (post) => {
    detailPost.value = post
}

const formatDate = (date) => {
    if (!date) return ''
    return new Date(date).toLocaleDateString() + ' ' + new Date(date).toLocaleTimeString()
}

const platformLabel = (platform) => {
    const labels = {
        facebook: 'Facebook',
        instagram: 'Instagram',
        youtube: 'YouTube',
        twitter: 'X/Twitter',
        linkedin: 'LinkedIn',
    }

    return labels[platform] || platform
}
</script>