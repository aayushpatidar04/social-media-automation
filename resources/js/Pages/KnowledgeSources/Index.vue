<template>
  <AppLayout>
    <div class="min-h-screen bg-slate-900">
      <div class="bg-slate-800 border-b border-slate-700 p-6">
        <div class="max-w-7xl mx-auto flex justify-between items-start">
          <div>
            <h1 class="text-3xl font-bold text-white mb-2">📚 Knowledge Sources</h1>
            <p class="text-slate-400">Upload and link knowledge sources for AI replies.</p>
          </div>

          <button @click="showUploadModal = true" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
            Upload Source
          </button>
        </div>
      </div>

      <div class="max-w-7xl mx-auto p-6">
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-4 mb-6">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <input v-model="filters.search" @input="applyFilters" placeholder="Search sources..."
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />

            <select v-model="filters.type" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
              <option value="">All Types</option>
              <option value="pdf">PDF</option>
              <option value="docx">DOCX</option>
              <option value="faq">FAQ</option>
              <option value="script">Script</option>
              <option value="policy">Policy</option>
              <option value="template">Template</option>
              <option value="brochure">Brochure</option>
            </select>

            <select v-model="filters.scope" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
              <option value="">All Scope</option>
              <option value="global">Global</option>
              <option value="post_specific">Post Specific</option>
            </select>

            <select v-model="filters.indexed" @change="applyFilters"
              class="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
              <option value="">All Status</option>
              <option value="1">Indexed</option>
              <option value="0">Not Indexed</option>
            </select>
          </div>
        </div>

        <div v-if="sources.length === 0" class="text-center py-12 text-slate-400">
          No knowledge sources found.
        </div>

        <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div v-for="source in sources" :key="source.id" class="bg-slate-800 border border-slate-700 rounded-lg p-5">
            <div class="flex justify-between gap-4">
              <div>
                <h3 class="text-lg font-bold text-white">{{ source.name }}</h3>
                <p class="text-slate-400 text-sm mt-1">{{ source.description || 'No description' }}</p>
              </div>

              <span :class="source.is_indexed ? 'bg-green-900 text-green-200' : 'bg-yellow-900 text-yellow-200'"
                class="px-3 py-1 rounded-full text-xs h-fit">
                {{ source.is_indexed ? 'Indexed' : 'Not Indexed' }}
              </span>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 text-sm">
              <div>
                <p class="text-slate-500">Type</p>
                <p class="text-white uppercase">{{ source.type }}</p>
              </div>
              <div>
                <p class="text-slate-500">Scope</p>
                <p class="text-white">{{ source.scope || 'global' }}</p>
              </div>
              <div>
                <p class="text-slate-500">Chunks</p>
                <p class="text-white">{{ source.total_chunks }}</p>
              </div>
              <div>
                <p class="text-slate-500">File</p>
                <p class="text-white">{{ formatSize(source.file_size) }}</p>
              </div>
            </div>

            <div class="mt-4 border-t border-slate-700 pt-4">
              <p class="text-slate-400 text-sm mb-2">Linked Posts</p>
              <div v-if="source.social_posts?.length" class="space-y-2">
                <div v-for="post in source.social_posts" :key="post.id"
                  class="flex justify-between items-center bg-slate-900 rounded p-2">
                  <span class="text-slate-300 text-sm line-clamp-1">
                    {{ post.platform }} · {{ post.content || post.platform_post_id }}
                  </span>
                  <button @click="unlinkPost(source, post)" class="text-red-400 hover:text-red-300 text-xs">
                    Unlink
                  </button>
                </div>
              </div>
              <p v-else class="text-slate-500 text-sm">No posts linked.</p>
            </div>

            <div class="flex flex-wrap gap-2 mt-4">
              <button @click="openLinkModal(source)"
                class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                Link Posts
              </button>

              <button @click="reindexSource(source)"
                class="px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm rounded">
                Re-index
              </button>

              <button @click="deleteSource(source)"
                class="px-3 py-2 bg-red-700 hover:bg-red-800 text-white text-sm rounded">
                Delete
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Upload Modal -->
      <div v-if="showUploadModal" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-lg max-w-2xl w-full p-6">
          <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-white">Upload Knowledge Source</h2>
            <button @click="showUploadModal = false" class="text-slate-400 text-2xl">×</button>
          </div>

          <form @submit.prevent="uploadSource" class="space-y-4">
            <input v-model="form.name" required placeholder="Source name"
              class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded text-white" />

            <textarea v-model="form.description" placeholder="Description"
              class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded text-white"></textarea>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <select v-model="form.type" required
                class="px-4 py-2 bg-slate-700 border border-slate-600 rounded text-white">
                <option value="">Select Type</option>
                <option value="pdf">PDF</option>
                <option value="docx">DOCX</option>
                <option value="faq">FAQ</option>
                <option value="script">Script</option>
                <option value="policy">Policy</option>
                <option value="template">Template</option>
                <option value="brochure">Brochure</option>
              </select>

              <select v-model="form.scope" required
                class="px-4 py-2 bg-slate-700 border border-slate-600 rounded text-white">
                <option value="global">Global</option>
                <option value="post_specific">Post Specific</option>
              </select>
            </div>

            <div v-if="form.scope === 'post_specific'" class="space-y-2">
              <p class="text-slate-300 text-sm font-medium">
                Select posts for this source
              </p>

              <input v-model="postSearch" type="text" placeholder="Search posts by platform, ID, or content..."
                class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:border-blue-500" />

              <div class="max-h-60 overflow-y-auto bg-slate-900 border border-slate-700 rounded-lg p-3 space-y-2">
                <label v-for="post in filteredPostsForSource" :key="post.id"
                  class="flex gap-3 items-start bg-slate-800 rounded p-3 cursor-pointer hover:bg-slate-700">
                  <input type="checkbox" :value="post.id" v-model="form.post_ids" class="mt-1" />

                  <div>
                    <p class="text-white text-sm">
                      {{ post.platform }} · {{ post.platform_post_id }}
                    </p>
                    <p class="text-slate-400 text-xs line-clamp-2">
                      {{ post.content || 'No content' }}
                    </p>
                  </div>
                </label>

                <p v-if="filteredPostsForSource.length === 0" class="text-slate-500 text-sm text-center py-4">
                  No posts found.
                </p>
              </div>

              <p v-if="form.post_ids.length === 0" class="text-yellow-400 text-xs">
                Select at least one post for post-specific knowledge.
              </p>
            </div>

            <input type="file" @change="handleFile"
              class="w-full text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:bg-blue-600 file:text-white" />

            <div class="flex justify-end gap-2">
              <button type="button" @click="showUploadModal = false" class="px-4 py-2 bg-slate-700 text-white rounded">
                Cancel
              </button>
              <button type="submit"
                :disabled="uploading || (form.scope === 'post_specific' && form.post_ids.length === 0)"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded">
                {{ uploading ? 'Uploading...' : 'Upload' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Link Modal -->
      <div v-if="linkingSource" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
        <div class="bg-slate-800 border border-slate-700 rounded-lg max-w-3xl w-full p-6">
          <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-white">Link Posts</h2>
            <button @click="linkingSource = null" class="text-slate-400 text-2xl">×</button>
          </div>

          <div class="max-h-96 overflow-y-auto space-y-2">
            <label v-for="post in posts" :key="post.id"
              class="flex gap-3 items-start bg-slate-900 rounded p-3 cursor-pointer">
              <input type="checkbox" :value="post.id" v-model="selectedPostIds" class="mt-1" />
              <div>
                <p class="text-white text-sm">{{ post.platform }} · {{ post.platform_post_id }}</p>
                <p class="text-slate-400 text-sm line-clamp-2">{{ post.content || 'No content' }}</p>
              </div>
            </label>
          </div>

          <div class="flex justify-end gap-2 mt-4">
            <button @click="linkingSource = null" class="px-4 py-2 bg-slate-700 text-white rounded">Cancel</button>
            <button @click="linkPosts" class="px-4 py-2 bg-blue-600 text-white rounded">Save Links</button>
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

const sources = computed(() => page.props.sources?.data || page.props.sources || [])
const posts = computed(() => page.props.posts || [])
const currentFilters = computed(() => page.props.filters || {})

const showUploadModal = ref(false)
const linkingSource = ref(null)
const selectedPostIds = ref([])
const uploading = ref(false)

const filters = ref({
  search: currentFilters.value.search || '',
  type: currentFilters.value.type || '',
  scope: currentFilters.value.scope || '',
  indexed: currentFilters.value.indexed || '',
})

const form = ref({
  name: '',
  description: '',
  type: '',
  scope: 'global',
  file: null,
  post_ids: [],
})

const applyFilters = () => {
  router.get('/knowledge-sources', filters.value, {
    preserveState: true,
    replace: true,
  })
}

const handleFile = (event) => {
  form.value.file = event.target.files[0]
}

const uploadSource = () => {
  uploading.value = true

  router.post('/knowledge-sources', form.value, {
    forceFormData: true,
    onSuccess: () => {
      showUploadModal.value = false
      form.value = {
        name: '',
        description: '',
        type: '',
        scope: 'global',
        file: null,
        post_ids: [],
      }
    },
    onFinish: () => {
      uploading.value = false
    },
  })
}

const openLinkModal = (source) => {
  linkingSource.value = source
  selectedPostIds.value = source.social_posts?.map(post => post.id) || []
}

const linkPosts = () => {
  router.post(`/knowledge-sources/${linkingSource.value.id}/link-posts`, {
    post_ids: selectedPostIds.value,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      linkingSource.value = null
    },
  })
}

const unlinkPost = (source, post) => {
  router.delete(`/knowledge-sources/${source.id}/posts/${post.id}`, {
    preserveScroll: true,
  })
}

const reindexSource = (source) => {
  router.post(`/knowledge-sources/${source.id}/reindex`, {}, {
    preserveScroll: true,
  })
}

const deleteSource = (source) => {
  if (!confirm('Delete this knowledge source?')) return

  router.delete(`/knowledge-sources/${source.id}`, {
    preserveScroll: true,
  })
}

const formatSize = (bytes) => {
  if (!bytes) return '0 KB'
  return `${(bytes / 1024).toFixed(1)} KB`
}

const postSearch = ref('')

const filteredPostsForSource = computed(() => {
  const search = postSearch.value.toLowerCase().trim()

  if (!search) {
    return posts.value
  }

  return posts.value.filter(post => {
    return (
      String(post.platform || '').toLowerCase().includes(search) ||
      String(post.platform_post_id || '').toLowerCase().includes(search) ||
      String(post.content || '').toLowerCase().includes(search)
    )
  })
})
</script>