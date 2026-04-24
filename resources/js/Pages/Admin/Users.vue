<template>
    <AppLayout title="User Management">
        <template #actions>
            <button @click="showCreate = true"
                class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
                + New User
            </button>
        </template>

        <div class="p-6 animate-fade-in">
            <div class="bg-white rounded-2xl border border-surface-100 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-surface-100 bg-surface-50">
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                User
                            </th>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Role
                            </th>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">
                                Phone</th>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Status</th>
                            <th class="px-5 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-50">
                        <tr v-for="u in users.data" :key="u.id" class="hover:bg-surface-50 transition-colors group">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <img :src="u.avatar_url ?? `https://ui-avatars.com/api/?name=${encodeURIComponent(u.name)}&background=e2e8f0&color=475569`"
                                        class="w-8 h-8 rounded-full" />
                                    <div>
                                        <p class="font-medium text-surface-900">{{ u.name }}</p>
                                        <p class="text-xs text-surface-400">{{ u.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span v-for="r in u.roles" :key="r.id" :class="['badge', roleClass(r.name)]">{{ r.name
                                    }}</span>
                            </td>
                            <td class="px-5 py-3.5 hidden md:table-cell text-surface-500 text-xs font-mono">{{ u.phone
                                ?? '—' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <span
                                    :class="['badge', u.is_active ? 'bg-brand-100 text-brand-700' : 'bg-surface-100 text-surface-500']">
                                    {{ u.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <div
                                    class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button @click="editUser(u)"
                                        class="text-xs text-surface-500 hover:text-surface-800">Edit</button>
                                    <button @click="deleteUser(u)"
                                        class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showCreate || editingUser" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeModal" />
                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 animate-slide-up">
                        <h2 class="text-base font-semibold mb-5">{{ editingUser ? 'Edit User' : 'New User' }}</h2>
                        <form @submit.prevent="submitUser" class="space-y-4">
                            <div>
                                <label class="block text-xs font-medium mb-1">Name *</label>
                                <input v-model="form.name" required
                                    class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Email *</label>
                                <input v-model="form.email" type="email" required
                                    class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
                            </div>
                            <div v-if="!editingUser">
                                <label class="block text-xs font-medium mb-1">Password *</label>
                                <input v-model="form.password" type="password" required minlength="8"
                                    class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1">Role *</label>
                                    <select v-model="form.role" required
                                        class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                                        <option v-for="r in roles" :key="r.id" :value="r.name">{{ r.name }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Phone</label>
                                    <input v-model="form.phone"
                                        class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
                                </div>
                            </div>
                            <!-- Active status toggle — only show on edit -->
                            <div v-if="editingUser" class="flex items-center gap-3 pt-1">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="form.is_active" class="sr-only peer" />
                                    <div
                                        class="w-9 h-5 bg-surface-200 peer-focus:ring-2 peer-focus:ring-brand-300 rounded-full peer peer-checked:bg-brand-500 transition-colors" />
                                    <div
                                        class="absolute left-0.5 top-0.5 bg-white w-4 h-4 rounded-full shadow peer-checked:translate-x-4 transition-transform" />
                                </label>
                                <span class="text-xs font-medium text-surface-700">
                                    {{ form.is_active ? 'Active account' : 'Inactive (login blocked)' }}
                                </span>
                            </div>
                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button" @click="closeModal"
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
    </AppLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps({ users: Object, roles: Array });
const toast = useToast();
const showCreate = ref(false);
const editingUser = ref(null);
const submitting = ref(false);

const form = reactive({ name: '', email: '', password: '', role: 'executive', phone: '', is_active: true });

function editUser(u) {
    Object.assign(form, {
        name: u.name,
        email: u.email,
        password: '',
        role: u.roles[0]?.name ?? 'executive',
        phone: u.phone ?? '',
        is_active: u.is_active ?? true,
    });
    editingUser.value = u;
}

function closeModal() {
    showCreate.value = false;
    editingUser.value = null;
    Object.assign(form, { name: '', email: '', password: '', role: 'executive', phone: '', is_active: true });
}

function submitUser() {
    submitting.value = true;
    if (editingUser.value) {
        router.patch(route('admin.users.update', editingUser.value.id), form, {
            onSuccess: () => { toast.success('User updated.'); closeModal(); },
            onFinish: () => { submitting.value = false; },
        });
    } else {
        router.post(route('admin.users.store'), form, {
            onSuccess: () => { toast.success('User created.'); closeModal(); },
            onFinish: () => { submitting.value = false; },
        });
    }
}

function deleteUser(u) {
    if (!confirm(`Delete ${u.name}?`)) return;
    router.delete(route('admin.users.destroy', u.id), {
        onSuccess: () => toast.success('User deleted.'),
    });
}

function roleClass(r) {
    return { admin: 'bg-purple-100 text-purple-700', executive: 'bg-blue-100 text-blue-700', auditor: 'bg-amber-100 text-amber-700' }[r] ?? 'bg-surface-100 text-surface-600';
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