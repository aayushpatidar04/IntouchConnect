<template>
    <div class="flex flex-col h-full">
        <!-- Chat header -->
        <div class="flex items-center gap-3 px-5 py-4 border-b border-surface-100 bg-white shrink-0">
            <img :src="avatarUrl" class="w-10 h-10 rounded-full object-cover" />
            <div>
                <p class="font-semibold text-sm text-surface-900">{{ customer.name }}</p>
                <p class="text-xs text-surface-400">
                    +{{ customer.phone.slice(0, 2) + '*'.repeat(customer.phone.length - 4) + customer.phone.slice(-2) }}
                    {{ customer.company ? ' · ' + customer.company : '' }}
                </p>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <span :class="['badge', customer.status === 'active' ? 'bg-brand-100 text-brand-700' : 'bg-surface-100 text-surface-600']">
                    {{ customer.status }}
                </span>
                <slot name="header-actions" />
            </div>
        </div>

        <!-- Messages scroll area -->
        <div ref="scrollArea" class="flex-1 overflow-y-auto scrollbar-thin px-5 py-4">
            <!-- Empty state -->
            <div v-if="messages.length === 0" class="flex flex-col items-center justify-center h-full text-center opacity-50">
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
                            <div v-if="msg.body" :class="[
                                'px-4 py-2.5 text-sm leading-relaxed rounded-2xl',
                                isArihantMessage(msg) ? 'bg-blue-100 border border-blue-200' : 'bubble-in'
                            ]" v-html="linkify(msg.body)"></div>
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
                            <DocumentBubble v-if="msg.document" :document="msg.document" class="mb-1" />
                            <div v-if="msg.body" :class="[
                                'px-4 py-2.5 text-sm leading-relaxed rounded-2xl',
                                isArihantMessage(msg) ? 'bg-blue-100 border border-blue-200' : 'bubble-out'
                            ]" v-html="linkify(msg.body)"></div>
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
                            <span class="w-1.5 h-1.5 bg-white/60 rounded-full animate-bounce" style="animation-delay:0ms" />
                            <span class="w-1.5 h-1.5 bg-white/60 rounded-full animate-bounce" style="animation-delay:150ms" />
                            <span class="w-1.5 h-1.5 bg-white/60 rounded-full animate-bounce" style="animation-delay:300ms" />
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Staged file preview -->
        <Transition name="slide-up-fast">
            <div v-if="stagedFile" class="border-t border-surface-100 bg-surface-50 px-5 py-3 shrink-0">
                <div class="flex items-center gap-3 bg-white rounded-2xl border border-surface-200 px-4 py-3 shadow-sm">
                    <div class="w-12 h-12 rounded-xl overflow-hidden bg-surface-100 shrink-0 flex items-center justify-center">
                        <img v-if="stagedPreviewUrl" :src="stagedPreviewUrl" class="w-full h-full object-cover" alt="preview" />
                        <span v-else class="text-2xl">{{ stagedFileIcon }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-surface-800 truncate">{{ stagedFile.name }}</p>
                        <p class="text-xs text-surface-400">{{ formatBytes(stagedFile.size) }}</p>
                        <div v-if="uploadProgress > 0 && uploadProgress < 100" class="mt-1.5 w-full bg-surface-100 rounded-full h-1 overflow-hidden">
                            <div class="h-full bg-brand-400 rounded-full transition-all duration-300" :style="{ width: uploadProgress + '%' }" />
                        </div>
                        <p v-if="uploadProgress > 0 && uploadProgress < 100" class="text-[10px] text-brand-500 mt-0.5">Uploading {{ uploadProgress }}%…</p>
                        <p v-if="stagedDocument" class="text-[10px] text-brand-600 mt-0.5 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-brand-400 inline-block" />
                            Ready to send
                        </p>
                    </div>
                    <button @click="clearStagedFile"
                        class="w-7 h-7 rounded-full flex items-center justify-center text-surface-400 hover:bg-red-50 hover:text-red-500 transition-colors shrink-0" title="Remove">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <p class="text-[10px] text-surface-400 mt-2 px-1">Add a caption below (optional), then click Send to deliver this file to the customer.</p>
            </div>
        </Transition>

        <!-- ── Input area ──────────────────────────────────────────────────── -->
        <div class="border-t border-surface-100 bg-white px-5 py-4 shrink-0">
            <div class="flex items-end gap-3">

                <!-- Template button -->
                <button @click="openTemplatePicker" :disabled="sending || uploadProgress > 0"
                    class="flex items-center justify-center w-9 h-9 rounded-xl bg-surface-100 hover:bg-brand-50 text-surface-500 hover:text-brand-600 transition-colors shrink-0"
                    title="Send template">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z" />
                    </svg>
                </button>

                <!-- Attach button -->
                <button @click="fileInput.click()" :disabled="sending || uploadProgress > 0" :class="[
                    'flex items-center justify-center w-9 h-9 rounded-xl transition-colors shrink-0',
                    stagedFile ? 'bg-brand-100 text-brand-600' : 'bg-surface-100 hover:bg-surface-200 text-surface-500 hover:text-surface-700'
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

                <!-- Send button -->
                <button @click="handleSend" :disabled="!canSend" :class="[
                    'flex items-center justify-center w-10 h-10 rounded-xl transition-all shrink-0',
                    canSend ? 'bg-brand-500 hover:bg-brand-600 text-white shadow-md shadow-brand-500/30' : 'bg-surface-100 text-surface-300 cursor-not-allowed'
                ]">
                    <svg class="w-4 h-4 rotate-45" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- ── Template Picker Modal ───────────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showTemplatePicker" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeTemplatePicker" />
                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] flex flex-col animate-slide-up">
                        <div class="p-5 border-b border-surface-100 shrink-0">
                            <h2 class="text-base font-semibold">Send Template</h2>
                            <p class="text-xs text-surface-400 mt-0.5">Select a template to send to {{ customer.name }}</p>
                        </div>
                        <div class="flex-1 overflow-y-auto p-4 space-y-2">
                            <!-- Search -->
                            <input v-model="templateSearch" placeholder="Search templates…"
                                class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 mb-3" />

                            <div v-if="filteredTemplates.length === 0" class="text-center py-8 text-sm text-surface-400">
                                No templates found.
                            </div>

                            <button v-for="t in filteredTemplates" :key="t.id" @click="selectTemplate(t)"
                                class="w-full text-left p-4 rounded-xl border border-surface-100 hover:border-brand-300 hover:bg-brand-50 transition-colors">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="font-medium text-sm text-surface-900">{{ t.name }}</span>
                                    <span :class="['badge text-[10px]', categoryColor(t.category)]">{{ t.category }}</span>
                                </div>
                                <p class="text-xs text-surface-500 line-clamp-2" v-html="renderWaMarkdown(t.body)" />
                                <div v-if="t.variables.length" class="flex flex-wrap gap-1 mt-2">
                                    <span v-for="v in t.variables" :key="v"
                                        class="bg-brand-50 text-brand-600 text-[10px] font-mono px-1.5 py-0.5 rounded">
                                        {{ formatVar(v) }}
                                    </span>
                                </div>
                            </button>
                        </div>
                        <div class="p-4 border-t border-surface-100 shrink-0">
                            <button @click="closeTemplatePicker" class="w-full px-4 py-2 text-sm text-surface-600 rounded-xl hover:bg-surface-100 transition-colors">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── Template Variables Modal ──────────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="selectedTemplate" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeVariableModal" />
                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 animate-slide-up">
                        <h2 class="text-base font-semibold mb-1">{{ selectedTemplate.name }}</h2>
                        <p class="text-xs text-surface-400 mb-4">Fill in the variables for {{ customer.name }}</p>

                        <!-- Auto-filled variables -->
                        <div v-if="autoFilledVars.length" class="mb-4 space-y-2">
                            <p class="text-xs font-medium text-surface-500 uppercase tracking-wider">Auto-filled</p>
                            <div v-for="v in autoFilledVars" :key="v" class="flex items-center gap-2 bg-surface-50 rounded-lg px-3 py-2">
                                <span class="text-xs font-mono text-brand-600">{{ formatVar(v) }}</span>
                                <span class="text-xs text-surface-400">= {{ getAutoFillValue(v) }}</span>
                            </div>
                        </div>

                        <!-- Custom variables -->
                        <div v-if="customVars.length" class="space-y-3 mb-4">
                            <p class="text-xs font-medium text-surface-500 uppercase tracking-wider">Fill manually</p>
                            <div v-for="v in customVars" :key="v">
                                <label class="block text-xs font-medium text-surface-700 mb-1">{{ formatVar(v) }}</label>
                                <input v-model="variableValues[v]" :placeholder="`Enter ${v}…`"
                                    class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
                            </div>
                        </div>

                        <!-- Preview -->
                        <div class="bg-[#e9f5e1] rounded-xl px-3 py-3 mb-4">
                            <p class="text-[10px] text-surface-400 uppercase tracking-wider mb-1">Preview</p>
                            <div class="text-sm leading-relaxed wa-preview" v-html="linkify(renderWaMarkdown(previewBody))" />
                        </div>

                        <div class="flex justify-end gap-2">
                            <button @click="closeVariableModal" class="px-4 py-2 text-sm text-surface-600">Cancel</button>
                            <button @click="fillTemplateToInput" :disabled="sendingTemplate"
                                class="px-5 py-2 bg-brand-500 text-white text-sm font-medium rounded-xl hover:bg-brand-600 disabled:opacity-50">
                                {{ sendingTemplate ? 'Sending…' : 'Fill in Chat' }}
                            </button>
                            <button @click="sendTemplateDirect" :disabled="sendingTemplate"
                                class="px-5 py-2 bg-brand-600 text-white text-sm font-medium rounded-xl hover:bg-brand-700 disabled:opacity-50">
                                {{ sendingTemplate ? 'Sending…' : 'Send Now' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
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
    templates: { type: Array, default: () => [] }, // NEW: templates passed from parent
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
const stagedFile = ref(null);
const stagedPreviewUrl = ref(null);
const stagedDocument = ref(null);
const uploadProgress = ref(0);

// ── Template State ─────────────────────────────────────────────────────────
const showTemplatePicker = ref(false);
const templateSearch = ref('');
const selectedTemplate = ref(null);
const variableValues = ref({});
const sendingTemplate = ref(false);

const autoFilled = ['customer_name', 'customer_phone', 'customer_email'];

// ── Computed ─────────────────────────────────────────────────────────────────
const avatarUrl = computed(() =>
    `https://ui-avatars.com/api/?name=${encodeURIComponent(props.customer.name)}&background=e2e8f0&color=475569&size=64`
);

const canSend = computed(() =>
    !sending.value && !sendingTemplate.value &&
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

// Template computed
const filteredTemplates = computed(() => {
    if (!props.templates || !Array.isArray(props.templates)) return [];
    let list = props.templates.filter(t => t.is_active !== false);
    if (templateSearch.value) {
        const q = templateSearch.value.toLowerCase();
        list = list.filter(t => t.name.toLowerCase().includes(q) || t.body.toLowerCase().includes(q));
    }
    return list;
});

const autoFilledVars = computed(() => {
    if (!selectedTemplate.value) return [];
    return (selectedTemplate.value.variables || []).filter(v => autoFilled.includes(v));
});

const customVars = computed(() => {
    if (!selectedTemplate.value) return [];
    return (selectedTemplate.value.variables || []).filter(v => !autoFilled.includes(v));
});

const previewBody = computed(() => {
    if (!selectedTemplate.value) return '';
    let body = selectedTemplate.value.body;
    const values = {
        customer_name: props.customer.name,
        customer_phone: props.customer.phone,
        customer_email: props.customer.email ?? '',
        ...variableValues.value,
    };
    for (const [k, v] of Object.entries(values)) {
        body = body.replaceAll(`{{${k}}}`, v || `{{${k}}}`);
    }
    return body;
});

// ── Scroll ───────────────────────────────────────────────────────────────────
watch(() => messages.value.length, () => nextTick(scrollToBottom));
onMounted(() => nextTick(scrollToBottom));

function scrollToBottom() {
    if (scrollArea.value) scrollArea.value.scrollTop = scrollArea.value.scrollHeight;
}

function linkify(text) {
    if (!text) return '';
    
    // Escape HTML first to prevent XSS
    let html = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    
    // URL regex pattern
    const urlPattern = /(\b(?:https?:\/\/|www\.)[^\s<>"{}|\\^`\[\]]+[^\s<>"{}|\\^`\[\].,;:!?])/gi;
    
    return html.replace(urlPattern, (match) => {
        let url = match;
        let display = match;
        
        // If it starts with www. but no protocol, add https://
        if (url.startsWith('www.') && !url.startsWith('http')) {
            url = 'https://' + url;
        }
        
        // Clean trailing punctuation from display (but keep in href)
        display = display.replace(/[.,;:!?]+$/, '');
        
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline break-all">${display}</a>`;
    });
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
            m => String(m.id) === String(data.message_id) || String(m.id) === String(data.id)
        );
        if (msg) msg.status = data.status;
    },
});

