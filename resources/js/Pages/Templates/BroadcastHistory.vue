<template>
    <AppLayout title="Broadcast History">
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('templates.index')" class="text-surface-400 hover:text-surface-700 text-sm">
                    ← Templates
                </Link>
                <span class="text-surface-300">/</span>
                <h1 class="text-base font-semibold text-surface-900">Broadcast History</h1>
            </div>
        </template>

        <div class="p-6 space-y-4 animate-fade-in">

            <!-- Summary stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="Total Broadcasts" :value="broadcasts.total" icon="📡" color="blue" />
                <StatCard label="Running" :value="runningCount" icon="⚡" color="teal" :alert="runningCount > 0" />
                <StatCard label="Completed" :value="completedCount" icon="✅" color="green" />
                <StatCard label="Failed" :value="failedCount" icon="❌" color="amber" :alert="failedCount > 0" />
            </div>

            <!-- Broadcasts table -->
            <div class="bg-white rounded-2xl border border-surface-100 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-surface-100 bg-surface-50">
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Template</th>
                            <th v-if="isAdmin"
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">
                                Sent by</th>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Progress</th>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">
                                Started</th>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Status</th>
                            <th class="px-5 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-50">
                        <tr v-for="b in broadcasts.data" :key="b.id"
                            class="hover:bg-surface-50 transition-colors group">
                            <td class="px-5 py-3.5">
                                <p class="font-medium text-surface-900">{{ b.template?.name ?? 'Deleted template' }}</p>
                                <span v-if="b.template?.category"
                                    :class="['badge text-[10px]', categoryColor(b.template.category)]">
                                    {{ b.template.category }}
                                </span>
                            </td>
                            <td v-if="isAdmin" class="px-5 py-3.5 hidden md:table-cell text-xs text-surface-500">
                                {{ b.sent_by?.name ?? '—' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="space-y-1 min-w-[140px]">
                                    <!-- Progress bar -->
                                    <div class="h-1.5 bg-surface-100 rounded-full overflow-hidden">
                                        <div class="h-full bg-brand-500 rounded-full transition-all duration-500"
                                            :style="{ width: progressPct(b) + '%' }" />
                                    </div>
                                    <p class="text-[10px] text-surface-400">
                                        {{ b.sent_count }} sent · {{ b.failed_count }} failed · {{ b.pending_count }}
                                        pending
                                        / {{ b.total_recipients }} total
                                    </p>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 hidden lg:table-cell text-xs text-surface-400">
                                {{ b.started_at ? formatDate(b.started_at) : '—' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <span :class="['badge', statusClass(b.status)]">{{ b.status }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-right opacity-0 group-hover:opacity-100 transition-opacity">
                                <button @click="openDetail(b)"
                                    class="text-xs text-brand-500 hover:text-brand-700 font-medium">
                                    Details →
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!broadcasts.data.length">
                            <td :colspan="isAdmin ? 6 : 5" class="text-center py-12 text-sm text-surface-400">
                                No broadcasts yet. Go to Templates to send one.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="broadcasts.last_page > 1"
                    class="border-t border-surface-50 px-5 py-3 flex items-center justify-between">
                    <p class="text-xs text-surface-400">{{ broadcasts.from }}–{{ broadcasts.to }} of {{ broadcasts.total
                        }}</p>
                    <div class="flex gap-2">
                        <Link v-if="broadcasts.prev_page_url" :href="broadcasts.prev_page_url"
                            class="px-3 py-1.5 text-xs rounded-xl bg-surface-100 hover:bg-surface-200">← Prev</Link>
                        <Link v-if="broadcasts.next_page_url" :href="broadcasts.next_page_url"
                            class="px-3 py-1.5 text-xs rounded-xl bg-surface-100 hover:bg-surface-200">Next →</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detail drawer -->
        <Teleport to="body">
            <Transition name="drawer">
                <div v-if="showDetail" class="fixed inset-0 z-50 flex justify-end">
                    <div class="absolute inset-0 bg-black/30" @click="showDetail = false" />
                    <div class="relative bg-white w-full max-w-lg h-full flex flex-col shadow-2xl">
                        <div class="p-5 border-b border-surface-100 flex items-center justify-between shrink-0">
                            <div>
                                <h2 class="font-semibold text-sm">Broadcast #{{ detail?.broadcast?.id }}</h2>
                                <p class="text-xs text-surface-400">{{ detail?.broadcast?.total_recipients }} recipients
                                </p>
                            </div>
                            <button @click="showDetail = false"
                                class="text-surface-400 hover:text-surface-700">✕</button>
                        </div>

                        <!-- Progress summary -->
                        <div v-if="detail" class="p-5 border-b border-surface-100 shrink-0 space-y-3">
                            <div class="h-2 bg-surface-100 rounded-full overflow-hidden">
                                <div class="h-full bg-brand-500 rounded-full transition-all"
                                    :style="{ width: detail.broadcast.progress_percent + '%' }" />
                            </div>
                            <div class="flex justify-between text-xs text-surface-500">
                                <span>✅ {{ detail.broadcast.sent_count }} sent</span>
                                <span>❌ {{ detail.broadcast.failed_count }} failed</span>
                                <span>⏳ {{ detail.broadcast.pending_count }} pending</span>
                            </div>
                        </div>

                        <!-- Recipients list -->
                        <div class="flex-1 overflow-y-auto">
                            <!-- Search -->
                            <div class="p-4 border-b border-surface-50">
                                <input v-model="detailSearch" placeholder="Search customers…"
                                    class="w-full rounded-xl border border-surface-200 px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-400" />
                            </div>

                            <div v-if="loadingDetail" class="flex items-center justify-center py-16">
                                <div
                                    class="w-6 h-6 border-2 border-brand-400 border-t-brand-200 rounded-full animate-spin" />
                            </div>

                            <div v-else class="divide-y divide-surface-50">
                                <div v-for="r in filteredRecipients" :key="r.id"
                                    class="flex items-center gap-3 px-5 py-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium text-surface-800">{{ r.customer_name }}</p>
                                        <p class="text-[10px] font-mono text-surface-400">+{{ r.customer_phone }}</p>
                                        <p v-if="r.failure_reason" class="text-[10px] text-red-500 mt-0.5">{{
                                            r.failure_reason
                                            }}</p>
                                    </div>
                                    <span :class="['badge text-[10px] shrink-0', recipientStatusClass(r.status)]">
                                        {{ r.status }}
                                    </span>
                                    <span v-if="r.sent_at" class="text-[10px] text-surface-300 shrink-0">
                                        {{ timeAgo(r.sent_at) }}
                                    </span>
                                </div>
                                <p v-if="!filteredRecipients.length" class="text-xs text-surface-400 text-center py-8">
                                    No recipients found.
                                </p>
                            </div>
                        </div>

                        <!-- Refresh for running broadcasts -->
                        <div v-if="detail?.broadcast?.status === 'running'"
                            class="p-4 border-t border-surface-100 shrink-0">
                            <button @click="refreshDetail"
                                class="w-full text-xs text-brand-500 hover:text-brand-700 font-medium py-2 rounded-xl hover:bg-brand-50 transition-colors">
                                ↻ Refresh Progress
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import axios from 'axios';
import { formatDistanceToNow } from 'date-fns';

const props = defineProps({
    broadcasts: { type: Object, required: true },
    isAdmin: { type: Boolean, default: false },
});

// ── Stats ─────────────────────────────────────────────────────────────────────
const runningCount = computed(() => props.broadcasts.data.filter(b => b.status === 'running').length);
const completedCount = computed(() => props.broadcasts.data.filter(b => b.status === 'completed').length);
const failedCount = computed(() => props.broadcasts.data.filter(b => b.status === 'failed').length);

// ── Helpers ───────────────────────────────────────────────────────────────────
const CATEGORY_COLORS = {
    general: 'bg-surface-100 text-surface-600',
    followup: 'bg-blue-100 text-blue-700',
    promo: 'bg-purple-100 text-purple-700',
    reminder: 'bg-amber-100 text-amber-700',
};
function categoryColor(key) { return CATEGORY_COLORS[key] ?? 'bg-surface-100 text-surface-600'; }

function progressPct(b) {
    if (!b.total_recipients) return 0;
    return Math.round(((b.sent_count + b.failed_count) / b.total_recipients) * 100);
}

function statusClass(s) {
    return { running: 'bg-blue-100 text-blue-700', completed: 'bg-brand-100 text-brand-700', failed: 'bg-red-100 text-red-600', pending: 'bg-amber-100 text-amber-700' }[s] ?? 'bg-surface-100 text-surface-500';
}

function recipientStatusClass(s) {
    return { sent: 'bg-brand-100 text-brand-700', delivered: 'bg-teal-100 text-teal-700', read: 'bg-green-100 text-green-700', failed: 'bg-red-100 text-red-600', queued: 'bg-blue-100 text-blue-700', pending: 'bg-amber-100 text-amber-700' }[s] ?? 'bg-surface-100 text-surface-500';
}

function formatDate(ts) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function timeAgo(ts) {
    if (!ts) return '';
    return formatDistanceToNow(new Date(ts), { addSuffix: true });
}

// ── Detail drawer ─────────────────────────────────────────────────────────────
const showDetail = ref(false);
const loadingDetail = ref(false);
const detail = ref(null);
const detailSearch = ref('');

const filteredRecipients = computed(() => {
    if (!detail.value?.recipients) return [];
    const q = detailSearch.value.toLowerCase();
    if (!q) return detail.value.recipients;
    return detail.value.recipients.filter(r =>
        r.customer_name.toLowerCase().includes(q) || r.customer_phone.includes(q)
    );
});

async function openDetail(b) {
    showDetail.value = true;
    loadingDetail.value = true;
    detail.value = null;
    detailSearch.value = '';
    try {
        const { data } = await axios.get(route('templates.broadcasts.show', b.id));
        detail.value = data;
    } catch {
        showDetail.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

async function refreshDetail() {
    if (!detail.value?.broadcast?.id) return;
    loadingDetail.value = true;
    try {
        const { data } = await axios.get(route('templates.broadcasts.show', detail.value.broadcast.id));
        detail.value = data;
    } finally {
        loadingDetail.value = false;
    }
}
</script>

<style scoped>
.drawer-enter-active,
.drawer-leave-active {
    transition: opacity .2s ease;
}

.drawer-enter-active .relative,
.drawer-leave-active .relative {
    transition: transform .2s ease;
}

.drawer-enter-from,
.drawer-leave-to {
    opacity: 0;
}

.drawer-enter-from .relative {
    transform: translateX(100%);
}
</style>