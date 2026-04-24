<template>
    <AppLayout :title="customer.name">
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('customers.index')"
                    class="text-surface-400 hover:text-surface-700 transition-colors text-sm">
                    ← Customers
                </Link>
                <span class="text-surface-300">/</span>
                <span class="text-sm font-semibold text-surface-900">{{ customer.name }}</span>
            </div>
        </template>

        <div class="flex h-[calc(100vh-56px)] overflow-hidden animate-fade-in">

            <!-- ── Left panel: customer info + documents ────────────────────── -->
            <aside class="w-72 shrink-0 border-r border-surface-100 bg-white flex flex-col overflow-hidden">

                <!-- Customer card -->
                <div class="p-5 border-b border-surface-100">
                    <div class="flex items-center gap-3 mb-4">
                        <img :src="avatarUrl" class="w-12 h-12 rounded-2xl object-cover" />
                        <div class="min-w-0">
                            <p class="font-semibold text-surface-900 text-sm">{{ customer.name }}</p>
                            <p class="text-xs font-mono text-surface-400">+{{ customer.phone }}</p>
                        </div>
                    </div>
                    <div class="space-y-2 text-xs">
                        <InfoRow icon="🏢" :value="customer.company" label="Company" />
                        <InfoRow icon="✉️" :value="customer.email" label="Email" />
                        <InfoRow icon="👤" :value="customer.assigned_to?.name" label="Executive" />
                        <div class="flex items-center justify-between pt-1">
                            <span class="text-surface-400">Status</span>
                            <span :class="['badge', statusClass(customer.status)]">{{ customer.status }}</span>
                        </div>
                    </div>
                    <button @click="showEditModal = true"
                        class="mt-4 w-full text-xs font-medium text-surface-600 border border-surface-200 rounded-xl py-2 hover:bg-surface-50 transition-colors">
                        Edit Customer
                    </button>
                </div>

                <!-- Documents panel -->
                <div class="flex-1 overflow-y-auto scrollbar-thin p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-xs font-semibold text-surface-700 uppercase tracking-wider">
                            Documents ({{ localDocs.length }})
                        </h3>
                    </div>

                    <div class="space-y-2">
                        <p v-if="localDocs.length === 0" class="text-xs text-surface-400 text-center py-6">
                            No documents yet. Attach a file in the chat to send one.
                        </p>

                        <div v-for="doc in localDocs" :key="doc.id"
                            class="bg-surface-50 rounded-xl border border-surface-100 px-3 py-2.5 group">
                            <!-- Top row: icon + name + download -->
                            <div class="flex items-center gap-2">
                                <span class="text-base shrink-0">{{ docIcon(doc.mime_type) }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-medium text-surface-700 truncate">{{ doc.original_filename }}
                                    </p>
                                    <p class="text-[10px] text-surface-400">{{ doc.formatted_size }}</p>
                                </div>
                                <a :href="route('documents.download', doc.id)"
                                    class="text-surface-300 hover:text-brand-500 transition-colors shrink-0"
                                    title="Download" target="_blank">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                </a>
                            </div>

                            <!-- Status row — source badge + action buttons -->
                            <div class="flex items-center gap-1.5 mt-2">
                                <!-- Source -->
                                <span class="text-[9px] font-medium text-surface-400 uppercase tracking-wide">
                                    {{ doc.source === 'whatsapp' ? '📲 WA' : '⬆ Manual' }}
                                </span>
                                <span class="text-surface-200">·</span>

                                <!-- Current status badge -->
                                <span :class="['badge text-[9px]', docStatusClass(doc.status)]">
                                    {{ doc.status }}
                                </span>

                                <!-- Action buttons — only show for inbound (received from customer) docs -->
                                <template v-if="doc.source === 'whatsapp' && doc.status === 'pending'">
                                    <button @click="updateDocStatus(doc, 'approved')" :disabled="doc._updating"
                                        class="ml-auto text-[9px] font-semibold px-2 py-0.5 rounded-lg bg-brand-500 text-white hover:bg-brand-600 transition-colors disabled:opacity-50"
                                        title="Approve this document">
                                        Approve
                                    </button>
                                    <button @click="updateDocStatus(doc, 'rejected')" :disabled="doc._updating"
                                        class="text-[9px] font-semibold px-2 py-0.5 rounded-lg bg-red-100 text-red-600 hover:bg-red-200 transition-colors disabled:opacity-50"
                                        title="Reject this document">
                                        Reject
                                    </button>
                                </template>

                                <!-- Re-open rejected -->
                                <button v-if="doc.status === 'rejected' && doc.source === 'whatsapp'"
                                    @click="updateDocStatus(doc, 'pending')" :disabled="doc._updating"
                                    class="ml-auto text-[9px] font-medium text-surface-400 hover:text-surface-600 underline transition-colors">
                                    Reset
                                </button>

                                <!-- Delete button -->
                                <button @click="deleteDoc(doc)"
                                    class="ml-auto text-surface-200 hover:text-red-400 transition-colors opacity-0 group-hover:opacity-100"
                                    title="Delete document">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- ── Right: chat window ───────────────────────────────────────── -->
            <div class="flex-1 overflow-hidden">
                <ChatWindow :customer="customer" :initial-messages="messages" @message-sent="markRead"
                    @document-updated="refreshDocs" />
            </div>
        </div>

        <!-- Edit modal -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="showEditModal = false" />
                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 animate-slide-up">
                        <h2 class="text-base font-semibold mb-5">Edit Customer</h2>
                        <CustomerForm :executives="executives" :customer="customer" @saved="onEditSaved"
                            @cancel="showEditModal = false" />
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import ChatWindow from '@/Components/Chat/ChatWindow.vue';
import CustomerForm from '@/Components/UI/CustomerForm.vue';
import InfoRow from '@/Components/UI/InfoRow.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    customer: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
    executives: { type: Array, default: () => [] },
});

