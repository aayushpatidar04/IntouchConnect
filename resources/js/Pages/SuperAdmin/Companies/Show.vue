<template>
    <SuperAdminLayout :title="company.name">
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('superadmin.companies.index')"
                    class="text-surface-400 hover:text-surface-700 transition-colors">
                    ← Companies
                </Link>
                <span class="text-surface-300">/</span>
                <h1 class="text-base font-semibold text-surface-900">{{ company.name }}</h1>
                <span :class="['badge ml-1', company.is_active ? 'bg-brand-100 text-brand-700' : 'bg-surface-100 text-surface-500']">
                    {{ company.is_active ? 'Active' : 'Inactive' }}
                </span>
            </div>
        </template>

        <template #actions>
            <button @click="showAddAdmin = true"
                class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
                + Add Admin
            </button>
        </template>

        <div class="p-6 space-y-6 animate-fade-in">

            <!-- Top info cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <!-- Company info -->
                <div class="bg-white rounded-2xl border border-surface-100 p-5 space-y-3">
                    <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wider">Company Info</h3>
                    <div class="space-y-2 text-sm">
                        <InfoRow label="Name" :value="company.name" />
                        <InfoRow label="Slug" :value="company.slug" mono />
                        <InfoRow label="Session ID" :value="company.session_id" mono />
                        <InfoRow label="Created" :value="formatDate(company.created_at)" />
                    </div>
                    <!-- Edit company name -->
                    <div class="pt-2 border-t border-surface-100 flex gap-2">
                        <button @click="showEditCompany = true"
                            class="text-xs text-surface-500 hover:text-surface-800">Edit Name</button>
                        <button @click="toggleActive"
                            :class="['text-xs', company.is_active ? 'text-amber-500 hover:text-amber-700' : 'text-brand-500 hover:text-brand-700']">
                            {{ company.is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </div>
                </div>

                <!-- WhatsApp session -->
                <div class="bg-white rounded-2xl border border-surface-100 p-5 space-y-3">
                    <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wider">WhatsApp Session</h3>

                    <!-- Live status from gateway -->
                    <div :class="['flex items-center gap-2 rounded-xl px-3 py-2', sessionBg]">
                        <span :class="sessionDot" class="w-2 h-2 rounded-full shrink-0" />
                        <div>
                            <p :class="['text-sm font-medium', sessionTextColor]">{{ sessionLabel }}</p>
                            <p v-if="gatewayStatus.phone" class="text-xs text-surface-400">+{{ gatewayStatus.phone }}</p>
                        </div>
                    </div>

                    <!-- QR code if waiting -->
                    <div v-if="sessionStatus === 'qr_ready' && gatewayStatus.qr"
                        class="flex flex-col items-center gap-2 pt-1">
                        <img :src="gatewayStatus.qr" alt="QR Code"
                            class="w-40 h-40 rounded-xl border border-amber-100" />
                        <p class="text-xs text-amber-600 text-center">
                            Open WhatsApp → Linked Devices → Link a Device
                        </p>
                    </div>

                    <div class="pt-2 border-t border-surface-100 flex flex-wrap gap-2">
                        <button @click="provisionSession"
                            class="text-xs text-brand-500 hover:text-brand-700 font-medium">
                            Provision Session
                        </button>
                        <button @click="logoutSession"
                            class="text-xs text-red-400 hover:text-red-600">
                            Reset / New QR
                        </button>
                    </div>
                </div>

                <!-- Quick stats — users count only, no customer data -->
                <div class="bg-white rounded-2xl border border-surface-100 p-5 space-y-3">
                    <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wider">Team</h3>
                    <div class="space-y-2">
                        <div v-for="(count, role) in roleCounts" :key="role"
                            class="flex items-center justify-between text-sm">
                            <span class="capitalize text-surface-600">{{ role }}</span>
                            <span :class="['badge', roleClass(role)]">{{ count }}</span>
                        </div>
                        <p v-if="!users.length" class="text-xs text-surface-400">No users yet</p>
                    </div>
                </div>
            </div>

            <!-- Users / Admins table -->
            <div class="bg-white rounded-2xl border border-surface-100 overflow-hidden">
                <div class="px-5 py-4 border-b border-surface-100">
                    <h3 class="text-sm font-semibold text-surface-700">Company Users</h3>
                    <p class="text-xs text-surface-400 mt-0.5">
                        Super-admin can only manage admin accounts here.
                        Executives and auditors are managed by the company admin.
                    </p>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-surface-50 bg-surface-50">
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">User</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Role</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">Phone</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Status</th>
                            <th class="px-5 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-50">
                        <tr v-for="u in users" :key="u.id" class="hover:bg-surface-50 transition-colors group">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <img :src="`https://ui-avatars.com/api/?name=${encodeURIComponent(u.name)}&background=e2e8f0&color=475569`"
                                        class="w-8 h-8 rounded-full" />
                                    <div>
                                        <p class="font-medium text-surface-900">{{ u.name }}</p>
                                        <p class="text-xs text-surface-400">{{ u.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span :class="['badge', roleClass(u.role)]">{{ u.role }}</span>
                            </td>
                            <td class="px-5 py-3.5 hidden md:table-cell text-xs text-surface-500 font-mono">
                                {{ u.phone ?? '—' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <span :class="['badge', u.is_active ? 'bg-brand-100 text-brand-700' : 'bg-surface-100 text-surface-500']">
                                    {{ u.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <!-- Only show edit/delete for admin role users -->
                                <div v-if="u.role === 'admin'"
                                    class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button @click="editAdmin(u)"
                                        class="text-xs text-surface-500 hover:text-surface-800">Edit</button>
                                    <button @click="deleteAdmin(u)"
                                        class="text-xs text-red-400 hover:text-red-600">Remove</button>
                                </div>
                                <span v-else class="text-xs text-surface-300 opacity-0 group-hover:opacity-100">
                                    Managed by company admin
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!users.length">
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-surface-400">
                                No users yet. Add an admin to get started.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add Admin Modal -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showAddAdmin" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="showAddAdmin = false" />
                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 animate-slide-up">
                        <h2 class="text-base font-semibold mb-5">Add Admin to {{ company.name }}</h2>
			<div class="flex gap-2 mb-5">
                            <button type="button" @click="adminMode = 'existing'" :class="[
                                'px-4 py-2 rounded-xl text-sm font-medium transition',
                                adminMode === 'existing'
                                    ? 'bg-brand-500 text-white'
                                    : 'bg-surface-100 text-surface-700'
                            ]">
                                Existing Admin
                            </button>

                            <button type="button" @click="adminMode = 'new'" :class="[
                                'px-4 py-2 rounded-xl text-sm font-medium transition',
                                adminMode === 'new'
                                    ? 'bg-brand-500 text-white'
                                    : 'bg-surface-100 text-surface-700'
                            ]">
                                New Admin
                            </button>
                        </div>
                        <form @submit.prevent="submitAdmin" class="space-y-4">
			    <div v-if="adminMode === 'existing'">
                                <div>
                                    <label class="block text-xs font-medium mb-3">
                                        Manage Admin Access
                                    </label>

                                    <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                                        <label v-for="admin in admins" :key="admin.id"
                                            class="flex items-center gap-3 p-3 rounded-xl border border-surface-200 hover:bg-surface-50 cursor-pointer">
                                            <input type="checkbox" :value="admin.id" v-model="adminForm.admin_ids"
                                                class="rounded border-surface-300 text-brand-500 focus:ring-brand-500" />

                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium truncate">
                                                    {{ admin.name }}
                                                </p>

                                                <p class="text-xs text-surface-500 truncate">
                                                    {{ admin.email }}
                                                </p>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div v-if="adminMode === 'new'">
                                <div>
                                    <label class="block text-xs font-medium mb-1">Name *</label>
                                    <input v-model="adminForm.name" required class="input-field" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Email *</label>
                                    <input v-model="adminForm.email" type="email" required class="input-field" />
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium mb-1">Password *</label>
                                        <input v-model="adminForm.password" type="password" required minlength="8"
                                            class="input-field" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium mb-1">Confirm *</label>
                                        <input v-model="adminForm.password_confirmation" type="password" required
                                            class="input-field" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Phone</label>
                                    <input v-model="adminForm.phone" class="input-field" />
                                </div>
                            </div>
			    <div class="flex justify-end gap-2 pt-1">
                                <button type="button" @click="closeAdminModal"
                                    class="px-4 py-2 text-sm text-surface-600">Cancel</button>
                                <button type="submit" :disabled="submitting"
                                    class="px-5 py-2 bg-brand-500 text-white text-sm font-medium rounded-xl hover:bg-brand-600 disabled:opacity-50">
                                    {{ submitting ? 'Saving…' : editingAdmin ? 'Update Admin' : 'Add Admin' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Edit Company Name Modal -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showEditCompany" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="showEditCompany = false" />
                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 animate-slide-up">
                        <h2 class="text-base font-semibold mb-5">Edit Company</h2>
                        <form @submit.prevent="submitEditCompany" class="space-y-4">
                            <div>
                                <label class="block text-xs font-medium mb-1">Company Name *</label>
                                <input v-model="editCompanyForm.name" required class="input-field" />
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="showEditCompany = false"
                                    class="px-4 py-2 text-sm text-surface-600">Cancel</button>
                                <button type="submit" :disabled="submitting"
                                    class="px-5 py-2 bg-brand-500 text-white text-sm font-medium rounded-xl hover:bg-brand-600 disabled:opacity-50">
                                    {{ submitting ? 'Saving…' : 'Save' }}
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
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Components/Layout/SuperAdminLayout.vue';
import { useToast } from '@/Composables/useToast';
import axios from 'axios';
import InfoRow from '@/Components/UI/InfoRow2.vue';

const props = defineProps({
    company:       { type: Object, required: true },
    users:         { type: Array,  default: () => [] },
    gatewayStatus: { type: Object, default: () => ({}) },
});

const toast        = useToast();
const showAddAdmin    = ref(false);
const showEditCompany = ref(false);
const editingAdmin    = ref(null);
const submitting      = ref(false);
const adminMode = ref('existing')
const admins = ref([])

const adminForm = reactive({ name: '', email: '', password: '', password_confirmation: '', phone: '', admin_ids: [] });
const editCompanyForm = reactive({ name: props.company.name });

// ── Gateway session display ───────────────────────────────────────────────────
const sessionStatus = computed(() => props.gatewayStatus.status ?? props.company.session_status ?? 'disconnected');

const sessionBg = computed(() => ({
    connected:    'bg-brand-50',
    qr_ready:     'bg-amber-50',
    disconnected: 'bg-surface-50',
    failed:       'bg-red-50',
}[sessionStatus.value] ?? 'bg-surface-50'));

const sessionDot = computed(() => ({
    connected:    'bg-brand-500',
    qr_ready:     'bg-amber-400 animate-pulse',
    disconnected: 'bg-surface-300',
    failed:       'bg-red-500',
}[sessionStatus.value] ?? 'bg-surface-300'));

const sessionTextColor = computed(() => ({
    connected:    'text-brand-700',
    qr_ready:     'text-amber-700',
    disconnected: 'text-surface-500',
    failed:       'text-red-600',
}[sessionStatus.value] ?? 'text-surface-500'));

const sessionLabel = computed(() => {
    if (sessionStatus.value === 'connected' && props.gatewayStatus.phone) return `Connected: +${props.gatewayStatus.phone}`;
    return { connected: 'Connected', qr_ready: 'Waiting for QR scan', disconnected: 'Disconnected', failed: 'Session failed' }[sessionStatus.value] ?? 'Unknown';
});

// ── User role counts ──────────────────────────────────────────────────────────
const roleCounts = computed(() => {
    const counts = {};
    for (const u of props.users) {
        counts[u.role] = (counts[u.role] ?? 0) + 1;
    }
    return counts;
});

function roleClass(role) {
    return { admin: 'bg-purple-100 text-purple-700', executive: 'bg-blue-100 text-blue-700', auditor: 'bg-amber-100 text-amber-700' }[role] ?? 'bg-surface-100 text-surface-600';
}

function formatDate(ts) {
    if (!ts) return '—';
    return new Date(ts).toLocaleDateString('en-IN', { year: 'numeric', month: 'short', day: 'numeric' });
}

// ── Admin CRUD ────────────────────────────────────────────────────────────────
function editAdmin(u) {
    Object.assign(adminForm, { name: u.name, email: u.email, password: '', password_confirmation: '', phone: u.phone ?? '' });
    editingAdmin.value = u;
    showAddAdmin.value = true;
}

function closeAdminModal() {
    showAddAdmin.value = false;
    editingAdmin.value = null;
    Object.assign(adminForm, { name: '', email: '', password: '', password_confirmation: '', phone: '' });
}

function submitAdmin() {
    submitting.value = true;
    if (editingAdmin.value) {
        router.patch(route('superadmin.companies.admins.update', { company: props.company.id, user: editingAdmin.value.id }), adminForm, {
            onSuccess: () => { toast.success('Admin updated.'); closeAdminModal(); },
            onError: () => toast.error('Please check the form.'),
            onFinish: () => { submitting.value = false; },
        });
    } else {
        router.post(route('superadmin.companies.admins.store', props.company.id), adminForm, {
            onSuccess: () => { toast.success('Admin added.'); closeAdminModal(); },
            onError: () => toast.error('Please check the form.'),
            onFinish: () => { submitting.value = false; },
        });
    }
}

function deleteAdmin(u) {
    if (!confirm(`Remove admin "${u.name}" from ${props.company.name}?`)) return;
    router.delete(route('superadmin.companies.admins.destroy', { company: props.company.id, user: u.id }), {
        onSuccess: () => toast.success('Admin removed.'),
        onError:   () => toast.error('Cannot remove the only admin.'),
    });
}

// ── Company edit ──────────────────────────────────────────────────────────────
function submitEditCompany() {
    submitting.value = true;
    router.patch(route('superadmin.companies.update', props.company.id), editCompanyForm, {
        onSuccess: () => { toast.success('Company updated.'); showEditCompany.value = false; },
        onFinish: () => { submitting.value = false; },
    });
}

function toggleActive() {
    router.post(route('superadmin.companies.toggle', props.company.id), {}, {
        onSuccess: () => toast.success(`Company ${props.company.is_active ? 'activated' : 'deactivated'}.`),
    });
}

// ── Gateway actions ───────────────────────────────────────────────────────────
function provisionSession() {
    router.post(route('superadmin.companies.provision-session', props.company.id), {}, {
        onSuccess: () => toast.success('Session provisioned. Check the gateway dashboard for the QR code.'),
        onError:   () => toast.error('Failed to provision session.'),
    });
}

function logoutSession() {
    if (!confirm('This will disconnect the current WhatsApp session and generate a new QR code. Continue?')) return;
    router.post(route('superadmin.companies.logout-session', props.company.id), {}, {
        onSuccess: () => toast.success('Session reset. A new QR code will appear on the gateway dashboard.'),
        onError:   () => toast.error('Failed to reset session.'),
    });
}

const fetchAdmins = async () => {
    try {
        const response = await axios.get(
            route('superadmin.companies.available-admins', props.company.id)
        )

        admins.value = response.data;
        adminForm.admin_ids = response.data
            .filter(admin => admin.checked)
            .map(admin => admin.id)
    } catch (error) {
        console.error(error)
    }
}

watch(showAddAdmin, (value) => {
    if (value) {
        fetchAdmins()
    }
})
</script>

<style scoped>
.input-field {
    @apply w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400;
}
.modal-enter-active, .modal-leave-active { transition: opacity .15s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
</style>
