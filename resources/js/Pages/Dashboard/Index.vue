<template>
    <AppLayout title="Dashboard">
        <template #actions>
            <WhatsAppStatus :show-always="true" :show-logout="true" />
        </template>

        <div class="p-6 space-y-6 animate-fade-in">
            <!-- Stats grid -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <StatCard label="Total Customers" :value="stats.total_customers" icon="👥" color="blue" />
                <StatCard label="Active" :value="stats.active_customers" icon="✅" color="green" />
                <StatCard label="Unread Messages" :value="unreadCount" icon="💬" color="amber" :alert="unreadCount > 0" />
                <StatCard label="Pending Docs" :value="stats.pending_documents" icon="📄" color="purple" />
                <StatCard label="Messages Today" :value="stats.messages_today" icon="📊" color="teal" />
            </div>

            <!-- Chart + Recent activity + Unread Messages -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Message chart -->
                <div class="lg:col-span-2 bg-white rounded-2xl border border-surface-100 p-5">
                    <h3 class="text-sm font-semibold text-surface-700 mb-4">Message Volume — Last 7 Days</h3>
                    <MessageChart :data="messageChart" />
                </div>

                <!-- Unread Messages (auto-refreshing) -->
                <div class="bg-white rounded-2xl border border-surface-100 p-5 flex flex-col">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-semibold text-surface-700">
                            Unread Messages
                            <span v-if="unreadCount > 0" class="ml-1.5 badge bg-red-100 text-red-600 text-[10px]">
                                {{ unreadCount }}
                            </span>
                        </h3>
                        <span class="flex items-center gap-1.5">
                            <span :class="['w-1.5 h-1.5 rounded-full animate-pulse', isRefreshing ? 'bg-brand-400' : 'bg-surface-300']" />
                            <span class="text-[10px] text-surface-400">{{ lastRefresh }}</span>
                        </span>
                    </div>

                    <div class="flex-1 space-y-2 overflow-y-auto scrollbar-thin max-h-[320px]">
                        <div v-for="msg in unreadMessages" :key="msg.id"
                            class="flex items-start gap-3 p-2.5 rounded-xl hover:bg-surface-50 transition-colors group cursor-pointer"
                            @click="goToChat(msg.customer_id)">
                            <div class="relative shrink-0">
                                <div class="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-brand-600 text-xs font-semibold">
                                    {{ msg.customer_name?.charAt(0).toUpperCase() ?? '?' }}
                                </div>
                                <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5">
                                    <p class="text-xs font-medium text-surface-800 truncate">{{ msg.customer_name }}</p>
                                    <span class="text-[10px] text-surface-400 shrink-0">{{ msg.time_ago }}</span>
                                </div>
                                <p class="text-xs text-surface-500 truncate mt-0.5">{{ msg.body || '[media]' }}</p>
                                <p class="text-[10px] text-surface-400 font-mono mt-0.5">+{{ msg.customer_phone }}</p>
                            </div>
                        </div>

                        <div v-if="unreadMessages.length === 0" class="text-center py-8">
                            <p class="text-2xl mb-2">✅</p>
                            <p class="text-xs text-surface-400">All caught up!</p>
                            <p class="text-[10px] text-surface-300 mt-1">No unread messages</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity (full width below) -->
	    <div class="bg-white rounded-2xl border border-surface-100 p-5">
        	<!-- Header -->
 	        <div class="flex items-center justify-between mb-4">
            	<h3 class="text-sm font-semibold text-surface-700">Recent Activity</h3>
	        <span v-if="recentMessages.total" class="text-[10px] text-surface-400 bg-surface-100 px-2 py-0.5 rounded-full">
                    {{ recentMessages.total }} chats
                </span>
            </div>

            <!-- Search -->
            <div class="mb-3">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search customers or messages..."
                    class="w-full text-xs px-3 py-2 rounded-lg border border-surface-200 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent placeholder:text-surface-400"
                />
            </div>

            <!-- List -->
            <div class="space-y-1">
                <Link
                    v-for="msg in recentMessages.data"
                    :key="msg.id"
                    :href="route('customers.show', msg.customer_id)"
                    class="flex items-center gap-3 p-3 rounded-xl hover:bg-surface-50 transition-colors group"
                >
                    <!-- Avatar -->
                    <div class="relative shrink-0">
                        <img
                            v-if="msg.customer?.avatar"
                            :src="msg.customer.avatar"
                            class="w-9 h-9 rounded-full object-cover"
                        />
                        <div
                            v-else
                            class="w-9 h-9 rounded-full bg-surface-200 flex items-center justify-center text-xs font-medium text-surface-500"
                        >
                            {{ (msg.customer?.name ?? '?').charAt(0).toUpperCase() }}
                        </div>
                        <!-- Direction indicator dot -->
                        <span
                            :class="[
                                'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                                msg.direction === 'inbound' ? 'bg-emerald-400' : 'bg-blue-400'
                            ]"
                        />
                    </div>
 
                    <!-- Content -->
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-surface-800 group-hover:text-brand-600 truncate">
                                {{ msg.customer?.name ?? 'Unknown' }}
                            </span>
                            <span v-if="msg.unread" class="w-1.5 h-1.5 rounded-full bg-brand-500" />
                        </div>
                        <p class="text-xs text-surface-500 truncate mt-0.5">
                            <span
                                :class="msg.direction === 'inbound' ? 'text-emerald-600' : 'text-blue-600'"
                                class="font-medium text-[10px] uppercase tracking-wide mr-1"
                            >
                                {{ msg.direction === 'inbound' ? 'In' : 'Out' }}
                            </span>
                            {{ msg.body || '[media]' }}
                        </p>
                    </div>

                    <!-- Time -->
                    <div class="shrink-0 text-right">
                        <span class="text-[11px] text-surface-400">{{ timeAgo(msg.created_at) }}</span>
                        <p
                            v-if="msg.customer?.company"
                            class="text-[10px] text-surface-300 truncate max-w-[80px]"
                        >
                            {{ msg.customer.company }}
                        </p>
                    </div>
                </Link>

                <p v-if="!recentMessages.data.length" class="text-xs text-surface-400 text-center py-8">
                    No recent messages
                </p>
            </div>

            <!-- Pagination -->
            <div v-if="recentMessages.links.length > 3" class="mt-4 flex justify-center gap-1">
                <Link
                    v-for="(link, i) in recentMessages.links"
                    :key="i"
                    :href="link.url"
                    :class="[
                        'px-2.5 py-1 text-[11px] rounded-md transition-colors',
                        link.active
                            ? 'bg-brand-600 text-white font-medium'
                            : 'text-surface-500 hover:bg-surface-100',
                        !link.url && 'opacity-50 cursor-not-allowed'
                    ]"
                    v-html="link.label"
                    preserve-state
                    preserve-scroll
                />
            </div>
        </div>


        </div>
    </AppLayout>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import WhatsAppStatus from '@/Components/UI/WhatsAppStatus.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import MessageChart from '@/Components/UI/MessageChart.vue';