const showEditModal = ref(false);
const { success, error: toastError } = useToast();

// Local reactive copy of documents so status updates reflect immediately
// without needing a full Inertia reload
const localDocs = ref(props.documents.map(d => ({ ...d, _updating: false })));

const avatarUrl = computed(() =>
    `https://ui-avatars.com/api/?name=${encodeURIComponent(props.customer.name)}&background=e2e8f0&color=475569&size=64`
);

async function markRead() {
    await axios.post(route('messages.mark-read', props.customer.id));
}

// Called by ChatWindow after a document is staged/sent
function refreshDocs() {
    router.reload({
        only: ['documents'], onSuccess: () => {
            localDocs.value = props.documents.map(d => ({ ...d, _updating: false }));
        }
    });
}

// Inline approve / reject — updates local state immediately, then persists
async function updateDocStatus(doc, newStatus) {
    doc._updating = true;
    const previous = doc.status;
    doc.status = newStatus; // optimistic

    try {
        const { data } = await axios.patch(
            route('documents.status', doc.id),
            { status: newStatus }
        );
        // Merge server response back (keeps formatted_size etc.)
        Object.assign(doc, data.document, { _updating: false });
        success(`Document marked as ${newStatus}.`);
    } catch {
        doc.status = previous; // rollback
        doc._updating = false;
        toastError('Could not update document status.');
    }
}

async function deleteDoc(doc) {
    if (!confirm(`Delete "${doc.original_filename}"?`)) return;
    try {
        await axios.delete(route('documents.destroy', doc.id));
        localDocs.value = localDocs.value.filter(d => d.id !== doc.id);
        success('Document deleted.');
    } catch {
        toastError('Could not delete document.');
    }
}

function onEditSaved() {
    showEditModal.value = false;
    router.reload({ only: ['customer', 'executives'] });
}

// Helpers
function statusClass(s) {
    return {
        active: 'bg-brand-100 text-brand-700',
        inactive: 'bg-surface-100 text-surface-500',
        blocked: 'bg-red-100 text-red-600',
    }[s] ?? '';
}
function docStatusClass(s) {
    return {
        pending: 'bg-amber-100 text-amber-700',
        approved: 'bg-brand-100 text-brand-700',
        rejected: 'bg-red-100 text-red-600',
    }[s] ?? '';
}
function docIcon(mime) {
    if (mime?.includes('pdf')) return '📄';
    if (mime?.includes('image')) return '🖼️';
    if (mime?.includes('video')) return '🎥';
    if (mime?.includes('audio')) return '🎵';
    return '📎';
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity .15s ease;
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
</style>