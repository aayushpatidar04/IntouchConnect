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
                <StatCard label="Unread Messages" :value="stats.unread_messages" icon="💬" color="amber"
                    :alert="stats.unread_messages > 0" />
                <StatCard label="Pending Docs" :value="stats.pending_documents" icon="📄" color="purple" />
                <StatCard label="Messages Today" :value="stats.messages_today" icon="📊" color="teal" />
            </div>

            <!-- Chart + Recent activity -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Message chart -->
                <div class="lg:col-span-2 bg-white rounded-2xl border border-surface-100 p-5">
                    <h3 class="text-sm font-semibold text-surface-700 mb-4">Message Volume — Last 7 Days</h3>
                    <MessageChart :data="messageChart" />
                </div>

                <!-- Recent messages -->
                <div class="bg-white rounded-2xl border border-surface-100 p-5 flex flex-col">
                    <h3 class="text-sm font-semibold text-surface-700 mb-4">Recent Activity</h3>
                    <div class="flex-1 space-y-3 overflow-y-auto scrollbar-thin">
                        <div v-for="msg in recentMessages" :key="msg.id" class="flex items-start gap-3 text-xs">
                            <span
                                :class="['flex-shrink-0 mt-0.5 w-2 h-2 rounded-full', msg.direction === 'inbound' ? 'bg-brand-400' : 'bg-blue-400']" />
                            <div class="min-w-0">
                                <Link :href="route('customers.show', msg.customer_id)"
                                    class="font-medium text-surface-800 hover:text-brand-600 truncate block">
                                    {{ msg.customer?.name ?? 'Unknown' }}
                                </Link>
                                <p class="text-surface-400 truncate">{{ msg.body || '[media]' }}</p>
                            </div>
                            <span class="text-surface-300 shrink-0 ml-auto">{{ timeAgo(msg.created_at) }}</span>
                        </div>
                        <p v-if="!recentMessages.length" class="text-xs text-surface-400 text-center py-6">No recent
                            messages
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Components/Layout/AppLayout.vue';
import WhatsAppStatus from '@/Components/UI/WhatsAppStatus.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import MessageChart from '@/Components/UI/MessageChart.vue';
import { Link } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';

defineProps({
    stats: { type: Object, required: true },
    recentMessages: { type: Array, default: () => [] },
    messageChart: { type: Array, default: () => [] },
    whatsappStatus: { type: Object, default: () => ({}) },
});

function timeAgo(ts) {
    if (!ts) return '';
    return formatDistanceToNow(new Date(ts), { addSuffix: true });
}
</script>