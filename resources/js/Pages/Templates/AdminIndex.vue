<template>
    <SuperAdminLayout title="Global Templates">
        <template #actions>
            <button @click="openCreate"
                class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors shadow-sm shadow-brand-500/30">
                + New Template
            </button>
        </template>

        <div class="p-6 space-y-4 animate-fade-in">
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

            <!-- Templates table -->
            <div class="bg-white rounded-2xl border border-surface-100 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-surface-100 bg-surface-50">
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Template</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">Category</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">Status</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">Created By</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Broadcasts</th>
                            <th class="px-5 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-50">
                        <tr v-for="t in templates.data" :key="t.id" class="hover:bg-surface-50 transition-colors group">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div v-if="t.media" class="w-10 h-10 rounded-lg bg-surface-100 overflow-hidden shrink-0">
                                        <img :src="t.media" class="w-full h-full object-cover" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-medium text-surface-900 text-sm">{{ t.name }}</p>
                                        <p class="text-xs text-surface-400 line-clamp-1 max-w-[300px]" v-html="renderWaMarkdown(t.body)" />
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 hidden md:table-cell">
                                <span :class="['badge text-[10px]', categoryColor(t.category)]">
                                    {{ categories[t.category]?.label ?? t.category }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 hidden md:table-cell">
                                <span v-if="t.is_active" class="badge bg-brand-100 text-brand-700 text-[10px]">Active</span>
                                <span v-else class="badge bg-surface-100 text-surface-400 text-[10px]">Inactive</span>
                            </td>
                            <td class="px-5 py-3.5 hidden lg:table-cell text-xs text-surface-500">
                                {{ t.created_by }}
                            </td>
                            <td class="px-5 py-3.5 text-xs text-surface-500">
                                {{ t.broadcasts_count }}
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button @click="openEdit(t)"
                                        class="px-3 py-1.5 text-xs text-surface-500 hover:text-surface-800 border border-surface-200 rounded-xl hover:bg-surface-50 transition-colors">
                                        Edit
                                    </button>
                                    <button @click="deleteTemplate(t)"
                                        class="px-3 py-1.5 text-xs text-red-400 hover:text-red-600 border border-red-100 rounded-xl hover:bg-red-50 transition-colors">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!templates.data.length">
                            <td colspan="6" class="text-center py-12 text-sm text-surface-400">
                                <p class="text-3xl mb-3">📝</p>
                                <p>No templates yet.</p>
                                <p class="mt-1">Click <strong>+ New Template</strong> to create one.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="templates.last_page > 1" class="border-t border-surface-50 px-5 py-3 flex items-center justify-between">
                    <p class="text-xs text-surface-400">{{ templates.from }}–{{ templates.to }} of {{ templates.total }}</p>
                    <div class="flex gap-2">
                        <Link v-if="templates.prev_page_url" :href="templates.prev_page_url"
                            class="px-3 py-1.5 text-xs rounded-xl bg-surface-100 hover:bg-surface-200">← Prev</Link>
                        <Link v-if="templates.next_page_url" :href="templates.next_page_url"
                            class="px-3 py-1.5 text-xs rounded-xl bg-surface-100 hover:bg-surface-200">Next →</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create / Edit Modal -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeForm" />
                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col animate-slide-up">
                        <div class="p-6 border-b border-surface-100 shrink-0">
                            <h2 class="text-base font-semibold">{{ editingTemplate ? 'Edit Template' : 'New Global Template' }}</h2>
                            <p class="text-xs text-surface-400 mt-0.5">
                                Global templates are available to all companies.
                            </p>
                        </div>

                        <div class="flex-1 overflow-y-auto p-6 space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="col-span-2">
                                    <label class="block text-xs font-medium mb-1">Template Name *</label>
                                    <input v-model="form.name" required placeholder="e.g. Welcome Message"
                                        class="input-field" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Category *</label>
                                    <select v-model="form.category" class="input-field">
                                        <option v-for="(meta, key) in categories" :key="key" :value="key">{{ meta.label }}</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-2 pt-5">
                                    <input v-model="form.is_active" type="checkbox" id="is_active" class="rounded" />
                                    <label for="is_active" class="text-sm text-surface-700">Active (visible to companies)</label>
                                </div>
                            </div>

                            <!-- Body with formatting toolbar -->
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-xs font-medium">Message Body *</label>
                                    <span class="text-xs text-surface-400">{{ form.body.length }} / 4096</span>
                                </div>

                                <div class="flex items-center gap-0.5 px-2 py-1.5 bg-surface-50 border border-surface-200 border-b-0 rounded-t-xl flex-wrap">
                                    <button v-for="fmt in formatButtons" :key="fmt.label" type="button"
                                        @click="applyFormat(fmt)" :title="fmt.title"
                                        class="px-2 py-1 rounded-lg text-surface-600 hover:bg-surface-200 hover:text-surface-900 transition-colors text-xs font-medium select-none">
                                        <span v-html="fmt.label" />
                                    </button>

                                    <span class="w-px h-4 bg-surface-200 mx-1 shrink-0" />

                                    <div class="relative" v-if="quickVars.length">
                                        <button type="button" @click="showVarPicker = !showVarPicker"
                                            class="px-2 py-1 rounded-lg text-brand-600 hover:bg-brand-50 transition-colors text-xs font-medium flex items-center gap-1">
                                            <span>{ }</span>
                                            <span class="text-[10px] text-surface-400">Variables</span>
                                        </button>
                                        <div v-if="showVarPicker"
                                            class="absolute top-full left-0 mt-1 bg-white rounded-xl shadow-lg border border-surface-100 py-1 z-10 min-w-[180px]">
                                            <button v-for="v in quickVars" :key="v" type="button"
                                                @click="insertVariable(v)"
                                                class="w-full text-left px-3 py-1.5 text-xs text-surface-700 hover:bg-surface-50 font-mono">
                                                {{ formatVar(v) }}
                                            </button>
                                            <div class="border-t border-surface-100 mt-1 pt-1">
                                                <button type="button" @click="insertCustomVar"
                                                    class="w-full text-left px-3 py-1.5 text-xs text-brand-500 hover:bg-brand-50">
                                                    + Custom variable…
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <span class="w-px h-4 bg-surface-200 mx-1 shrink-0" />

                                    <div class="ml-auto flex bg-surface-200 rounded-lg p-0.5">
                                        <button type="button" @click="bodyTab = 'edit'"
                                            :class="['px-2.5 py-0.5 rounded-md text-xs font-medium transition-colors', bodyTab === 'edit' ? 'bg-white text-surface-800 shadow-sm' : 'text-surface-500 hover:text-surface-700']">
                                            Edit
                                        </button>
                                        <button type="button" @click="bodyTab = 'preview'"
                                            :class="['px-2.5 py-0.5 rounded-md text-xs font-medium transition-colors', bodyTab === 'preview' ? 'bg-white text-surface-800 shadow-sm' : 'text-surface-500 hover:text-surface-700']">
                                            Preview
                                        </button>
                                    </div>
                                </div>

                                <textarea v-show="bodyTab === 'edit'" ref="bodyTextarea" v-model="form.body" rows="8"
                                    required maxlength="4096"
                                    placeholder="Hi *{{customer_name}}*, thank you for reaching out…"
                                    @click="showVarPicker = false" @keydown.escape="showVarPicker = false"
                                    class="w-full border border-surface-200 rounded-b-xl px-3 py-2.5 text-xs font-mono leading-relaxed focus:outline-none focus:ring-2 focus:ring-brand-400 resize-none" />

                                <div v-show="bodyTab === 'preview'"
                                    class="min-h-[168px] border border-surface-200 rounded-b-xl px-3 py-2.5 bg-[#e9f5e1]">
                                    <div v-if="form.body" class="text-sm leading-relaxed wa-preview"
                                        v-html="renderWaMarkdown(form.body)" />
                                    <p v-else class="text-xs text-surface-400 italic">Start typing to see the WhatsApp preview…</p>
                                </div>

                                <div class="flex items-start justify-between mt-2 gap-3">
                                    <div v-if="detectedVars.length" class="flex flex-wrap gap-1.5">
                                        <span class="text-xs text-surface-400">Variables:</span>
                                        <span v-for="v in detectedVars" :key="v"
                                            class="bg-brand-50 text-brand-600 text-[10px] font-mono px-2 py-0.5 rounded-lg border border-brand-100 cursor-pointer hover:bg-brand-100"
                                            @click="insertVariableAtCursor(v)" title="Click to insert">
                                            {{ formatVar(v) }}
                                        </span>
                                    </div>
                                    <p class="text-[10px] text-surface-300 shrink-0 text-right">
                                        *bold* · _italic_ · ~strike~ · ```code```
                                    </p>
                                </div>
                            </div>

                            <!-- Media Upload -->
                            <div>
                                <label class="block text-xs font-medium mb-1">Attach Media</label>
                                <div class="flex items-center gap-3">
                                    <input type="file" id="media" class="hidden"
                                        @change="e => form.media = e.target.files[0]" />
                                    <label for="media"
                                        class="cursor-pointer px-4 py-2 bg-brand-50 text-brand-600 text-xs font-medium rounded-lg border border-brand-200 hover:bg-brand-100 hover:text-brand-700 transition">
                                        Choose File
                                    </label>
                                    <span v-if="form.media" class="text-xs text-surface-600 truncate max-w-[200px]">
                                        Selected: {{ form.media.name || form.media }}
                                    </span>
                                    <span v-else-if="editingTemplate?.media" class="text-xs text-surface-400">
                                        Current: <a :href="editingTemplate.media" target="_blank" class="text-brand-500 hover:underline">View</a>
                                    </span>
                                </div>
                                <p class="text-xs text-surface-400 mt-1">Optional: image, PDF, or other file (max 5MB)</p>
                            </div>
                        </div>

                        <div class="p-6 border-t border-surface-100 flex justify-end gap-2 shrink-0">
                            <button type="button" @click="closeForm" class="px-4 py-2 text-sm text-surface-600">Cancel</button>
                            <button @click="submitForm" :disabled="submitting"
                                class="px-5 py-2 bg-brand-500 text-white text-sm font-medium rounded-xl hover:bg-brand-600 disabled:opacity-50">
                                {{ submitting ? 'Saving…' : editingTemplate ? 'Update Template' : 'Create Template' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </SuperAdminLayout>
</template>

<script setup>
import { computed, reactive, ref, nextTick } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Components/Layout/SuperAdminLayout.vue';

const formatVar = v => `{{${v}}}`;

const props = defineProps({
    templates: { type: Object, required: true },
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
        router.get(route('superadmin.templates.index'), { search: search.value, category: filterCategory.value }, { preserveState: true, replace: true });
    }, 350);
}

function categoryColor(key) {
    return props.categories[key]?.color ?? 'bg-surface-100 text-surface-600';
}

// -- Create / Edit form
const showForm = ref(false);
const editingTemplate = ref(null);
const submitting = ref(false);

const form = reactive({
    name: '', body: '', category: 'general', is_active: true, media: null,
});

const detectedVars = computed(() => {
    const matches = form.body.match(/\{\{(\w+)\}\}/g) ?? [];
    return [...new Set(matches.map(m => m.replace(/\{\{|\}\}/g, '')))];
});

// -- WhatsApp formatting toolbar
const bodyTextarea = ref(null);
const bodyTab = ref('edit');
const showVarPicker = ref(false);

const quickVars = computed(() => {
    const builtIn = ['customer_name', 'customer_phone', 'customer_email'];
    const custom = detectedVars.value.filter(v => !builtIn.includes(v));
    return [...builtIn, ...custom];
});

const formatButtons = [
    { label: '<strong>B</strong>', title: 'Bold (*text*)', wrap: '*' },
    { label: '<em>I</em>', title: 'Italic (_text_)', wrap: '_' },
    { label: '<s>S</s>', title: 'Strikethrough (~text~)', wrap: '~' },
    { label: '<code>{ }</code>', title: 'Inline code (```text```)', wrap: '```' },
    { label: '↵', title: 'New line', insert: '\n' },
];

function applyFormat(fmt) {
    const el = bodyTextarea.value;
    if (!el) return;
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const val = form.body;

    if (fmt.insert !== undefined) {
        form.body = val.slice(0, start) + fmt.insert + val.slice(end);
        nextTick(() => { el.selectionStart = el.selectionEnd = start + fmt.insert.length; el.focus(); });
        return;
    }

    const selected = val.slice(start, end);
    const marker = fmt.wrap;

    if (selected.startsWith(marker) && selected.endsWith(marker) && selected.length >= marker.length * 2) {
        const inner = selected.slice(marker.length, selected.length - marker.length);
        form.body = val.slice(0, start) + inner + val.slice(end);
        nextTick(() => { el.selectionStart = start; el.selectionEnd = start + inner.length; el.focus(); });
    } else {
        const replacement = marker + (selected || 'text') + marker;
        form.body = val.slice(0, start) + replacement + val.slice(end);
        nextTick(() => {
            if (selected) { el.selectionStart = start; el.selectionEnd = start + replacement.length; }
            else { el.selectionStart = start + marker.length; el.selectionEnd = start + marker.length + 4; }
            el.focus();
        });
    }
}

function insertVariable(varName) { insertVariableAtCursor(varName); showVarPicker.value = false; }

function insertVariableAtCursor(varName) {
    const el = bodyTextarea.value;
    bodyTab.value = 'edit';
    nextTick(() => {
        if (!el) { form.body += `{{${varName}}}`; return; }
        const start = el.selectionStart ?? form.body.length;
        const end = el.selectionEnd ?? form.body.length;
        const insert = `{{${varName}}}`;
        form.body = form.body.slice(0, start) + insert + form.body.slice(end);
        nextTick(() => { el.selectionStart = el.selectionEnd = start + insert.length; el.focus(); });
    });
}

function insertCustomVar() {
    showVarPicker.value = false;
    const name = prompt('Variable name (letters and underscores only):\ne.g. amount, appointment_date');
    if (!name) return;
    const clean = name.trim().replace(/[^\w]/g, '_');
    if (clean) insertVariableAtCursor(clean);
}

function renderWaMarkdown(text) {
    if (!text) return '';
    const hasHtml = /<[^>]+>/.test(text);
    let html = text;
    if (!hasHtml) {
        html = html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    html = html
        .replace(/```([\s\S]*?)```/g, '<code class="wa-code">$1</code>')
        .replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')
        .replace(/_([^_\n]+)_/g, '<em>$1</em>')
        .replace(/~([^~\n]+)~/g, '<del>$1</del>')
        .replace(/\n/g, '<br>')
        .replace(/\{\{(\w+)\}\}/g, '<span class="wa-var">{{$1}}</span>');
    return html;
}

function openCreate() {
    editingTemplate.value = null;
    bodyTab.value = 'edit';
    Object.assign(form, { name: '', body: '', category: 'general', is_active: true, media: null });
    showForm.value = true;
}

function openEdit(t) {
    editingTemplate.value = t;
    bodyTab.value = 'edit';
    Object.assign(form, { name: t.name, body: t.body, category: t.category, is_active: t.is_active, media: null });
    showForm.value = true;
}

function closeForm() { showForm.value = false; }

function submitForm() {
    submitting.value = true;
    const url = editingTemplate.value
        ? route('superadmin.templates.update', editingTemplate.value.id)
        : route('superadmin.templates.store');

    const fd = new FormData();
    fd.append('name', form.name);
    fd.append('body', form.body);
    fd.append('category', form.category);
    fd.append('is_active', form.is_active ? 1 : 0);
    if (form.media) fd.append('media', form.media);
    if (editingTemplate.value) fd.append('_method', 'PATCH');

    router.post(url, fd, {
        forceFormData: true,
        onSuccess: () => { closeForm(); },
        onFinish: () => { submitting.value = false; },
    });
}

function deleteTemplate(t) {
    if (!confirm(`Delete template "${t.name}"? This will affect all companies using it.`)) return;
    router.delete(route('superadmin.templates.destroy', t.id));
}
</script>

<style scoped>
.input-field {
    @apply w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400;
}
.line-clamp-1 {
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.modal-enter-active, .modal-leave-active { transition: opacity .15s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
.wa-preview {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 0.8125rem;
    color: #111b21;
    word-break: break-word;
}
.wa-preview strong { font-weight: 700; }
.wa-preview em { font-style: italic; }
.wa-preview del { text-decoration: line-through; color: #667781; }
.wa-preview .wa-code {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.75rem;
    background: rgba(0,0,0,0.06);
    border-radius: 4px;
    padding: 0 3px;
    white-space: pre-wrap;
}
.wa-preview .wa-var {
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
