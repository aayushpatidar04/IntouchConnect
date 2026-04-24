<template>
    <div class="flex flex-col h-full">
        <!-- Chat header -->
        <div class="flex items-center gap-3 px-5 py-4 border-b border-surface-100 bg-white shrink-0">
            <img :src="avatarUrl" class="w-10 h-10 rounded-full object-cover" />
            <div>
                <p class="font-semibold text-sm text-surface-900">{{ customer.name }}</p>
                <p class="text-xs text-surface-400">+{{ customer.phone }}{{ customer.company ? ' · ' + customer.company
                    : '' }}</p>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <span
                    :class="['badge', customer.status === 'active' ? 'bg-brand-100 text-brand-700' : 'bg-surface-100 text-surface-600']">
                    {{ customer.status }}
                </span>
                <slot name="header-actions" />
            </div>
        </div>

        <!-- Messages scroll area -->
        <div ref="scrollArea" class="flex-1 overflow-y-auto scrollbar-thin px-5 py-4">

            <!-- Empty state -->
            <div v-if="messages.length === 0"
                class="flex flex-col items-center justify-center h-full text-center opacity-50">
                <div class="w-16 h-16 rounded-2xl bg-surface-100 flex items-center justify-center mb-3">
                    <svg class="w-8 h-8 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                </div>
                <p class="text-sm font-medium text-surface-500">No messages yet</p>
                <p class="text-xs text-surface-400 mt-1">Send the first message below</p>
            </div>

            <!-- Message list -->
            <div v-else class="space-y-3">
                <div v-for="msg in messages" :key="msg.id"
                    :class="['flex', msg.direction === 'outbound' ? 'justify-end' : 'justify-start']">
                    <!-- Inbound -->
                    <div v-if="msg.direction === 'inbound'" class="flex items-end gap-2 max-w-[75%]">
                        <img :src="avatarUrl" class="w-7 h-7 rounded-full object-cover shrink-0 mb-1" />
                        <div>
                            <DocumentBubble v-if="msg.document" :document="msg.document" class="mb-1" />
                            <div v-if="msg.body" class="bubble-in px-4 py-2.5 text-sm leading-relaxed">{{ msg.body }}
                            </div>
                            <div class="flex items-center gap-1 mt-1 ml-1">
                                <span class="text-[10px] text-surface-400">{{ formatTime(msg.created_at) }}</span>
                                <span v-if="msg.is_forwarded" class="text-[10px] text-surface-400">· Forwarded</span>
                            </div>
                        </div>
                    </div>

                    <!-- Outbound -->
                    <div v-else class="flex items-end gap-2 max-w-[75%] flex-row-reverse">
                        <img :src="msg.sent_by?.avatar || avatarUrlFromName(msg.sent_by?.name)"
                            class="w-7 h-7 rounded-full object-cover shrink-0 mb-1" />
                        <div>
                            <!-- Outbound document bubble -->
                            <DocumentBubble v-if="msg.document" :document="msg.document" class="mb-1" />
                            <div v-if="msg.body" class="bubble-out px-4 py-2.5 text-sm leading-relaxed">{{ msg.body }}
                            </div>
                            <div class="flex items-center gap-1 mt-1 mr-1 justify-end">
                                <span class="text-[10px] text-surface-400">{{ formatTime(msg.created_at) }}</span>
                                <MessageStatus :status="msg.status" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Typing / sending indicator -->
                <div v-if="sending" class="flex justify-end">
                    <div class="bubble-out px-4 py-2.5">
                        <span class="flex gap-1">
                            <span class="w-1.5 h-1.5 bg-white/60 rounded-full animate-bounce"
                                style="animation-delay:0ms" />
                            <span class="w-1.5 h-1.5 bg-white/60 rounded-full animate-bounce"
                                style="animation-delay:150ms" />
                            <span class="w-1.5 h-1.5 bg-white/60 rounded-full animate-bounce"
                                style="animation-delay:300ms" />
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Staged file preview ──────────────────────────────────────────── -->
        <Transition name="slide-up-fast">
            <div v-if="stagedFile" class="border-t border-surface-100 bg-surface-50 px-5 py-3 shrink-0">
                <div class="flex items-center gap-3 bg-white rounded-2xl border border-surface-200 px-4 py-3 shadow-sm">

                    <!-- Image thumbnail or file icon -->
                    <div
                        class="w-12 h-12 rounded-xl overflow-hidden bg-surface-100 shrink-0 flex items-center justify-center">
                        <img v-if="stagedPreviewUrl" :src="stagedPreviewUrl" class="w-full h-full object-cover"
                            alt="preview" />
                        <span v-else class="text-2xl">{{ stagedFileIcon }}</span>
                    </div>

                    <!-- File info -->
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-surface-800 truncate">{{ stagedFile.name }}</p>
                        <p class="text-xs text-surface-400">{{ formatBytes(stagedFile.size) }}</p>
                        <!-- Upload progress bar -->
                        <div v-if="uploadProgress > 0 && uploadProgress < 100"
                            class="mt-1.5 w-full bg-surface-100 rounded-full h-1 overflow-hidden">
                            <div class="h-full bg-brand-400 rounded-full transition-all duration-300"
                                :style="{ width: uploadProgress + '%' }" />
                        </div>
                        <p v-if="uploadProgress > 0 && uploadProgress < 100" class="text-[10px] text-brand-500 mt-0.5">
                            Uploading {{ uploadProgress }}%…
                        </p>
                        <p v-if="stagedDocument" class="text-[10px] text-brand-600 mt-0.5 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-brand-400 inline-block" />
                            Ready to send
                        </p>
                    </div>

                    <!-- Remove staged file -->
                    <button @click="clearStagedFile"
                        class="w-7 h-7 rounded-full flex items-center justify-center text-surface-400 hover:bg-red-50 hover:text-red-500 transition-colors shrink-0"
                        title="Remove">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <p class="text-[10px] text-surface-400 mt-2 px-1">
                    Add a caption below (optional), then click Send to deliver this file to the customer.
                </p>
            </div>
        </Transition>

        <!-- ── Input area ──────────────────────────────────────────────────── -->
        <div class="border-t border-surface-100 bg-white px-5 py-4 shrink-0">
            <div class="flex items-end gap-3">

                <!-- Attach button -->
                <button @click="fileInput.click()" :disabled="sending || uploadProgress > 0" :class="[
                    'flex items-center justify-center w-9 h-9 rounded-xl transition-colors shrink-0',
                    stagedFile
                        ? 'bg-brand-100 text-brand-600'
                        : 'bg-surface-100 hover:bg-surface-200 text-surface-500 hover:text-surface-700'
                ]" title="Attach file">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                    </svg>
                </button>
                <input ref="fileInput" type="file" class="hidden" @change="handleFileSelect" />

                <!-- Text input -->
                <textarea v-model="messageText" @keydown.enter.exact.prevent="handleSend"
                    @keydown.enter.shift.exact="messageText += '\n'" rows="1"
                    :placeholder="stagedFile ? 'Add a caption (optional)…' : 'Type a message… (Enter to send)'"
                    class="flex-1 resize-none rounded-2xl border border-surface-200 bg-surface-50 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent transition-all max-h-32 scrollbar-thin"
                    style="field-sizing: content;" />

                <!-- Send button — active if text exists OR file is staged and uploaded -->
                <button @click="handleSend" :disabled="!canSend" :class="[
                    'flex items-center justify-center w-10 h-10 rounded-xl transition-all shrink-0',
                    canSend
                        ? 'bg-brand-500 hover:bg-brand-600 text-white shadow-md shadow-brand-500/30'
                        : 'bg-surface-100 text-surface-300 cursor-not-allowed'
                ]">
                    <svg class="w-4 h-4 rotate-45" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch, computed, nextTick, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';
