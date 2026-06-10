<template>
    <AppLayout>
        <div class="max-w-4xl">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-white">Team Members</h1>
                <button @click="showInvite = true"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                    + Invite Member
                </button>
            </div>

            <!-- Team Members List -->
            <div class="grid gap-4">
                <div v-for="user in teamMembers" :key="user.id"
                    class="bg-slate-800 rounded-lg border border-slate-700 p-6 flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-bold text-white">{{ user.name }}</h3>
                        <p class="text-slate-400 text-sm">{{ user.email }}</p>
                        <p class="text-slate-500 text-xs mt-1">Role: <span class="capitalize">{{ user.role }}</span></p>
                    </div>
                    <button v-if="user.id !== currentUserId" @click="removeMember(user.id)"
                        class="px-4 py-2 bg-red-900 hover:bg-red-800 text-red-200 rounded">
                        Remove
                    </button>
                </div>
            </div>
        </div>

        <!-- Invite Modal -->
        <Modal v-model="showInvite" title="Invite Team Member">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Email</label>
                    <input v-model="inviteForm.email" type="email"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Role</label>
                    <select v-model="inviteForm.role"
                        class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                        <option value="team_member">Team Member</option>
                        <option value="manager">Manager</option>
                    </select>
                </div>
            </div>
            <template #footer>
                <button @click="sendInvite" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">
                    Send Invite
                </button>
                <button @click="showInvite = false"
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
    teamMembers: Array,
    currentUserId: Number,
})

const showInvite = ref(false)
const inviteForm = ref({
    email: '',
    role: 'team_member',
})

const sendInvite = () => {
    console.log('Sending invite:', inviteForm.value)
    showInvite.value = false
}

const removeMember = (userId) => {
    if (confirm('Remove this team member?')) {
        console.log('Removing member:', userId)
    }
}
</script>