import { formatDistanceToNow } from 'date-fns';
import axios from 'axios';
import debounce from 'lodash/debounce';

const props = defineProps({
    stats: { type: Object, required: true },
    recentMessages: Object, // paginator: { data: [], links: [], ... }
    filters: Object,
    messageChart: { type: Array, default: () => [] },
    whatsappStatus: { type: Object, default: () => ({}) },
});

const search = ref(props.filters?.search ?? '');

// Debounced search — updates URL and refreshes data
watch(search, debounce((value) => {
    router.get(
        route('dashboard'), // replace with your actual route
        { search: value, page: 1 }, // reset to page 1 on new search
        { preserveState: true, preserveScroll: true, replace: true }
    );
}, 300));

// ── Reactive unread state ────────────────────────────────────────────────────
const unreadCount = ref(0);
const unreadMessages = ref([]);
const isRefreshing = ref(false);
const lastRefresh = ref('Just now');

// ── Polling ──────────────────────────────────────────────────────────────────
let pollInterval = null;
const POLL_INTERVAL_MS = 30_000; // 1 minute

async function fetchUnreadMessages() {
    isRefreshing.value = true;
    try {
        const { data } = await axios.get(route('api.unread-messages'));
        unreadCount.value = data.unread_count;
        unreadMessages.value = data.unread_messages;
        lastRefresh.value = new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
    } catch (err) {
        console.error('Failed to fetch unread messages:', err);
    } finally {
        isRefreshing.value = false;
    }
}

function goToChat(customerId) {
    router.visit(route('customers.show', customerId));
}

function timeAgo(ts) {
    if (!ts) return '';
    return formatDistanceToNow(new Date(ts), { addSuffix: true });
}

// ── Lifecycle ────────────────────────────────────────────────────────────────
onMounted(() => {
    // Initial fetch
    fetchUnreadMessages();
    // Start polling
    pollInterval = setInterval(fetchUnreadMessages, POLL_INTERVAL_MS);
});

onUnmounted(() => {
    if (pollInterval) {
        clearInterval(pollInterval);
    }
});
</script>
