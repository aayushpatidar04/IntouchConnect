<template>
    <SuperAdminLayout title="Companies">
        <template #actions>
            <button @click="showCreate = true"
                class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
                + New Company
            </button>
        </template>

        <div class="p-6 space-y-4 animate-fade-in">

            <!-- Filters -->
            <div class="flex flex-col sm:flex-row gap-3">
                <input v-model="search" @input="doSearch" placeholder="Search by name or slug…"
                    class="flex-1 rounded-xl border border-surface-200 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
                <select v-model="statusFilter" @change="doSearch"
                    class="rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-2xl border border-surface-100 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-surface-100 bg-surface-50">
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Company</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">Admin</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">WhatsApp</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">Users</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Status</th>
                            <th class="px-5 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-50">
                        <tr v-for="c in companies.data" :key="c.id"
                            class="hover:bg-surface-50 transition-colors group">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-brand-100 flex items-center justify-center shrink-0">
                                        <span class="text-brand-600 font-bold text-sm">{{ c.name.charAt(0).toUpperCase() }}</span>
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
                                {{ c.users_count }} user{{ c.users_count === 1 ? '' : 's' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <span :class="['badge', c.is_active ? 'bg-brand-100 text-brand-700' : 'bg-surface-100 text-surface-500']">
                                    {{ c.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-3 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <Link :href="route('superadmin.companies.show', c.id)"
                                        class="text-xs text-brand-500 hover:text-brand-700 font-medium">
                                        Manage
                                    </Link>
                                    <button @click="toggleActive(c)"
                                        class="text-xs text-surface-400 hover:text-surface-700">
                                        {{ c.is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                    <button @click="confirmDelete(c)" class="text-xs text-red-400 hover:text-red-600">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!companies.data.length">
                            <td colspan="6" class="px-5 py-12 text-center text-sm text-surface-400">
                                No companies found.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="companies.last_page > 1"
                    class="flex items-center justify-between px-5 py-3 border-t border-surface-100">
                    <p class="text-xs text-surface-400">
                        {{ companies.from }}–{{ companies.to }} of {{ companies.total }}
                    </p>
                    <div class="flex gap-2">
                        <Link v-if="companies.prev_page_url" :href="companies.prev_page_url"
                            class="px-3 py-1 text-xs rounded-lg border border-surface-200 hover:bg-surface-50">
                            ← Prev
                        </Link>
                        <Link v-if="companies.next_page_url" :href="companies.next_page_url"
                            class="px-3 py-1 text-xs rounded-lg border border-surface-200 hover:bg-surface-50">
                            Next →
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Company Modal -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="showCreate = false" />
                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 animate-slide-up">
                        <h2 class="text-base font-semibold mb-1">New Company</h2>
                        <p class="text-xs text-surface-400 mb-5">Creates the company and its first admin account.</p>

                        <form @submit.prevent="submitCreate" class="space-y-4">
                            <!-- Company details -->
                            <div class="p-4 bg-surface-50 rounded-xl space-y-3">
                                <p class="text-xs font-semibold text-surface-500 uppercase tracking-wider">Company</p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="col-span-2">
                                        <label class="block text-xs font-medium mb-1">Company Name *</label>
                                        <input v-model="createForm.name" required placeholder="Acme Corp"
                                            class="input-field" />
                                    </div>
                                    <div class="col-span-2">
                                        <label class="block text-xs font-medium mb-1">
                                            Slug *
                                            <span class="text-surface-400 font-normal ml-1">(used as WhatsApp session ID — lowercase, hyphens only)</span>
                                        </label>
                                        <input v-model="createForm.slug" required placeholder="acme-corp"
                                            pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only"
                                            class="input-field font-mono" />
                                    </div>
                                </div>
                            </div>

                            <!-- Admin account -->
                            <div class="p-4 bg-surface-50 rounded-xl space-y-3">
                                <p class="text-xs font-semibold text-surface-500 uppercase tracking-wider">First Admin Account</p>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Name *</label>
                                    <input v-model="createForm.admin_name" required placeholder="John Smith"
                                        class="input-field" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Email *</label>
                                    <input v-model="createForm.admin_email" type="email" required
                                        placeholder="admin@acmecorp.com" class="input-field" />
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium mb-1">Password *</label>
                                        <input v-model="createForm.admin_password" type="password" required
                                            minlength="8" class="input-field" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium mb-1">Confirm Password *</label>
                                        <input v-model="createForm.admin_password_confirmation" type="password"
                                            required class="input-field" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Phone (optional)</label>
                                    <input v-model="createForm.admin_phone" placeholder="+91 98765 43210"
                                        class="input-field" />
                                </div>
                            </div>

                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button" @click="showCreate = false"
                                    class="px-4 py-2 text-sm text-surface-600">Cancel</button>
                                <button type="submit" :disabled="submitting"
                                    class="px-5 py-2 bg-brand-500 text-white text-sm font-medium rounded-xl hover:bg-brand-600 disabled:opacity-50">
                                    {{ submitting ? 'Creating…' : 'Create Company' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </SuperAdminLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Components/Layout/SuperAdminLayout.vue';
import { useToast } from '@/Composables/useToast';

// Session badge inline component
const SessionBadge = {
    props: { status: String, phone: String },
    template: `
        <span :class="cls" class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium">
            <span :class="dotCls" class="w-1.5 h-1.5 rounded-full" />{{ label }}
        </span>`,
    computed: {
        cls() { return { connected:'bg-brand-50 text-brand-700', qr_ready:'bg-amber-50 text-amber-700', disconnected:'bg-surface-100 text-surface-500', failed:'bg-red-50 text-red-600' }[this.status] ?? 'bg-surface-100 text-surface-500'; },
        dotCls() { return { connected:'bg-brand-500', qr_ready:'bg-amber-400 animate-pulse', disconnected:'bg-surface-400', failed:'bg-red-500' }[this.status] ?? 'bg-surface-400'; },
        label() { if (this.status === 'connected' && this.phone) return `+${this.phone}`; return { connected:'Connected', qr_ready:'Scan QR', disconnected:'Offline', failed:'Failed' }[this.status] ?? this.status; },
    },
};

const props = defineProps({
    companies: { type: Object, required: true },
    filters:   { type: Object, default: () => ({}) },
});

const toast = useToast();
const showCreate  = ref(false);
const submitting  = ref(false);
const search      = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? '');

const createForm = reactive({
    name: '', slug: '',
    admin_name: '', admin_email: '', admin_password: '', admin_password_confirmation: '', admin_phone: '',
});

let searchTimer = null;
function doSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(route('superadmin.companies.index'), { search: search.value, status: statusFilter.value }, { preserveState: true, replace: true });
    }, 350);
}

function submitCreate() {
    submitting.value = true;
    router.post(route('superadmin.companies.store'), createForm, {
        onSuccess: () => {
            toast.success('Company created successfully.');
            showCreate.value = false;
            Object.assign(createForm, { name:'', slug:'', admin_name:'', admin_email:'', admin_password:'', admin_password_confirmation:'', admin_phone:'' });
        },
        onError: () => toast.error('Please check the form for errors.'),
        onFinish: () => { submitting.value = false; },
    });
}

function toggleActive(company) {
    router.post(route('superadmin.companies.toggle', company.id), {}, {
        onSuccess: () => toast.success(`Company ${company.is_active ? 'deactivated' : 'activated'}.`),
    });
}

function confirmDelete(company) {
    if (!confirm(`Delete "${company.name}"? This will soft-delete the company and remove its gateway session. This cannot be undone easily.`)) return;
    router.delete(route('superadmin.companies.destroy', company.id), {
        onSuccess: () => toast.success('Company deleted.'),
    });
}
</script>

<style scoped>
.input-field {
    @apply w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400;
}
.modal-enter-active, .modal-leave-active { transition: opacity .15s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
</style>