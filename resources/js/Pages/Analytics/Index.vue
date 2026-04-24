<template>
    <AppLayout title="Analytics">
        <div class="p-6 space-y-6 animate-fade-in">

            <!-- Period selector -->
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-surface-900">Performance Overview</h2>
                <div class="flex gap-2">
                    <button v-for="d in [7, 14, 30, 90]" :key="d" @click="changeDays(d)" :class="['px-3 py-1.5 rounded-xl text-xs font-medium transition-all',
                        currentDays === d
                            ? 'bg-brand-500 text-white shadow-sm'
                            : 'bg-white border border-surface-200 text-surface-600 hover:border-brand-300']">
                        {{ d }}d
                    </button>
                </div>
            </div>

            <!-- KPI row -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <StatCard label="New Customers" :value="newCustomers" icon="👤" />
                <StatCard label="Active Customers" :value="activeCustomers" icon="💬" />
                <StatCard label="Docs Pending" :value="documentStats.pending ?? 0" icon="📄" />
                <StatCard label="Docs Approved" :value="documentStats.approved ?? 0" icon="✅" color="green" />
            </div>

            <!-- Charts row -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Message volume chart -->
                <div class="lg:col-span-2 bg-white rounded-2xl border border-surface-100 p-5">
                    <h3 class="text-sm font-semibold text-surface-700 mb-1">Message Volume</h3>
                    <p class="text-xs text-surface-400 mb-4">Last {{ days }} days</p>
                    <MessageChart :data="volumeByDay" />
                </div>

                <!-- Document breakdown -->
                <div class="bg-white rounded-2xl border border-surface-100 p-5">
                    <h3 class="text-sm font-semibold text-surface-700 mb-4">Document Status</h3>
                    <div class="space-y-3">
                        <div v-for="(count, status) in documentStats" :key="status" class="flex items-center gap-3">
                            <span :class="['badge capitalize', docStatusClass(status)]">{{ status }}</span>
                            <div class="flex-1 bg-surface-100 rounded-full h-2 overflow-hidden">
                                <div :class="['h-full rounded-full transition-all', docBarColor(status)]"
                                    :style="{ width: docPercent(count) + '%' }" />
                            </div>
                            <span class="text-xs font-mono font-medium text-surface-700 w-8 text-right">{{ count
                                }}</span>
                        </div>
                        <p v-if="!Object.keys(documentStats).length" class="text-xs text-surface-400 text-center py-4">
                            No documents in period</p>
                    </div>
                </div>
            </div>

            <!-- Executive leaderboard + response times -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Top executives -->
                <div class="bg-white rounded-2xl border border-surface-100 p-5">
                    <h3 class="text-sm font-semibold text-surface-700 mb-4">Messages Sent by Executive</h3>
                    <div class="space-y-2">
                        <div v-for="(exec, i) in topExecutives" :key="exec.id" class="flex items-center gap-3">
                            <span class="w-5 text-xs text-surface-400 font-mono text-right shrink-0">#{{ i + 1 }}</span>
                            <span class="text-sm font-medium text-surface-800 flex-1 truncate">{{ exec.name }}</span>
                            <div class="w-24 bg-surface-100 rounded-full h-1.5 overflow-hidden">
                                <div class="h-full bg-brand-400 rounded-full"
                                    :style="{ width: execPercent(exec.messages_sent) + '%' }" />
                            </div>
                            <span class="text-xs font-mono text-surface-500 w-8 text-right shrink-0">{{
                                exec.messages_sent }}</span>
                        </div>
                        <p v-if="!topExecutives.length" class="text-xs text-surface-400 text-center py-4">No data</p>
                    </div>
                </div>

                <!-- Avg response time -->
                <div class="bg-white rounded-2xl border border-surface-100 p-5">
                    <h3 class="text-sm font-semibold text-surface-700 mb-4">Avg. Response Time</h3>
                    <div class="space-y-2">
                        <div v-for="row in responseTime" :key="row.name" class="flex items-center gap-3">
                            <span class="text-sm text-surface-700 flex-1 truncate">{{ row.name }}</span>
                            <span
                                :class="['text-xs font-mono font-semibold', row.avg_minutes < 30 ? 'text-brand-600' : row.avg_minutes < 120 ? 'text-amber-600' : 'text-red-500']">
                                {{ formatMinutes(row.avg_minutes) }}
                            </span>
                        </div>
                        <p v-if="!responseTime.length" class="text-xs text-surface-400 text-center py-4">Insufficient
                            data</p>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import MessageChart from '@/Components/UI/MessageChart.vue';
import StatCard from '@/Components/UI/StatCard.vue';

const props = defineProps({
    volumeByDay: { type: Array, default: () => [] },
    topExecutives: { type: Array, default: () => [] },
    responseTime: { type: Array, default: () => [] },
    documentStats: { type: Object, default: () => ({}) },
    newCustomers: { type: Number, default: 0 },
    activeCustomers: { type: Number, default: 0 },
    days: { type: Number, default: 30 },
});

const currentDays = computed(() => props.days);

const maxMessages = computed(() =>
    Math.max(...props.topExecutives.map(e => e.messages_sent), 1)
);

const totalDocs = computed(() =>
    Object.values(props.documentStats).reduce((s, v) => s + v, 0) || 1
);

function changeDays(d) {
    router.get(route('analytics.index'), { days: d }, { preserveState: true });
}

function execPercent(count) { return Math.round((count / maxMessages.value) * 100); }
function docPercent(count) { return Math.round((count / totalDocs.value) * 100); }

function docStatusClass(s) {
    return { pending: 'bg-amber-100 text-amber-700', approved: 'bg-brand-100 text-brand-700', rejected: 'bg-red-100 text-red-600' }[s] ?? '';
}
function docBarColor(s) {
    return { pending: 'bg-amber-400', approved: 'bg-brand-400', rejected: 'bg-red-400' }[s] ?? 'bg-surface-300';
}

function formatMinutes(m) {
    if (!m) return '—';
    if (m < 60) return `${m}m`;
    if (m < 1440) return `${Math.round(m / 60)}h`;
    return `${Math.round(m / 1440)}d`;
}
</script>