<template>
    <AppLayout>
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-white">Social Accounts</h1>
                <div class="flex gap-4">
                    <LoadingButton
                        :loading="loading.facebook"
                        :loading-text="'Connecting...'"
                        base-classes="bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium"
                        as="a"
                        :href="facebookLoginUrl"
                    >
                        + Connect Facebook
                    </LoadingButton>
                    <LoadingButton
                        :loading="loading.youtube"
                        :loading-text="'Connecting...'"
                        base-classes="bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium"
                        as="a"
                        :href="youtubeLoginUrl"
                    >
                        + Connect YouTube
                    </LoadingButton>
                    <LoadingButton
                        :loading="loading.twitter"
                        :loading-text="'Connecting...'"
                        base-classes="bg-black hover:bg-gray-800 text-white rounded-lg font-medium"
                        as="a"
                        :href="twitterLoginUrl"
                    >
                        + Connect X
                    </LoadingButton>
                    <LoadingButton
                        :loading="loading.linkedin"
                        :loading-text="'Connecting...'"
                        base-classes="bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-medium"
                        as="a"
                        :href="linkedinLoginUrl"
                    >
                        + Connect LinkedIn
                    </LoadingButton>
                </div>
            </div>

            <!-- Connected Accounts -->
            <div class="grid gap-4">
                <div
                    v-for="account in accounts"
                    :key="account.id"
                    class="bg-slate-800 rounded-lg border border-slate-700 p-6"
                >
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <h3 class="text-lg font-bold text-white mb-2">
                                {{ account.platform_account_name }}
                            </h3>
                            <p class="text-slate-400 text-sm mb-4">
                                Platform:
                                <span
                                    class="capitalize font-mono text-blue-400"
                                    >{{ account.platform }}</span
                                >
                            </p>
                            <div class="space-y-2 text-sm text-slate-400">
                                <p>
                                    Status:
                                    <span
                                        :class="getStatusClass(account.status)"
                                        >{{ account.status }}</span
                                    >
                                </p>
                                <p v-if="account.last_synced_at">
                                    Last synced:
                                    {{ formatDate(account.last_synced_at) }}
                                </p>
                                <p
                                    v-if="
                                        account.platform === 'youtube' &&
                                        account.metadata?.pubsub_subscribed
                                    "
                                    class="text-green-400"
                                >
                                    🟢 Webhook active (PubSubHubbub)
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <LoadingButton
                                :loading="loading.sync[account.id]"
                                :loading-text="'Syncing...'"
                                base-classes="bg-amber-700 hover:bg-amber-600 text-white rounded text-sm"
                                @click="syncNow(account.id, true)"
                            >
                                Full Sync
                            </LoadingButton>

                            <LoadingButton
                                v-if="
                                    account.platform === 'facebook' ||
                                    account.platform === 'instagram'
                                "
                                :loading="loading.sync[account.id]"
                                :loading-text="'Syncing...'"
                                base-classes="bg-slate-700 hover:bg-slate-600 text-white rounded text-sm"
                                @click="syncNow(account.id, false)"
                            >
                                Sync Now
                            </LoadingButton>

                            <template v-if="account.platform === 'youtube'">
                                <LoadingButton
                                    :loading="loading.sync[account.id]"
                                    :loading-text="'Syncing...'"
                                    base-classes="bg-slate-700 hover:bg-slate-600 text-white rounded text-sm"
                                    @click="syncYoutube(account.id)"
                                >
                                    Sync Now
                                </LoadingButton>
                                <LoadingButton
                                    :loading="loading.webhook[account.id]"
                                    :loading-text="'Toggling...'"
                                    :class="[
                                        'px-3 py-2 text-white rounded text-sm',
                                        account.metadata?.pubsub_subscribed
                                            ? 'bg-green-700 hover:bg-green-600'
                                            : 'bg-orange-700 hover:bg-orange-600',
                                    ]"
                                    @click="toggleYoutubeWebhook(account)"
                                >
                                    {{
                                        account.metadata?.pubsub_subscribed
                                            ? "Webhook Active"
                                            : "Subscribe Webhook"
                                    }}
                                </LoadingButton>
                            </template>

                            <LoadingButton
                                v-if="account.platform === 'twitter'"
                                :loading="loading.sync[account.id]"
                                :loading-text="'Syncing...'"
                                base-classes="bg-slate-700 hover:bg-slate-600 text-white rounded text-sm"
                                @click="syncTwitter(account.id)"
                            >
                                Sync Now
                            </LoadingButton>

                            <LoadingButton
                                v-if="account.platform === 'linkedin'"
                                :loading="loading.sync[account.id]"
                                :loading-text="'Syncing...'"
                                base-classes="bg-slate-700 hover:bg-slate-600 text-white rounded text-sm"
                                @click="syncLinkedIn(account.id)"
                            >
                                Sync Now
                            </LoadingButton>

                            <LoadingButton
                                :loading="loading.disconnect[account.id]"
                                :loading-text="'Removing...'"
                                base-classes="bg-red-900 hover:bg-red-800 text-red-200 rounded text-sm"
                                @click="disconnect(account.id)"
                            >
                                Disconnect
                            </LoadingButton>
                        </div>
                    </div>
                </div>

                <div
                    v-if="accounts.length === 0"
                    class="bg-slate-800 rounded-lg border border-slate-700 p-5 mb-2 text-center"
                >
                    <p class="text-slate-400 text-lg mb-4">
                        No social accounts connected yet
                    </p>
                    <a
                        :href="facebookLoginUrl"
                        class="inline-block px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium"
                    >
                        Connect Your First Account
                    </a>
                </div>
            </div>

            <!-- Supported Platforms -->
            <div
                class="mt-12 bg-slate-800 rounded-lg border border-slate-700 p-6"
            >
                <h3 class="text-xl font-bold text-white mb-4">
                    Supported Platforms
                </h3>
                <div class="grid md:grid-cols-2 gap-4 text-sm text-slate-300">
                    <div>✅ Facebook (Connected)</div>
                    <div>✅ Instagram (Connected via Facebook)</div>
                    <div>✅ YouTube (Webhook + Cron)</div>
                    <div>✅ Twitter/X (Cron polling)</div>
                    <div>✅ LinkedIn (Webhook + Cron)</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";
