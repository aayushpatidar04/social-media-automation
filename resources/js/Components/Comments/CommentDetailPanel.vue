<!-- resources/js/Components/Comments/CommentDetailPanel.vue -->
<template>
    <Teleport to="body">
        <div v-if="comment" class="fixed inset-0 z-50 lg:relative lg:z-0">
            <!-- Mobile: Full screen backdrop -->
            <div
                class="lg:hidden absolute inset-0 bg-black/50"
                @click="$emit('close')"
            ></div>

            <!-- Panel -->
            <div
                class="fixed inset-0 right-0 w-full lg:static lg:w-96 bg-slate-800 border-l border-slate-700 overflow-y-auto z-50 lg:z-auto"
            >
                <!-- Close Button (Mobile) -->
                <button
                    @click="$emit('close')"
                    class="lg:hidden absolute top-4 left-4 text-slate-400 hover:text-slate-300 z-10"
                >
                    ← Back
                </button>

                <!-- Header -->
                <div
                    class="sticky top-0 bg-slate-800 border-b border-slate-700 p-6 pt-16 lg:pt-6"
                >
                    <h3 class="text-lg font-bold text-white">
                        {{ comment.author_name }}
                    </h3>
                    <p class="text-slate-400 text-sm">
                        {{ comment.social_account.platform }}
                    </p>
                </div>

                <!-- Content -->
                <div class="p-6 space-y-6">
                    <!-- Comment -->
                    <div>
                        <label
                            class="block text-sm font-medium text-slate-300 mb-2"
                            >Comment</label
                        >
                        <div class="bg-slate-700 rounded p-4 text-slate-300">
                            {{ comment.content }}
                        </div>
                    </div>

                    <!-- Analysis Results -->
                    <div class="space-y-3">
                        <div>
                            <label
                                class="block text-sm font-medium text-slate-300 mb-1"
                                >Sentiment</label
                            >
                            <Badge
                                :variant="
                                    getSentimentVariant(comment.sentiment)
                                "
                            >
                                {{ comment.sentiment }} ({{
                                    comment.sentiment_score
                                }}/100)
                            </Badge>
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-slate-300 mb-1"
                                >Intent</label
                            >
                            <Badge :variant="getIntentVariant(comment.intent)">
                                {{ comment.intent }}
                            </Badge>
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-slate-300 mb-1"
                                >Lead Score</label
                            >
                            <p class="text-white font-bold">
                                {{ comment.lead_score }}/100
                            </p>
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-slate-300 mb-1"
                                >Status</label
                            >
                            <p class="text-white capitalize">
                                {{ comment.status }}
                            </p>
                        </div>
                    </div>

                    <!-- AI Response (if available) -->
                    <div
                        v-if="aiConversation"
                        class="border-t border-slate-700 pt-6"
                    >
                        <h4 class="font-bold text-white mb-3">AI Response</h4>

                        <div
                            v-if="aiConversation.ai_response"
                            class="bg-slate-700 rounded p-4 text-slate-300 mb-4"
                        >
                            {{ aiConversation.ai_response }}
                        </div>

                        <div
                            v-if="aiConversation.requires_human_review"
                            class="bg-yellow-900/30 border border-yellow-700 rounded p-3 mb-4"
                        >
                            <p class="text-yellow-200 text-sm">
                                ⚠️ {{ aiConversation.review_reason }}
                            </p>
                        </div>

                        <div
                            v-if="aiConversation.response_status === 'pending'"
                            class="space-y-2"
                        >
                            <LoadingButton
                                :loading="loading.approve"
                                :loading-text="'Approving...'"
                                base-classes="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded font-medium"
                                @click="approveResponse"
                            >
                                Approve & Send
                            </LoadingButton>
                            <LoadingButton
                                :loading="loading.reject"
                                :loading-text="'Rejecting...'"
                                base-classes="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium"
                                @click="showRejectForm = true"
                            >
                                Reject
                            </LoadingButton>
                        </div>

                        <div
                            v-if="aiConversation.response_status === 'approved'"
                            class="bg-green-900/30 border border-green-700 rounded p-3"
                        >
                            <p class="text-green-200 text-sm">
                                ✓ Response approved and queued for sending
                            </p>
                        </div>

                        <div
                            v-if="
                                aiConversation.response_status === 'auto_sent'
                            "
                            class="bg-blue-900/30 border border-blue-700 rounded p-3"
                        >
                            <p class="text-blue-200 text-sm">
                                ✓ Response sent automatically
                            </p>
                            <p class="text-blue-200 text-xs mt-1">
                                Sent at:
                                {{ formatDate(aiConversation.sent_at) }}
                            </p>
                        </div>
                    </div>

                    <!-- Manual Reply Option -->
                    <div v-else class="border-t border-slate-700 pt-6">
                        <h4 class="font-bold text-white mb-3">
                            Send Manual Reply
                        </h4>
                        <textarea
                            v-model="manualReply"
                            placeholder="Write your reply..."
                            rows="4"
                            class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400"
                        ></textarea>
                        <LoadingButton
                            :loading="loading.send"
                            :loading-text="'Sending...'"
                            base-classes="mt-2 w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-600 text-white rounded font-medium"
                            :disabled="!manualReply.trim()"
                            @click="sendManualReply"
                        >
                            Send Reply
                        </LoadingButton>
                    </div>

                    <!-- Mark as Responded -->
                    <div
                        v-if="comment.status !== 'replied'"
                        class="border-t border-slate-700 pt-6"
                    >
                        <LoadingButton
                            :loading="loading.markResponded"
                            :loading-text="'Saving...'"
                            base-classes="w-full px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded font-medium"
                            @click="markAsResponded"
                        >
                            Mark as Responded
                        </LoadingButton>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reject Modal -->
        <Modal v-model="showRejectForm" title="Reject Response">
            <textarea
                v-model="rejectReason"
                placeholder="Why are you rejecting this response?"
                rows="4"
                class="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
            ></textarea>
            <template #footer>
                <LoadingButton
                    :loading="loading.rejectSubmit"
                    :loading-text="'Rejecting...'"
                    base-classes="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded"
                    @click="submitReject"
                >
                    Reject
                </LoadingButton>
                <button
                    @click="showRejectForm = false"
                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded"
                >
                    Cancel
                </button>
            </template>
        </Modal>
    </Teleport>
