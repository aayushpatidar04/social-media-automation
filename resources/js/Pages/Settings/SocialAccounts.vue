<template>
    <AppLayout>
        <div class="max-w-4xl mx-auto">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-white">Social Accounts</h1>
                <div class="flex gap-4">
                    <a :href="facebookLoginUrl"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                        + Connect Facebook
                    </a>
                    <a :href="youtubeLoginUrl"
                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium">
                        + Connect YouTube
                    </a>
                    <a :href="twitterLoginUrl"
                        class="px-4 py-2 bg-black hover:bg-gray-800 text-white rounded-lg font-medium">
                        + Connect X
                    </a>
                    <a :href="linkedinLoginUrl"
                        class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-medium">
                        + Connect LinkedIn
                    </a>
                </div>
            </div>

            <!-- Connected Accounts -->
            <div class="grid gap-4">
                <div v-for="account in accounts" :key="account.id"
                    class="bg-slate-800 rounded-lg border border-slate-700 p-6">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <h3 class="text-lg font-bold text-white mb-2">{{ account.platform_account_name }}</h3>
                            <p class="text-slate-400 text-sm mb-4">
                                Platform: <span class="capitalize font-mono text-blue-400">{{ account.platform }}</span>
                            </p>
                            <div class="space-y-2 text-sm text-slate-400">
                                <p>Status: <span :class="getStatusClass(account.status)">{{ account.status }}</span></p>
                                <p v-if="account.last_synced_at">
                                    Last synced: {{ formatDate(account.last_synced_at) }}
                                </p>
                            </div>
                        </div>

                        <div class="flex gap-2">
                            <button v-if="account.platform === 'facebook' || account.platform === 'instagram'"
                                @click="syncNow(account.id)"
                                class="px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm">
                                Sync Now
                            </button>

                            <button v-if="account.platform === 'youtube'" @click="syncYoutube(account.id)"
                                class="px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm">
                                Sync Now
                            </button>
                            <button v-if="account.platform === 'twitter'" @click="syncTwitter(account.id)"
                                class="px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm">
                                Sync Now
                            </button>
                            <button v-if="account.platform === 'linkedin'" @click="syncLinkedIn(account.id)"
                                class="px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded text-sm">
                                Sync LinkedIn
                            </button>
                            <button @click="disconnect(account.id)"
                                class="px-3 py-2 bg-red-900 hover:bg-red-800 text-red-200 rounded text-sm">
                                Disconnect
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="accounts.length === 0"
                    class="bg-slate-800 rounded-lg border border-slate-700 p-5 mb-2 text-center">
                    <p class="text-slate-400 text-lg mb-4">No social accounts connected yet</p>
                    <a :href="facebookLoginUrl"
                        class="inline-block px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                        Connect Your First Account
                    </a>
                </div>
            </div>

            <!-- Supported Platforms -->
            <div class="mt-12 bg-slate-800 rounded-lg border border-slate-700 p-6">
                <h3 class="text-xl font-bold text-white mb-4">Supported Platforms</h3>
                <div class="grid md:grid-cols-2 gap-4 text-sm text-slate-300">
                    <div>✅ Facebook (Connected)</div>
                    <div>✅ Instagram (Connected)</div>
                    <div>🔲 YouTube (Coming Soon)</div>
                    <div>🔲 Twitter/X (Coming Soon)</div>
                    <div>🔲 LinkedIn (Coming Soon)</div>
                    <div>🔲 TikTok (Coming Soon)</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import axios from 'axios'

const props = defineProps({
    accounts: Array,
    facebook_login_url: String,
    available_platforms: Array,
})

const facebookLoginUrl = ref(props.facebook_login_url || '#')
const youtubeLoginUrl = '/auth/youtube/login'
const twitterLoginUrl = '/auth/twitter/login'
const linkedinLoginUrl = '/auth/linkedin/login'

const syncLinkedIn = async (accountId) => {
    try {
        const response = await axios.post(`/settings/social-accounts/${accountId}/linkedin-sync`)
        console.log(response.data.message)
    } catch (error) {
        console.error('LinkedIn sync failed:', error.response?.data || error.message)
    }
}

const syncNow = async (accountId) => {
    try {
        const response = await axios.post(`/settings/social-accounts/${accountId}/sync`)
        console.log(response.data.message) // "Sync job queued"
    } catch (error) {
        console.error('Sync failed:', error.response?.data || error.message)
    }
}

const syncYoutube = async (accountId) => {
    try {
        const response = await axios.post(`/settings/social-accounts/${accountId}/youtube-sync`)
        console.log(response.data.message)
    } catch (error) {
        console.error('YouTube sync failed:', error.response?.data || error.message)
    }
}

const syncTwitter = async (accountId) => {
    try {
        const response = await axios.post(`/settings/social-accounts/${accountId}/twitter-sync`)
        console.log(response.data.message)
    } catch (error) {
        console.error('X sync failed:', error.response?.data || error.message)
    }
}

const disconnect = async (accountId) => {
    if (confirm('Are you sure you want to disconnect this account?')) {
        try {
            const response = await axios.post(`/settings/social-accounts/${accountId}/disconnect`)
            console.log(response.data.message) // "Account disconnected"
        } catch (error) {
            console.error('Disconnect failed:', error.response?.data || error.message)
        }
    }
}

const getStatusClass = (status) => {
    const classes = {
        connected: 'text-green-400',
        disconnected: 'text-red-400',
        expired: 'text-yellow-400',
        error: 'text-red-400',
    }
    return classes[status] || 'text-slate-400'
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString()
}
</script>