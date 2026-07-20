<template>
    <SuperAdminLayout title="Platform Overview">
        <div class="p-6 space-y-6 animate-fade-in">
	    <button
	        type="button"
	        :disabled="syncing"
	        @click="syncNewUsers"
	        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
	    >
        	{{
	            syncing
                	? 'Syncing...'
        	        : 'Sync New Users'
	        }}
	    </button>
            <!-- Platform stats -->
            <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                <StatCard label="Total Companies"  :value="stats.total_companies"  icon="🏢" color="blue" />
                <StatCard label="Active Companies" :value="stats.active_companies"  icon="✅" color="green" />
                <StatCard label="Total Users"      :value="stats.total_users"       icon="👥" color="purple" />
                <StatCard label="Sessions Online"  :value="stats.sessions_online"   icon="🟢" color="teal"
                    :alert="stats.sessions_online === 0" />
                <StatCard label="Sessions Offline" :value="stats.sessions_offline"  icon="🔴" color="amber"
                    :alert="stats.sessions_offline > 0" />
                <StatCard label="Waiting for QR"   :value="stats.sessions_qr"       icon="📱" color="blue"
                    :alert="stats.sessions_qr > 0" />
            </div>

            <!-- Companies table + growth chart -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

                <!-- Companies table — takes 2/3 width -->
                <div class="xl:col-span-2 bg-white rounded-2xl border border-surface-100 overflow-hidden">
                    <div class="px-5 py-4 border-b border-surface-100 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-surface-700">All Companies</h3>
                        <Link :href="route('superadmin.companies.index')"
                            class="text-xs text-brand-500 hover:text-brand-700 font-medium">
                            Manage all →
                        </Link>
                    </div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-surface-50 bg-surface-50">
                                <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Company</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">Admin</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">WhatsApp</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">Users</th>
                                <th class="px-5 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-50">
                            <tr v-for="c in companies" :key="c.id" class="hover:bg-surface-50 transition-colors group">
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-xl bg-brand-100 flex items-center justify-center shrink-0">
                                            <span class="text-brand-600 font-bold text-xs">{{ c.name.charAt(0).toUpperCase() }}</span>
                                        </div>
                                        <div>
                                            <p class="font-medium text-surface-900">{{ c.name }}</p>
                                            <p class="text-xs text-surface-400 font-mono">{{ c.slug }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 hidden md:table-cell">
                                    <p class="text-xs text-surface-700">{{ c.admin_name ?? '—' }}</p>
                                    <p class="text-xs text-surface-400">{{ c.admin_email ?? '' }}</p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <SessionBadge :status="c.session_status" :phone="c.session_phone" />
                                </td>
                                <td class="px-5 py-3.5 hidden lg:table-cell text-xs text-surface-500">
                                    {{ c.users_count }}
                                </td>
                                <td class="px-5 py-3.5 text-right opacity-0 group-hover:opacity-100 transition-opacity">
                                    <Link :href="route('superadmin.companies.show', c.id)"
                                        class="text-xs text-brand-500 hover:text-brand-700 font-medium">
                                        View →
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!companies.length">
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-surface-400">
                                    No companies yet.
                                    <Link :href="route('superadmin.companies.index')"
                                        class="text-brand-500 hover:underline ml-1">Add one</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Right column: growth chart + gateway health -->
                <div class="space-y-6">

                    <!-- Company growth chart -->
                    <div class="bg-white rounded-2xl border border-surface-100 p-5">
                        <h3 class="text-sm font-semibold text-surface-700 mb-4">New Companies — Last 6 Months</h3>
                        <div class="flex items-end gap-2 h-24">
                            <template v-if="companyGrowth.length">
                                <div v-for="item in paddedGrowth" :key="item.month"
                                    class="flex-1 flex flex-col items-center gap-1">
                                    <span class="text-xs text-surface-400">{{ item.total }}</span>
                                    <div class="w-full rounded-t-md bg-brand-400 transition-all"
                                        :style="{ height: barHeight(item.total) }" />
                                    <span class="text-xs text-surface-300">{{ item.label }}</span>
                                </div>
                            </template>
                            <p v-else class="text-xs text-surface-400 w-full text-center">No data yet</p>
                        </div>
                    </div>

                    <!-- Live gateway sessions -->
                    <div class="bg-white rounded-2xl border border-surface-100 p-5">
                        <h3 class="text-sm font-semibold text-surface-700 mb-4">Live Gateway Sessions</h3>
                        <div class="space-y-2">
                            <template v-if="Object.keys(gatewaySessions).length">
                                <div v-for="(s, id) in gatewaySessions" :key="id"
                                    class="flex items-center justify-between text-xs">
                                    <span class="font-mono text-surface-600">{{ id }}</span>
                                    <SessionBadge :status="s.status" :phone="s.phone" />
                                </div>
                            </template>
                            <p v-else class="text-xs text-surface-400 text-center py-2">No active sessions</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SuperAdminLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Components/Layout/SuperAdminLayout.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const syncing = ref(false);

function syncNewUsers() {
    syncing.value = true;

    router.post(
        route('superadmin.sync-new-users'),
        {},
        {
            preserveScroll: true,

            onFinish: () => {
                syncing.value = false;
            },
        }
    );
}
const props = defineProps({
    stats:           { type: Object, required: true },
    companies:       { type: Array,  default: () => [] },
    companyGrowth:   { type: Array,  default: () => [] },
    gatewaySessions: { type: Object, default: () => ({}) },
});

// ── Session status badge ──────────────────────────────────────────────────────
const SessionBadge = {
    props: { status: String, phone: String },
    template: `
        <span :class="cls" class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium">
            <span :class="dotCls" class="w-1.5 h-1.5 rounded-full" />
            {{ label }}
        </span>
    `,
    computed: {
        cls() {
            return {
                connected:    'bg-brand-50 text-brand-700',
                qr_ready:     'bg-amber-50 text-amber-700',
                disconnected: 'bg-surface-100 text-surface-500',
                failed:       'bg-red-50 text-red-600',
            }[this.status] ?? 'bg-surface-100 text-surface-500';
        },
        dotCls() {
            return {
                connected:    'bg-brand-500',
                qr_ready:     'bg-amber-400 animate-pulse',
                disconnected: 'bg-surface-400',
                failed:       'bg-red-500',
            }[this.status] ?? 'bg-surface-400';
        },
        label() {
            if (this.status === 'connected' && this.phone) return `+${this.phone}`;
            return { connected: 'Connected', qr_ready: 'Scan QR', disconnected: 'Offline', failed: 'Failed' }[this.status] ?? this.status;
        },
    },
};

// ── Bar chart helpers ─────────────────────────────────────────────────────────
const MONTH_LABELS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

const paddedGrowth = computed(() => {
    // Ensure we always show 6 months even if some have no data
    const months = [];
    const now = new Date();
    for (let i = 5; i >= 0; i--) {
        const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
        const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
        const found = props.companyGrowth.find(g => g.month === key);
        months.push({ month: key, total: found?.total ?? 0, label: MONTH_LABELS[d.getMonth()] });
    }
    return months;
});

const maxGrowth = computed(() => Math.max(...paddedGrowth.value.map(g => g.total), 1));

function barHeight(total) {
    const pct = (total / maxGrowth.value) * 100;
    return `${Math.max(pct, 4)}%`;
}
</script>