// ── Template Functions ───────────────────────────────────────────────────────
function openTemplatePicker() {
    showTemplatePicker.value = true;
    templateSearch.value = '';
}

function closeTemplatePicker() {
    showTemplatePicker.value = false;
}

function selectTemplate(t) {
    selectedTemplate.value = t;
    variableValues.value = {};
    showTemplatePicker.value = false;
}

function closeVariableModal() {
    selectedTemplate.value = null;
    variableValues.value = {};
}

function formatVar(v) {
    return `{{${v}}}`;
}

function getAutoFillValue(v) {
    const map = {
        customer_name: props.customer.name,
        customer_phone: props.customer.phone,
        customer_email: props.customer.email ?? '—',
    };
    return map[v] ?? '—';
}

function categoryColor(key) {
    const colors = {
        general: 'bg-surface-100 text-surface-600',
        followup: 'bg-blue-100 text-blue-700',
        promo: 'bg-purple-100 text-purple-700',
        reminder: 'bg-amber-100 text-amber-700',
    };
    return colors[key] ?? 'bg-surface-100 text-surface-600';
}

function renderWaMarkdown(text) {
    if (!text) return '';
    let html = text
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')
        .replace(/_([^_\n]+)_/g, '<em>$1</em>')
        .replace(/~([^~\n]+)~/g, '<del>$1</del>')
        .replace(/\n/g, '<br>')
        .replace(/\{\{(\w+)\}\}/g, '<span class="wa-var">{{$1}}</span>');
    return html;
}

