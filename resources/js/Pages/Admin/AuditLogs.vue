<template>
    <AppLayout title="Audit Logs">
        <div class="p-6 animate-fade-in">
            <!-- Filters -->
            <div class="flex flex-wrap gap-3 mb-5">
                <select v-model="filters.user_id" @change="applyFilters"
                    class="rounded-xl border border-surface-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <option value="">All users</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <input v-model="filters.action" @input="debouncedFilter" type="text" placeholder="Filter by action…"
                    class="flex-1 min-w-[180px] rounded-xl border border-surface-200 bg-white px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
            </div>

            <!-- Logs table -->
            <div class="bg-white rounded-2xl border border-surface-100 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-surface-100 bg-surface-50">
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Time</th>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                User</th>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Action</th>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">
                                IP</th>
                            <th class="px-5 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-50">
                        <tr v-if="logs.data.length === 0">
                            <td colspan="5" class="text-center py-10 text-sm text-surface-400">No audit logs found</td>
                        </tr>
                        <tr v-for="log in logs.data" :key="log.id" class="hover:bg-surface-50 transition-colors group">
                            <td class="px-5 py-3 text-xs text-surface-400 font-mono whitespace-nowrap">
                                {{ formatDate(log.created_at) }}
                            </td>
                            <td class="px-5 py-3">
                                <span class="text-xs font-medium text-surface-700">{{ log.user?.name ?? 'System'
                                    }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span :class="['badge font-mono text-xs', actionClass(log.action)]">{{ log.action
                                    }}</span>
                            </td>
                            <td class="px-5 py-3 hidden lg:table-cell text-xs text-surface-400 font-mono">{{
                                log.ip_address ?? '—' }}</td>
                            <td class="px-5 py-3 text-right">
                                <button v-if="log.new_values || log.old_values" @click="viewDetail(log)"
                                    class="text-xs text-brand-500 hover:text-brand-700 opacity-0 group-hover:opacity-100 transition-opacity">
                                    Details
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="logs.last_page > 1"
                    class="border-t border-surface-50 px-5 py-3 flex items-center justify-between">
                    <p class="text-xs text-surface-400">{{ logs.from }}–{{ logs.to }} of {{ logs.total }}</p>
                    <div class="flex gap-1">
                        <Link v-if="logs.prev_page_url" :href="logs.prev_page_url"
                            class="px-3 py-1.5 text-xs rounded-lg bg-surface-100 hover:bg-surface-200">← Prev</Link>
                        <Link v-if="logs.next_page_url" :href="logs.next_page_url"
                            class="px-3 py-1.5 text-xs rounded-lg bg-surface-100 hover:bg-surface-200">Next →</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detail modal -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="detailLog" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="detailLog = null" />
                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 animate-slide-up">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-semibold">Log Detail</h2>
                            <button @click="detailLog = null" class="text-surface-400 hover:text-surface-700">✕</button>
                        </div>
                        <dl class="space-y-3 text-sm">
                            <div class="flex gap-3">
                                <dt class="text-surface-400 w-28 shrink-0">Action</dt>
                                <dd class="font-mono font-medium">{{ detailLog.action }}</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="text-surface-400 w-28 shrink-0">User</dt>
                                <dd>{{ detailLog.user?.name ?? 'System' }}</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="text-surface-400 w-28 shrink-0">Time</dt>
                                <dd class="font-mono text-xs">{{ formatDate(detailLog.created_at) }}</dd>
                            </div>
                            <div v-if="detailLog.old_values" class="flex gap-3">
                                <dt class="text-surface-400 w-28 shrink-0">Old Values</dt>
                                <dd class="flex-1 bg-red-50 rounded-lg p-2 text-xs font-mono overflow-auto max-h-32">{{
                                    JSON.stringify(detailLog.old_values, null, 2) }}</dd>
                            </div>
                            <div v-if="detailLog.new_values" class="flex gap-3">
                                <dt class="text-surface-400 w-28 shrink-0">New Values</dt>
                                <dd class="flex-1 bg-brand-50 rounded-lg p-2 text-xs font-mono overflow-auto max-h-32">
                                    {{ JSON.stringify(detailLog.new_values, null, 2) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { format } from 'date-fns';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
    logs: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const filters = reactive({ ...props.filters });
const detailLog = ref(null);

const debouncedFilter = useDebounceFn(applyFilters, 400);

function applyFilters() {
    router.get(route('admin.audit-logs'), filters, { preserveState: true, replace: true });
}

function viewDetail(log) { detailLog.value = log; }

function formatDate(ts) {
    if (!ts) return '';
    return format(new Date(ts), 'yyyy-MM-dd HH:mm:ss');
}

function actionClass(action) {
    if (action.includes('created')) return 'bg-brand-100 text-brand-700';
    if (action.includes('deleted')) return 'bg-red-100 text-red-700';
    if (action.includes('updated') || action.includes('sent')) return 'bg-blue-100 text-blue-700';
    if (action.includes('viewed') || action.includes('downloaded')) return 'bg-surface-100 text-surface-600';
    return 'bg-amber-100 text-amber-700';
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