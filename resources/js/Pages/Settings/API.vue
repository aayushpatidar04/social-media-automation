<template>
    <AppLayout>
        <div class="max-w-4xl">
            <h1 class="text-3xl font-bold text-white mb-8">API Settings</h1>

            <div class="space-y-6">
                <!-- API Keys -->
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-8">
                    <h3 class="text-xl font-bold text-white mb-4">API Keys</h3>
                    <p class="text-slate-400 mb-6">Create and manage API keys for integrations</p>

                    <button @click="generateKey"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                        + Generate New Key
                    </button>

                    <div class="mt-6 space-y-4">
                        <div class="bg-slate-700 rounded p-4 flex justify-between items-center">
                            <div>
                                <p class="font-mono text-sm text-slate-300">sk_live_xxxxxxxxxxxx</p>
                                <p class="text-xs text-slate-500 mt-1">Created 2 days ago</p>
                            </div>
                            <button class="text-red-400 hover:text-red-300 text-sm">Revoke</button>
                        </div>
                    </div>
                </div>

                <!-- Webhooks -->
                <div class="bg-slate-800 rounded-lg border border-slate-700 p-8">
                    <h3 class="text-xl font-bold text-white mb-4">Webhooks</h3>
                    <p class="text-slate-400 mb-6">Configure webhook endpoints for real-time events</p>

                    <div class="space-y-2 text-sm text-slate-300">
                        <p>🔗 Endpoint: <span class="font-mono">{{ webhookUrl }}</span></p>
                        <button @click="copyWebhook" class="text-blue-400 hover:text-blue-300">
                            Copy Webhook URL
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'

const webhookUrl = 'https://yourdomain.com/webhooks/facebook'

const generateKey = () => {
    console.log('Generating new API key')
}

const copyWebhook = () => {
    navigator.clipboard.writeText(webhookUrl)
    console.log('Webhook URL copied!')
}
</script>