// Fill resolved template into the chat input box
function fillTemplateToInput() {
    messageText.value = previewBody.value;
    closeVariableModal();
    success('Template filled in chat box. Press Enter to send.');
}

// Send template directly without filling input
async function sendTemplateDirect() {
    if (!selectedTemplate.value) return;
    sendingTemplate.value = true;

    try {
        const { data } = await axios.post(route('templates.broadcast', selectedTemplate.value.id), {
            customer_ids: [props.customer.id],
            group_ids: [],
            variable_values: variableValues.value,
        });

        // Optimistically add to chat
        messages.value.push({
            id: `tmp-${Date.now()}`,
            direction: 'outbound',
            body: previewBody.value,
            status: 'pending',
            created_at: new Date().toISOString(),
            sent_by: { name: page.props.auth.user.name },
        });

        success('Template sent!');
        closeVariableModal();
        emit('message-sent');
        nextTick(scrollToBottom);
    } catch (err) {
        toastError(err.response?.data?.error ?? 'Failed to send template.');
    } finally {
        sendingTemplate.value = false;
    }
}

// ── File staging ─────────────────────────────────────────────────────────────
async function handleFileSelect(e) {
    const file = e.target.files[0];
    if (!file) return;
    e.target.value = '';

    if (file.size > 10 * 1024 * 1024) {
        toastError('File too large. Maximum size is 10 MB.');
        return;
    }

    stagedFile.value = file;
    stagedDocument.value = null;
    uploadProgress.value = 0;

    if (file.type.startsWith('image/')) {
        stagedPreviewUrl.value = URL.createObjectURL(file);
    } else {
        stagedPreviewUrl.value = null;
    }

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
        emit('document-updated');
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
    } catch (err){
        messages.value = messages.value.filter(m => m.id !== optimistic.id);
	const errorMsg = err.response?.data?.error 
            || err.response?.data?.message 
            || err.message 
            || 'Failed to send message. Check WhatsApp connection.';
        toastError(errorMsg);
    } finally {
        sending.value = false;
    }
}

