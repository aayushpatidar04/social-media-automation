<template>
    <AppLayout>
        <div class="max-w-4xl">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-white">Knowledge Base</h1>
                <LoadingButton
                    :loading="loading.upload"
                    :loading-text="'Uploading...'"
                    base-classes="bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium"
                    @click="showUpload = true"
                >
                    + Upload Document
                </LoadingButton>
            </div>

            <!-- Documents List -->
            <div class="grid gap-4">
                <div
                    v-for="source in sources"
                    :key="source.id"
                    class="bg-slate-800 rounded-lg border border-slate-700 p-6 flex justify-between items-center"
                >
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-white">
                            {{ source.name }}
                        </h3>
                        <p class="text-slate-400 text-sm">
                            {{ source.description }}
                        </p>
                        <div class="flex gap-4 mt-2 text-xs text-slate-500">
                            <span
                                >Type:
                                <span class="capitalize">{{
                                    source.type
                                }}</span></span
                            >
                            <span
                                v-if="source.is_indexed"
                                class="text-green-400"
                                >✓ Indexed</span
                            >
                            <span v-else class="text-yellow-400"
                                >⏳ Indexing...</span
                            >
                        </div>
                    </div>
                    <LoadingButton
                        :loading="loading.delete[source.id]"
                        :loading-text="'Deleting...'"
                        base-classes="bg-red-900 hover:bg-red-800 text-red-200 rounded"
                        @click="deleteSource(source.id)"
                    >
                        Delete
                    </LoadingButton>
                </div>

                <div
                    v-if="sources.length === 0"
                    class="bg-slate-800 rounded-lg border border-slate-700 p-12 text-center"
                >
                    <p class="text-slate-400 text-lg mb-4">
                        No documents uploaded yet
                    </p>
                    <LoadingButton
                        :loading="loading.upload"
                        :loading-text="'Uploading...'"
                        base-classes="bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium"
                        @click="showUpload = true"
                    >
                        Upload Your First Document
                    </LoadingButton>
                </div>
            </div>
        </div>

        <!-- Upload Modal -->
        <Modal v-model="showUpload" title="Upload Document">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2"
                        >Document Name</label
                    >
                    <input
                        v-model="uploadForm.name"
                        type="text"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2"
                        >Type</label
                    >
                    <select
                        v-model="uploadForm.type"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                    >
                        <option value="pdf">PDF</option>
                        <option value="docx">Word Document</option>
                        <option value="faq">FAQ</option>
                        <option value="script">Script</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2"
                        >File</label
                    >
                    <input
                        type="file"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                    />
                </div>
            </div>
            <template #footer>
                <LoadingButton
                    :loading="loading.upload"
                    :loading-text="'Uploading...'"
                    base-classes="bg-blue-600 hover:bg-blue-700 text-white rounded"
                    @click="uploadDocument"
                >
                    Upload
                </LoadingButton>
                <LoadingButton
                    base-classes="bg-slate-700 hover:bg-slate-600 text-white rounded"
                    @click="showUpload = false"
                >
                    Cancel
                </LoadingButton>
            </template>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import Modal from "@/Components/Modal.vue";
import { toast } from "@/composables/useToast";
import LoadingButton from "@/Components/LoadingButton.vue";

defineProps({
    sources: Array,
});

const showUpload = ref(false);
const loading = reactive({
    upload: false,
    delete: {},
});

const uploadForm = ref({
    name: "",
    type: "pdf",
});

const uploadDocument = () => {
    loading.upload = true;
    // Simulate upload
    setTimeout(() => {
        loading.upload = false;
        showUpload.value = false;
        toast.success("Document uploaded successfully");
        uploadForm.value = { name: "", type: "pdf" };
    }, 1500);
};

const deleteSource = (id) => {
    loading.delete[id] = true;
    setTimeout(() => {
        loading.delete[id] = false;
        toast.success("Document deleted");
    }, 1000);
};
</script>