import { router } from "@inertiajs/vue3";
import { toast } from "@/composables/useToast";
import LoadingButton from "@/Components/LoadingButton.vue";

const props = defineProps({
    accounts: Array,
    facebook_login_url: String,
    available_platforms: Array,
});

const loading = reactive({
    facebook: false,
    youtube: false,
    twitter: false,
    linkedin: false,
    sync: {},
    webhook: {},
    disconnect: {},
});

const facebookLoginUrl = ref(props.facebook_login_url || "#");
const youtubeLoginUrl = "/auth/youtube/login";
const twitterLoginUrl = "/auth/twitter/login";
const linkedinLoginUrl = "/auth/linkedin/login";

const syncLinkedIn = async (accountId) => {
    loading.sync[accountId] = true;
    try {
        const response = await axios.post(
            `/settings/social-accounts/${accountId}/linkedin-sync`,
        );
        toast.success(response.data.message || "LinkedIn synced successfully");
    } catch (error) {
        toast.error(error.response?.data?.message || "LinkedIn sync failed");
    } finally {
        loading.sync[accountId] = false;
    }
};

const syncNow = async (accountId, fullSync) => {
    loading.sync[accountId] = true;
    try {
        const response = await axios.post(
            `/settings/social-accounts/${accountId}/sync`,
            null,
            {
                params: { full_sync: fullSync ? 1 : 0 },
            },
        );
        toast.success(
            response.data.message ||
                (fullSync ? "Full sync started" : "Sync completed"),
        );
    } catch (error) {
        toast.error(error.response?.data?.message || "Sync failed");
    } finally {
        loading.sync[accountId] = false;
    }
};

const syncYoutube = async (accountId) => {
    loading.sync[accountId] = true;
    try {
        const response = await axios.post(
            `/settings/social-accounts/${accountId}/youtube-sync`,
        );
        toast.success(response.data.message || "YouTube synced successfully");
    } catch (error) {
        toast.error(error.response?.data?.message || "YouTube sync failed");
    } finally {
        loading.sync[accountId] = false;
    }
};

const toggleYoutubeWebhook = async (account) => {
    if (account.metadata?.pubsub_subscribed) {
        if (
            !confirm(
                "Disable real-time webhook for this channel? Cron sync will continue.",
            )
        )
            return;
    }
    loading.webhook[account.id] = true;
    try {
        const url = account.metadata?.pubsub_subscribed
            ? `/youtube/unsubscribe/${account.id}`
            : `/youtube/subscribe/${account.id}`;
        const response = await axios.post(url);
        toast.success(
            response.data.message ||
                (account.metadata?.pubsub_subscribed
                    ? "Webhook unsubscribed"
                    : "Webhook subscribed"),
        );
        router.reload();
    } catch (error) {
        toast.error(
            error.response?.data?.message || "YouTube webhook toggle failed",
        );
    } finally {
        loading.webhook[account.id] = false;
    }
};

const syncTwitter = async (accountId) => {
    loading.sync[accountId] = true;
    try {
        const response = await axios.post(
            `/settings/social-accounts/${accountId}/twitter-sync`,
        );
        toast.success(response.data.message || "X/Twitter synced successfully");
    } catch (error) {
        toast.error(error.response?.data?.message || "X sync failed");
    } finally {
        loading.sync[accountId] = false;
    }
};

const disconnect = async (accountId) => {
    if (!confirm("Are you sure you want to disconnect this account?")) return;
    loading.disconnect[accountId] = true;
    try {
        const response = await axios.post(
            `/settings/social-accounts/${accountId}/disconnect`,
        );
        toast.success(response.data.message || "Account disconnected");
        router.reload();
    } catch (error) {
        toast.error(error.response?.data?.message || "Disconnect failed");
    } finally {
        loading.disconnect[accountId] = false;
    }
};

const getStatusClass = (status) => {
    const classes = {
        connected: "text-green-400",
        disconnected: "text-red-400",
        expired: "text-yellow-400",
        error: "text-red-400",
    };
    return classes[status] || "text-slate-400";
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString();
};
</script>