import { useChannel } from '@/Composables/useEcho';
import DocumentBubble from '@/Components/Chat/DocumentBubble.vue';
import MessageStatus from '@/Components/Chat/MessageStatus.vue';
import { format, isToday, isYesterday } from 'date-fns';

const props = defineProps({
    customer: { type: Object, required: true },
    initialMessages: { type: Array, default: () => [] },
});
const emit = defineEmits(['message-sent', 'document-updated']);

const { success, error: toastError } = useToast();
const page = usePage();

// ── State ────────────────────────────────────────────────────────────────────
const messages = ref([...props.initialMessages]);
const messageText = ref('');
const sending = ref(false);
const scrollArea = ref(null);
const fileInput = ref(null);

// Staged file state
const stagedFile = ref(null);   // File object from input
const stagedPreviewUrl = ref(null);   // object URL for image thumbnails
const stagedDocument = ref(null);   // Document record returned after upload
const uploadProgress = ref(0);

// ── Computed ─────────────────────────────────────────────────────────────────
const avatarUrl = computed(() =>
    `https://ui-avatars.com/api/?name=${encodeURIComponent(props.customer.name)}&background=e2e8f0&color=475569&size=64`
);

// Local helper function
function avatarUrlFromName(name) {
  const safeName = name || 'User';
  return `https://ui-avatars.com/api/?name=${encodeURIComponent(safeName)}&background=e2e8f0&color=475569&size=64`;
}

// Send is allowed when:
//   a) there is text (with or without a file), OR
//   b) a file has been fully uploaded (stagedDocument exists) and we're not already sending
const canSend = computed(() =>
    !sending.value &&
    (messageText.value.trim().length > 0 || stagedDocument.value !== null)
);

const stagedFileIcon = computed(() => {
    if (!stagedFile.value) return '📎';
    const mime = stagedFile.value.type;
    if (mime.startsWith('image/')) return '🖼️';
    if (mime.includes('pdf')) return '📄';
    if (mime.startsWith('video/')) return '🎥';
    if (mime.startsWith('audio/')) return '🎵';
    return '📎';
});

// ── Scroll ───────────────────────────────────────────────────────────────────
watch(() => messages.value.length, () => nextTick(scrollToBottom));
onMounted(() => nextTick(scrollToBottom));