async function sendDocument() {
    if (!stagedDocument.value || sending.value) return;

    sending.value = true;
    const caption = messageText.value.trim();
    const optimistic = {
        id: `opt-${Date.now()}`,
        direction: 'outbound',
        body: caption,
        status: 'pending',
        created_at: new Date().toISOString(),
        document: { ...stagedDocument.value },
    };
    messages.value.push(optimistic);
    messageText.value = '';
    const docRef = stagedDocument.value;
    clearStagedFile();

    try {
        const { data } = await axios.post(
            route('documents.send', { customer: props.customer.id, document: docRef.id }),
            { caption }
        );
        const idx = messages.value.findIndex(m => m.id === optimistic.id);
        if (idx !== -1) messages.value[idx] = { ...data.message, document: data.document };

        emit('message-sent');
        emit('document-updated');
        success('Document sent to customer.');
    } catch (err) {
        messages.value = messages.value.filter(m => m.id !== optimistic.id);
	const errorMsg = err.response?.data?.error 
            || err.response?.data?.message 
            || err.message 
            || 'Failed to send document.';
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

function isArihantMessage(msg) {
    return msg.session_id === 'arihant-special-session';
}

function avatarUrlFromName(name) {
    const safeName = name || 'User';
    return `https://ui-avatars.com/api/?name=${encodeURIComponent(safeName)}&background=e2e8f0&color=475569&size=64`;
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
.wa-var {
    display: inline-block;
    background: #e8f5e9;
    color: #1b5e20;
    border-radius: 4px;
    padding: 0 4px;
    font-family: monospace;
    font-size: 0.7rem;
    border: 1px solid #c8e6c9;
    line-height: 1.6;
}
</style>
