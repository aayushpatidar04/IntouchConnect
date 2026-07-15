<template>
    <AppLayout title="Groups Management">
        <template #actions>
            <button @click="openCreate"
                class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
                + New Group
            </button>
        </template>

        <div class="p-6 animate-fade-in">
            <div class="bg-white rounded-2xl border border-surface-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-surface-100 bg-surface-50">
                                <th
                                    class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Group</th>
                                <th
                                    class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Customers</th>
                                <th
                                    class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Owner</th>
                                <th class="px-5 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-50">
                            <tr v-for="g in groups.data" :key="g.id"
                                class="hover:bg-surface-50 transition-colors group">
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-surface-900">{{ g.name }}</p>
                                    <p class="text-xs text-surface-400">{{ g.description }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-surface-500 text-xs">{{ g.customers_count }}</td>
                                <td class="px-5 py-3.5 text-surface-500 text-xs">{{ g.creator?.name ?? '—' }}</td>
                                <td class="px-5 py-3.5 text-right">
                                    <div
                                        class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button @click="editGroup(g)"
                                            class="text-xs text-surface-500 hover:text-surface-800">Edit</button>
                                        <button @click="deleteGroup(g)"
                                            class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeModal" />
                    <div
                        class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 animate-slide-up max-h-[90vh] overflow-y-auto hide-scrollbar">
                        <h2 class="text-base font-semibold mb-5">{{ editingGroup ? 'Edit Group' : 'New Group' }}</h2>
                        <form @submit.prevent="submitGroup" class="space-y-4">
                            <div>
                                <label class="block text-xs font-medium mb-1">Group Name *</label>
                                <input v-model="form.name" required
                                    class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Description</label>
                                <textarea v-model="form.description"
                                    class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Customers</label>
                                <Multiselect v-model="form.customers" :options="customers" track-by="id" label="name"
                                    placeholder="Search and select customers" :multiple="true" :searchable="true"
                                    :close-on-select="false" :clear-on-select="false" :preserve-search="true">
                                    <template #option="{ option }">
                                        <div class="flex justify-between">
                                            <span>{{ option.name }}</span>
                                            <span class="text-xs text-gray-400">{{ option.phone }}</span>
                                        </div>
                                    </template>
                                </Multiselect>
                            </div>

                            <!-- Show selected customers -->
                            <div v-if="form.customers.length" class="mt-3 space-y-1">
                                <p class="text-xs font-medium text-surface-500">Selected Customers:</p>
                                <ul class="text-sm text-surface-700 list-disc pl-5">
                                    <li v-for="c in form.customers" :key="c.id">{{ c.name }} ({{ c.phone }})</li>
                                </ul>
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
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import Multiselect from 'vue-multiselect'
import 'vue-multiselect/dist/vue-multiselect.css'

const props = defineProps({
    groups: Object,
    customers: Array,
})

const showModal = ref(false)
const editingGroup = ref(null)
const submitting = ref(false)

const form = reactive({ name: '', description: '', customers: [] })

function openCreate() {
    Object.assign(form, { name: '', description: '', customers: [] })
    editingGroup.value = null
    showModal.value = true
}

function editGroup(g) {
    Object.assign(form, {
        name: g.name,
        description: g.description,
        customers: g.customers ?? []
    })
    editingGroup.value = g
    showModal.value = true
}

function closeModal() {
    showModal.value = false
    editingGroup.value = null
}

async function submitGroup() {
    submitting.value = true
    try {
        const payload = {
            name: form.name,
            description: form.description,
            customers: form.customers.map(c => c.id) // send IDs only
        }
        if (editingGroup.value) {
            await router.put(route('groups.update', editingGroup.value.id), payload)
        } else {
            await router.post(route('groups.store'), payload)
        }
        closeModal()
    } finally {
        submitting.value = false
    }
}

function deleteGroup(g) {
    if (!confirm(`Delete group "${g.name}"?`)) return
    router.delete(route('groups.destroy', g.id))
}
</script>
<style scoped>
/* global.css or inside <style scoped> */
.hide-scrollbar {
    -ms-overflow-style: none;
    /* IE/Edge */
    scrollbar-width: none;
    /* Firefox */
}

.hide-scrollbar::-webkit-scrollbar {
    display: none;
    /* Chrome/Safari */
}
</style>