function scrollToBottom() {
    if (scrollArea.value) scrollArea.value.scrollTop = scrollArea.value.scrollHeight;
}

// ── Real-time ─────────────────────────────────────────────────────────────────
useChannel(`user.${page.props.auth.user.id}`, {
    'message.received': (data) => {
        if (data.customer_id === props.customer.id) {
            messages.value.push(data);
        }
    },
});

useChannel('messages', {
    'message.status': (data) => {
        const msg = messages.value.find(
            m => m.gateway_job_id === data.job_id || m.id === data.id
        );
        if (msg) msg.status = data.status;
    },
});

// ── File staging ─────────────────────────────────────────────────────────────
async function handleFileSelect(e) {
    const file = e.target.files[0];
    if (!file) return;
    e.target.value = ''; // reset input so same file can be re-selected

    // Validate size (20 MB)
    if (file.size > 20 * 1024 * 1024) {
        toastError('File too large. Maximum size is 20 MB.');
        return;
    }

    stagedFile.value = file;
    stagedDocument.value = null;
    uploadProgress.value = 0;

    // Generate image preview URL
    if (file.type.startsWith('image/')) {
        stagedPreviewUrl.value = URL.createObjectURL(file);
    } else {
        stagedPreviewUrl.value = null;
    }

    // Auto-upload to server immediately so it is ready when user hits Send
    await uploadStagedFile(file);
}

async function uploadStagedFile(file) {
    const fd = new FormData();
    fd.append('file', file);

    try {
        const { data } = await axios.post(
            route('documents.upload', props.customer.id),
            fd,
            {
                headers: { 'Content-Type': 'multipart/form-data' },
                onUploadProgress: (e) => {
                    uploadProgress.value = Math.round((e.loaded * 100) / e.total);
                },
            }
        );
        stagedDocument.value = data.document;
        uploadProgress.value = 100;
        emit('document-updated'); // refresh sidebar doc list
    } catch {
        toastError('Upload failed. Please try again.');
        clearStagedFile();
    }
}

function clearStagedFile() {
    if (stagedPreviewUrl.value) URL.revokeObjectURL(stagedPreviewUrl.value);
    stagedFile.value = null;
    stagedPreviewUrl.value = null;
    stagedDocument.value = null;
    uploadProgress.value = 0;
}

// ── Send ─────────────────────────────────────────────────────────────────────
async function handleSend() {
    if (!canSend.value) return;

    if (stagedDocument.value) {
        await sendDocument();
    } else {
        await sendTextMessage();
    }
}

async function sendTextMessage() {
    const text = messageText.value.trim();
    if (!text || sending.value) return;

    sending.value = true;
    const optimistic = {
        id: `opt-${Date.now()}`,
        direction: 'outbound',
        body: text,
        status: 'pending',
        created_at: new Date().toISOString(),
    };
    messages.value.push(optimistic);
    messageText.value = '';

    try {
        const { data } = await axios.post(
            route('messages.send', props.customer.id),
            { body: text }
        );
        const idx = messages.value.findIndex(m => m.id === optimistic.id);
        if (idx !== -1) messages.value[idx] = data.message;
        emit('message-sent');
    } catch {
        messages.value = messages.value.filter(m => m.id !== optimistic.id);
        toastError('Failed to send message. Check WhatsApp connection.');
    } finally {
        sending.value = false;
    }
}

async function sendDocument() {
    if (!stagedDocument.value || sending.value) return;

    sending.value = true;
    const caption = messageText.value.trim();

    // Optimistic outbound bubble
    const optimistic = {
        id: `opt-${Date.now()}`,
        direction: 'outbound',
        body: caption,
        status: 'pending',
        created_at: new Date().toISOString(),
        document: { ...stagedDocument.value }, // show doc bubble immediately
    };
    messages.value.push(optimistic);
    messageText.value = '';
    const docRef = stagedDocument.value;
    clearStagedFile();

    try {
        const { data } = await axios.post(
            route('documents.send', {
                customer: props.customer.id,
                document: docRef.id,
            }),
            { caption }
        );

        // Replace optimistic with real message
        const idx = messages.value.findIndex(m => m.id === optimistic.id);
        if (idx !== -1) messages.value[idx] = { ...data.message, document: data.document };

        emit('message-sent');
        emit('document-updated'); // refresh doc list status
        success('Document sent to customer.');
    } catch (err) {
        console.log(err);
        messages.value = messages.value.filter(m => m.id !== optimistic.id);
        toastError(err.response?.data?.error ?? 'Failed to send document.');
    } finally {
        sending.value = false;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function formatTime(ts) {
    if (!ts) return '';
    const d = new Date(ts);
    if (isToday(d)) return format(d, 'HH:mm');
    if (isYesterday(d)) return 'Yesterday ' + format(d, 'HH:mm');
    return format(d, 'dd MMM, HH:mm');
}

function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}
</script>

<style scoped>
.slide-up-fast-enter-active,
.slide-up-fast-leave-active {
    transition: all 0.2s ease;
}

.slide-up-fast-enter-from,
.slide-up-fast-leave-to {
    opacity: 0;
    transform: translateY(8px);
}
</style>