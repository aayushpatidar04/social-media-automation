<template>
    <AppLayout>
        <div class="max-w-4xl">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-white">Knowledge Base</h1>
                <button @click="showUpload = true"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                    + Upload Document
                </button>
            </div>

            <!-- Documents List -->
            <div class="grid gap-4">
                <div v-for="source in sources" :key="source.id"
                    class="bg-slate-800 rounded-lg border border-slate-700 p-6 flex justify-between items-center">
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-white">{{ source.name }}</h3>
                        <p class="text-slate-400 text-sm">{{ source.description }}</p>
                        <div class="flex gap-4 mt-2 text-xs text-slate-500">
                            <span>Type: <span class="capitalize">{{ source.type }}</span></span>
                            <span v-if="source.is_indexed" class="text-green-400">✓ Indexed</span>
                            <span v-else class="text-yellow-400">⏳ Indexing...</span>
                        </div>
                    </div>
                    <button @click="deleteSource(source.id)"
                        class="px-4 py-2 bg-red-900 hover:bg-red-800 text-red-200 rounded">
                        Delete
                    </button>
                </div>

                <div v-if="sources.length === 0"
                    class="bg-slate-800 rounded-lg border border-slate-700 p-12 text-center">
                    <p class="text-slate-400 text-lg mb-4">No documents uploaded yet</p>
                    <button @click="showUpload = true"
                        class="inline-block px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                        Upload Your First Document
                    </button>
                </div>
            </div>
        </div>

        <!-- Upload Modal -->
        <Modal v-model="showUpload" title="Upload Document">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Document Name</label>
                    <input v-model="uploadForm.name" type="text"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Type</label>
                    <select v-model="uploadForm.type"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                        <option value="pdf">PDF</option>
                        <option value="docx">Word Document</option>
                        <option value="faq">FAQ</option>
                        <option value="script">Script</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">File</label>
                    <input type="file"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />
                </div>
            </div>
            <template #footer>
                <button @click="uploadDocument" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">
                    Upload
                </button>
                <button @click="showUpload = false"
                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded">
                    Cancel
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'

defineProps({
    sources: Array,
})

const showUpload = ref(false)
const uploadForm = ref({
    name: '',
    type: 'pdf',
})

const uploadDocument = () => {
    console.log('Uploading:', uploadForm.value)
    showUpload.value = false
}

const deleteSource = (id) => {
    if (confirm('Delete this document?')) {
        console.log('Deleting:', id)
    }
}
</script>