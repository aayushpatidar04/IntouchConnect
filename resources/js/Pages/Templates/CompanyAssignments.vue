<template>
    <AppLayout title="Template Access">
        <div class="p-6 space-y-4 animate-fade-in">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-base font-semibold text-surface-900">Template Access Control</h1>
                    <p class="text-xs text-surface-400 mt-0.5">
                        Choose which executives can use each global template.
                        Leave all unchecked to allow all executives.
                    </p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3">
                <input v-model="search" @input="doSearch" placeholder="Search templates…"
                    class="flex-1 min-w-[200px] rounded-xl border border-surface-200 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
                <div class="flex gap-2">
                    <button v-for="(meta, key) in categories" :key="key"
                        @click="filterCategory = filterCategory === key ? '' : key; doSearch()"
                        :class="['px-3 py-1.5 rounded-xl text-xs font-medium transition-colors border', filterCategory === key ? 'bg-brand-500 text-white border-brand-500' : 'border-surface-200 text-surface-500 hover:border-brand-300']">
                        {{ meta.label }}
                    </button>
                </div>
            </div>

            <!-- Templates list -->
            <div class="space-y-3">
                <div v-for="t in templates.data" :key="t.id"
                    class="bg-white rounded-2xl border border-surface-100 p-5 hover:shadow-sm transition-shadow">
                    <div class="flex items-start justify-between gap-4">
                        <!-- Template info -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-semibold text-surface-900 text-sm">{{ t.name }}</h3>
                                <span :class="['badge text-[10px]', categoryColor(t.category)]">
                                    {{ categories[t.category]?.label ?? t.category }}
                                </span>
                            </div>
                            <p class="text-xs text-surface-500 mt-1 line-clamp-2" v-html="renderWaMarkdown(t.body)" />

                            <!-- Variables -->
                            <div v-if="t.variables.length" class="flex flex-wrap gap-1.5 mt-2">
                                <span v-for="v in t.variables" :key="v"
                                    class="bg-brand-50 text-brand-600 text-[10px] font-mono px-2 py-0.5 rounded-lg border border-brand-100">
                                    {{ formatVar(v) }}
                                </span>
                            </div>
                        </div>

                        <!-- Assignment toggle -->
                        <div class="shrink-0">
                            <button v-if="!editing[t.id]" @click="startEdit(t)"
                                class="text-xs text-brand-500 hover:text-brand-700 font-medium px-3 py-1.5 rounded-xl hover:bg-brand-50 transition-colors">
                                Manage Access
                            </button>
                            <div v-else class="flex gap-2">
                                <button @click="saveAssignments(t)"
                                    :disabled="saving[t.id]"
                                    class="text-xs bg-brand-500 text-white px-3 py-1.5 rounded-xl hover:bg-brand-600 disabled:opacity-50">
                                    {{ saving[t.id] ? 'Saving…' : 'Save' }}
                                </button>
                                <button @click="cancelEdit(t)"
                                    class="text-xs text-surface-500 px-3 py-1.5 rounded-xl hover:bg-surface-100">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Executive selection (expandable) -->
                    <div v-if="editing[t.id]" class="mt-4 pt-4 border-t border-surface-100">
                        <p class="text-xs font-medium text-surface-700 mb-2">Select executives who can use this template:</p>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                            <label v-for="exec in executives" :key="exec.id"
                                class="flex items-center gap-2 cursor-pointer text-sm hover:bg-surface-50 px-2 py-1.5 rounded-lg transition-colors">
                                <input type="checkbox" :value="exec.id" v-model="editForm[t.id]"
                                    class="rounded text-brand-500" />
                                {{ exec.name }}
                            </label>
                        </div>
                        <p v-if="!executives.length" class="text-xs text-surface-400 py-2">
                            No executives in your company yet.
                        </p>
                        <p v-if="editForm[t.id].length === 0" class="text-xs text-brand-600 mt-2">
                            ✓ All executives can use this template (no restrictions).
                        </p>
                        <p v-else class="text-xs text-surface-500 mt-2">
                            {{ editForm[t.id].length }} executive{{ editForm[t.id].length === 1 ? '' : 's' }} selected.
                        </p>
                    </div>

                    <!-- Current assignment display -->
                    <div v-else class="mt-3 pt-3 border-t border-surface-100">
                        <p class="text-[11px] text-surface-400">
                            <span v-if="t.assigned_all">👥 All executives can use this template</span>
                            <span v-else>👤 {{ t.assigned_users.map(u => u.name).join(', ') }}</span>
                        </p>
                    </div>
                </div>

                <div v-if="!templates.data.length" class="text-center py-16 text-sm text-surface-400">
                    <p class="text-3xl mb-3">📝</p>
                    <p>No active templates available.</p>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="templates.last_page > 1" class="flex items-center justify-between">
                <p class="text-xs text-surface-400">{{ templates.from }}–{{ templates.to }} of {{ templates.total }}</p>
                <div class="flex gap-2">
                    <Link v-if="templates.prev_page_url" :href="templates.prev_page_url"
                        class="px-3 py-1.5 text-xs rounded-xl bg-surface-100 hover:bg-surface-200">← Prev</Link>
                    <Link v-if="templates.next_page_url" :href="templates.next_page_url"
                        class="px-3 py-1.5 text-xs rounded-xl bg-surface-100 hover:bg-surface-200">Next →</Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const formatVar = v => `{{${v}}}`;

const props = defineProps({
    templates: { type: Object, required: true },
    executives: { type: Array, default: () => [] },
    categories: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

// -- Filters
const search = ref(props.filters.search ?? '');
const filterCategory = ref(props.filters.category ?? '');
let searchTimer = null;
function doSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(route('admin.templates.assignments'), { search: search.value, category: filterCategory.value }, { preserveState: true, replace: true });
    }, 350);
}

function categoryColor(key) {
    return props.categories[key]?.color ?? 'bg-surface-100 text-surface-600';
}

// -- Inline editing
const editing = reactive({});
const editForm = reactive({});
const saving = reactive({});

function startEdit(t) {
    editing[t.id] = true;
    editForm[t.id] = t.assigned_users.map(u => u.id);
}

function cancelEdit(t) {
    editing[t.id] = false;
    delete editForm[t.id];
}

function saveAssignments(t) {
    saving[t.id] = true;
    router.post(route('admin.templates.assignments.update', t.id), {
        assigned_users: editForm[t.id],
    }, {
        preserveState: true,
        onSuccess: () => { editing[t.id] = false; },
        onFinish: () => { saving[t.id] = false; },
    });
}

function renderWaMarkdown(text) {
    if (!text) return '';
    let html = text
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')
        .replace(/_([^_\n]+)_/g, '<em>$1</em>')
        .replace(/~([^~\n]+)~/g, '<del>$1</del>')
        .replace(/\n/g, '<br>')
        .replace(/\{\{(\w+)\}\}/g, '<span class="wa-var">{{$1}}</span>');
    return html;
}
</script>

<style scoped>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.wa-var {
    display: inline-block;
    background: #e8f5e9;
    color: #1b5e20;
    border-radius: 4px;
    padding: 0 4px;
    font-family: monospace;
    font-size: 0.7rem;
    border: 1px solid #c8e6c9;
    line-height: 1.6;
}
</style>
