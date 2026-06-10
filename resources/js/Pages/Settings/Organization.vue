<template>
    <AppLayout>
        <div class="max-w-4xl">
            <h1 class="text-3xl font-bold text-white mb-8">Organization Settings</h1>

            <form @submit.prevent="save" class="bg-slate-800 rounded-lg border border-slate-700 p-8 space-y-6">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Organization Name</label>
                    <input v-model="form.name" type="text"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Description</label>
                    <textarea v-model="form.description" rows="4"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Website</label>
                    <input v-model="form.website" type="url"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Plan</label>
                    <select v-model="form.plan"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                        <option value="free">Free</option>
                        <option value="starter">Starter</option>
                        <option value="professional">Professional</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                </div>

                <button type="submit"
                    class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                    Save Changes
                </button>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    organization: Object,
})

const form = ref({
    name: '',
    description: '',
    website: '',
    plan: '',
})

const save = () => {
    console.log('Saving organization:', form.value)
}
</script>