</template>

<script setup>
import { ref, reactive } from "vue";
import Modal from "@/Components/Modal.vue";
import Badge from "@/Components/Badge.vue";
import { toast } from "@/composables/useToast";
import LoadingButton from "@/Components/LoadingButton.vue";

const props = defineProps({
    comment: Object,
    aiConversation: Object,
});

const emit = defineEmits(["close", "updated"]);

const manualReply = ref("");
const showRejectForm = ref(false);
const rejectReason = ref("");
const loading = reactive({
    send: false,
    approve: false,
    reject: false,
    rejectSubmit: false,
    markResponded: false,
});

const getSentimentVariant = (sentiment) => {
    const variants = {
        positive: "success",
        neutral: "info",
        negative: "danger",
        pending: "warning",
    };
    return variants[sentiment] || "info";
};

const getIntentVariant = (intent) => {
    const variants = {
        sales: "primary",
        support: "info",
        complaint: "danger",
        lead: "success",
        question: "warning",
        general: "info",
    };
    return variants[intent] || "info";
};

const approveResponse = async () => {
    loading.approve = true;
    try {
        const response = await fetch(`/inbox/${props.comment.id}/approve`, {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
            },
        });

        if (response.ok) {
            emit("updated");
            emit("close");
            toast.success("Response approved and sent");
        } else {
            toast.error("Failed to approve response");
        }
    } catch (error) {
        toast.error("Error approving response");
        console.error("Error approving response:", error);
    } finally {
        loading.approve = false;
    }
};

const submitReject = async () => {
    loading.rejectSubmit = true;
    try {
        const response = await fetch(`/inbox/${props.comment.id}/reject`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
            },
            body: JSON.stringify({
                reason: rejectReason.value || "User rejected",
            }),
        });

        if (response.ok) {
            showRejectForm.value = false;
            emit("updated");
            emit("close");
            toast.success("Response rejected");
        } else {
            toast.error("Failed to reject response");
        }
    } catch (error) {
        toast.error("Error rejecting response");
        console.error("Error rejecting response:", error);
    } finally {
        loading.rejectSubmit = false;
    }
};

const sendManualReply = async () => {
    loading.send = true;
    try {
        const response = await fetch(`/inbox/${props.comment.id}/reply`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
            },
            body: JSON.stringify({ message: manualReply.value }),
        });

        if (response.ok) {
            manualReply.value = "";
            emit("updated");
            emit("close");
            toast.success("Reply sent successfully");
        } else {
            toast.error("Failed to send reply");
        }
    } catch (error) {
        toast.error("Error sending reply");
        console.error("Error sending reply:", error);
    } finally {
        loading.send = false;
    }
};

const markAsResponded = async () => {
    loading.markResponded = true;
    try {
        const response = await fetch(
            `/inbox/${props.comment.id}/mark-responded`,
            {
                method: "POST",
                headers: {
                    "X-CSRF-TOKEN": document.querySelector(
                        'meta[name="csrf-token"]',
                    ).content,
                },
            },
        );

        if (response.ok) {
            emit("updated");
            toast.success("Marked as responded");
        } else {
            toast.error("Failed to mark as responded");
        }
    } catch (error) {
        toast.error("Error marking as responded");
        console.error("Error marking as responded:", error);
    } finally {
        loading.markResponded = false;
    }
};

const formatDate = (date) => {
    if (!date) return "";
    return new Date(date).toLocaleDateString();
};
</script>
