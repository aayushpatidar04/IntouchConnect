<template>
    <AppLayout title="Templates">
        <template #actions>
            <!-- <button v-if="isAdmin" @click="openCreate"
                class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors shadow-sm shadow-brand-500/30">
                + New Template
            </button> -->
            <Link :href="route('templates.broadcasts.history')"
                class="text-sm text-surface-500 hover:text-surface-800 px-3 py-2 rounded-xl hover:bg-surface-100 transition-colors">
                Broadcast History
            </Link>
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

            <!-- Template cards grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <div v-for="t in templates.data" :key="t.id"
                    class="bg-white rounded-2xl border border-surface-100 p-5 flex flex-col gap-3 hover:shadow-sm transition-shadow group">

                    <!-- Header -->
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-semibold text-surface-900 text-sm truncate">{{ t.name }}</h3>
                                <span :class="['badge text-[10px]', categoryColor(t.category)]">
                                    {{ categories[t.category]?.label ?? t.category }}
                                </span>
                            </div>
                            <p class="text-xs text-surface-400 mt-0.5">
                                by {{ t.created_by }} · {{ t.broadcasts_count }} broadcast{{ t.broadcasts_count === 1 ?
                                    '' : 's'
                                }}
                            </p>
                        </div>
                        <span v-if="!t.is_active"
                            class="shrink-0 badge bg-surface-100 text-surface-400 text-[10px]">Inactive</span>
                    </div>

                    <!-- Body preview -->
                    <div class="bg-surface-50 rounded-xl px-3 py-2.5 text-xs text-surface-600 leading-relaxed line-clamp-4 font-mono whitespace-pre-wrap"
                        v-html="renderWaMarkdown(t.body)" />


                    <!-- Variables -->
                    <div v-if="t.variables.length" class="flex flex-wrap gap-1.5">
                        <span v-for="v in t.variables" :key="v"
                            class="bg-brand-50 text-brand-600 text-[10px] font-mono px-2 py-0.5 rounded-lg border border-brand-100">
                            {{ formatVar(v) }}
                        </span>

                    </div>

                    <!-- Access info -->
                    <p class="text-[11px] text-surface-400">
                        <span v-if="t.assigned_all">👥 All executives</span>
                        <span v-else>👤 {{t.assigned_users.map(u => u.name).join(', ')}}</span>
                    </p>

                    <!-- Actions -->
                    <div class="flex items-center gap-2 mt-auto pt-2 border-t border-surface-100">
                        <!-- Exec: Send button -->
                        <button @click="openSend(t)" :disabled="!t.is_active"
                            class="flex-1 flex items-center justify-center gap-1.5 bg-brand-500 hover:bg-brand-600 disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-medium px-3 py-2 rounded-xl transition-colors">
                            📤 Send to Customers
                        </button>

                        <!-- Admin: Edit + Delete -->
                        <!-- <template v-if="isAdmin">
                            <button @click="openEdit(t)"
                                class="px-3 py-2 text-xs text-surface-500 hover:text-surface-800 border border-surface-200 rounded-xl hover:bg-surface-50 transition-colors">
                                Edit
                            </button>
                            <button @click="deleteTemplate(t)"
                                class="px-3 py-2 text-xs text-red-400 hover:text-red-600 border border-red-100 rounded-xl hover:bg-red-50 transition-colors">
                                Delete
                            </button>
                        </template> -->
                    </div>
                </div>

                <div v-if="!templates.data.length" class="col-span-full text-center py-16 text-sm text-surface-400">
                    <p class="text-3xl mb-3">📝</p>
                    <p>No templates yet.</p>
                    <p v-if="isAdmin" class="mt-1">Click <strong>+ New Template</strong> to create one.</p>
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

        <!-- ── Create / Edit Modal ───────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeForm" />
                    <div
                        class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col animate-slide-up">
                        <div class="p-6 border-b border-surface-100 shrink-0">
                            <h2 class="text-base font-semibold">{{ editingTemplate ? 'Edit Template' : 'New Template' }}
                            </h2>
                            <p class="text-xs text-surface-400 mt-0.5">
                                Use <code class="bg-surface-100 px-1 rounded">{{ formatVar('variable_name') }}</code>
                                for
                                dynamic
                                values.
                                Built-in: <code
                                    class="bg-surface-100 px-1 rounded">{{ formatVar('customer_name') }}</code>
                                <code class="bg-surface-100 px-1 rounded">{{ formatVar('customer_phone') }}</code>
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
                                        <option v-for="(meta, key) in categories" :key="key" :value="key">{{ meta.label
                                        }}
                                        </option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-2 pt-5">
                                    <input v-model="form.is_active" type="checkbox" id="is_active" class="rounded" />
                                    <label for="is_active" class="text-sm text-surface-700">Active (executives can
                                        use)</label>
                                </div>
                            </div>

                            <!-- Body -->
                            <!-- <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-xs font-medium">Message Body *</label>
                                    <span class="text-xs text-surface-400">{{ form.body.length }} / 4096</span>
                                </div>
                                <textarea v-model="form.body" rows="8" required maxlength="4096"
                                    placeholder="Hi {{customer_name}}, thank you for reaching out…"
                                    class="input-field resize-none font-mono text-xs leading-relaxed" />
                                
                                <div v-if="detectedVars.length" class="flex flex-wrap gap-1.5 mt-2">
                                    <span class="text-xs text-surface-400">Detected variables:</span>
                                    <span v-for="v in detectedVars" :key="v"
                                        class="bg-brand-50 text-brand-600 text-[10px] font-mono px-2 py-0.5 rounded-lg border border-brand-100">
                                        {{ formatVar(v) }}
                                    </span>
                                </div>
                            </div> -->

                            <!-- Body with WhatsApp formatting toolbar -->
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-xs font-medium">Message Body *</label>
                                    <span class="text-xs text-surface-400">{{ form.body.length }} / 4096</span>
                                </div>

                                <!-- Formatting toolbar -->
                                <div
                                    class="flex items-center gap-0.5 px-2 py-1.5 bg-surface-50 border border-surface-200 border-b-0 rounded-t-xl flex-wrap">
                                    <button v-for="fmt in formatButtons" :key="fmt.label" type="button"
                                        @click="applyFormat(fmt)" :title="fmt.title"
                                        class="px-2 py-1 rounded-lg text-surface-600 hover:bg-surface-200 hover:text-surface-900 transition-colors text-xs font-medium select-none"
                                        :class="fmt.class ?? ''">
                                        <span v-html="fmt.label" />
                                    </button>

                                    <span class="w-px h-4 bg-surface-200 mx-1 shrink-0" />

                                    <!-- Variable inserter -->
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

                                    <!-- Editor / Preview toggle -->
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

                                <!-- Editor pane -->
                                <textarea v-show="bodyTab === 'edit'" ref="bodyTextarea" v-model="form.body" rows="8"
                                    required maxlength="4096"
                                    placeholder="Hi *{{customer_name}}*, thank you for reaching out…"
                                    @click="showVarPicker = false" @keydown.escape="showVarPicker = false"
                                    class="w-full border border-surface-200 rounded-b-xl px-3 py-2.5 text-xs font-mono leading-relaxed focus:outline-none focus:ring-2 focus:ring-brand-400 resize-none" />

                                <!-- Preview pane — renders WhatsApp markdown visually -->
                                <div v-show="bodyTab === 'preview'"
                                    class="min-h-[168px] border border-surface-200 rounded-b-xl px-3 py-2.5 bg-[#e9f5e1]">
                                    <div v-if="form.body" class="text-sm leading-relaxed wa-preview"
                                        v-html="renderWaMarkdown(form.body)" />
                                    <p v-else class="text-xs text-surface-400 italic">
                                        Start typing to see the WhatsApp preview…
                                    </p>
                                </div>

                                <!-- Detected variables + formatting hint -->
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

                            <!-- Media -->
                            <!-- Media Upload -->
                            <div>
                                <label class="block text-xs font-medium mb-1">Attach Media</label>
                                <div class="flex items-center gap-3">
                                    <!-- Hidden native input -->
                                    <input type="file" id="media" class="hidden"
                                        @change="e => form.media = e.target.files[0]" />

                                    <!-- Custom button -->
                                    <label for="media"
                                        class="cursor-pointer px-4 py-2 bg-brand-50 text-brand-600 text-xs font-medium rounded-lg border border-brand-200 hover:bg-brand-100 hover:text-brand-700 transition">
                                        Choose File
                                    </label>

                                    <!-- File name preview -->
                                    <span v-if="form.media" class="text-xs text-surface-600 truncate max-w-[200px]">
                                        Selected: {{ form.media.name }}
                                    </span>
                                </div>
                                <p class="text-xs text-surface-400 mt-1">
                                    Optional: image, PDF, or other file (max 5MB)
                                </p>
                            </div>


                            <!-- Assign executives -->
                            <div>
                                <label class="block text-xs font-medium mb-2">Assign to Executives</label>
                                <p class="text-xs text-surface-400 mb-2">
                                    Leave all unchecked to allow all executives in your company to use this template.
                                </p>
                                <div
                                    class="space-y-2 max-h-40 overflow-y-auto border border-surface-100 rounded-xl p-3">
                                    <label v-for="exec in executives" :key="exec.id"
                                        class="flex items-center gap-2 cursor-pointer text-sm hover:bg-surface-50 px-1 py-0.5 rounded">
                                        <input type="checkbox" :value="exec.id" v-model="form.assigned_users"
                                            class="rounded text-brand-500" />
                                        {{ exec.name }}
                                    </label>
                                    <p v-if="!executives.length" class="text-xs text-surface-400 text-center py-2">
                                        No executives in your company yet.
                                    </p>
                                </div>
                                <p v-if="form.assigned_users.length === 0" class="text-xs text-brand-600 mt-1">
                                    ✓ All executives can use this template.
                                </p>
                                <p v-else class="text-xs text-surface-500 mt-1">
                                    {{ form.assigned_users.length }} executive{{ form.assigned_users.length === 1 ? '' :
                                        's' }}
                                    selected.
                                </p>
                            </div>
                        </div>

                        <div class="p-6 border-t border-surface-100 flex justify-end gap-2 shrink-0">
                            <button type="button" @click="closeForm"
                                class="px-4 py-2 text-sm text-surface-600">Cancel</button>
                            <button @click="submitForm" :disabled="submitting"
                                class="px-5 py-2 bg-brand-500 text-white text-sm font-medium rounded-xl hover:bg-brand-600 disabled:opacity-50">
                                {{ submitting ? 'Saving…' : editingTemplate ? 'Update Template' : 'Create Template' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── Send / Broadcast Modal ────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="showSend" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeSend" />
                    <div
                        class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col animate-slide-up">

                        <!-- Header -->
                        <div class="p-6 border-b border-surface-100 shrink-0">
                            <h2 class="text-base font-semibold">Send: {{ sendingTemplate?.name }}</h2>
                            <p class="text-xs text-surface-400 mt-0.5">
                                Fill variables → select customers → send. Messages will be delivered with natural
                                delays.
                            </p>
                        </div>

                        <div class="flex-1 overflow-y-auto">
                            <div
                                class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-surface-100">

                                <!-- Left: variables + preview -->
                                <div class="p-5 space-y-4">
                                    <div>
                                        <h3
                                            class="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                                            Variable Values
                                        </h3>

                                        <div v-if="sendingTemplate?.variables.length" class="space-y-3">
                                            <!-- customer_name and customer_phone are auto-filled, show as read-only hints -->
                                            <div v-for="v in customVariables" :key="v" class="space-y-1">
                                                <label class="block text-xs font-medium text-surface-700">
                                                    {{ v }}
                                                    <span class="text-surface-400 font-normal ml-1">— same value sent to
                                                        all</span>
                                                </label>
                                                <input v-model="sendForm.variable_values[v]"
                                                    :placeholder="`Enter ${v}…`" class="input-field" />
                                            </div>
                                            <div v-if="!customVariables.length"
                                                class="text-xs text-surface-400 bg-surface-50 rounded-xl p-3">
                                                Only auto-filled variables (customer_name, customer_phone). No manual
                                                input
                                                needed.
                                            </div>
                                        </div>
                                        <p v-else class="text-xs text-surface-400 bg-surface-50 rounded-xl p-3">
                                            No variables — this template sends as-is.
                                        </p>
                                    </div>

                                    <!-- Live preview -->
                                    <div>
                                        <div class="flex items-center justify-between mb-2">
                                            <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                                Preview
                                            </h3>
                                            <span class="text-[10px] text-surface-400">customer_name → first selected
                                                customer</span>
                                        </div>
                                        <div
                                            class="bg-[#e9f5e1] rounded-xl px-3 py-3 text-sm leading-relaxed min-h-[80px]">
                                            <div v-if="previewBody" class="wa-preview"
                                                v-html="renderWaMarkdown(previewBody)" />
                                            <p v-else class="text-xs text-surface-400 italic">Fill in the variables to
                                                see a
                                                preview…</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: customer selection -->
                                <div class="p-5 flex flex-col gap-3">
				    <div class="flex items-center justify-between">
                                        <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Select Groups
                                            <span class="text-brand-500 ml-1">({{ selectedGroups.length }}
                                                selected)</span>
                                        </h3>
                                        <div class="flex gap-2">
                                            <button @click="selectAllGroups"
                                                class="text-xs text-brand-500 hover:text-brand-700">All</button>
                                            <button @click="deselectAllGroups"
                                                class="text-xs text-surface-400 hover:text-surface-600">None</button>
                                        </div>
                                    </div>

                                    <!-- Group search -->
                                    <input v-model="groupSearch" placeholder="Search groups…"
                                        class="input-field text-xs" />

                                    <!-- Group list -->
                                    <div
                                        class="flex-1 overflow-y-auto max-h-40 border border-surface-100 rounded-xl divide-y divide-surface-50">
                                        <label v-for="g in filteredGroups" :key="g.id"
                                            class="flex items-center gap-3 px-3 py-2.5 hover:bg-surface-50 cursor-pointer">
                                            <input type="checkbox" :value="g.id" v-model="selectedGroups"
                                                class="rounded text-brand-500 shrink-0" />
                                            <div class="min-w-0">
                                                <p class="text-xs font-medium text-surface-800 truncate">{{ g.name }}
                                                </p>
                                                <p class="text-[10px] text-surface-400">{{ g.customers_count }}
                                                    customers</p>
                                            </div>
                                        </label>
                                        <p v-if="!filteredGroups.length"
                                            class="text-xs text-surface-400 text-center py-6">
                                            No groups found.
                                        </p>
                                    </div>


                                    <div class="flex items-center justify-between">
                                        <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Select Customers
                                            <span class="text-brand-500 ml-1">({{ selectedCustomers.length }}
                                                selected)</span>
                                        </h3>
                                        <div class="flex gap-2">
                                            <button @click="selectAllCustomers"
                                                class="text-xs text-brand-500 hover:text-brand-700">All</button>
                                            <button @click="deselectAllCustomers"
                                                class="text-xs text-surface-400 hover:text-surface-600">None</button>
                                        </div>
                                    </div>

                                    <!-- Customer search -->
                                    <input v-model="customerSearch" placeholder="Search customers…"
                                        class="input-field text-xs" />

                                    <!-- Filter by assignment -->
                                    <select v-model="customerFilter" class="input-field text-xs">
                                        <option value="">All statuses</option>
                                        <option value="active">Active only</option>
                                        <option value="inactive">Inactive only</option>
                                    </select>

                                    <!-- Customer list -->
                                    <div
                                        class="flex-1 overflow-y-auto max-h-64 border border-surface-100 rounded-xl divide-y divide-surface-50">
                                        <label v-for="c in filteredCustomers" :key="c.id"
                                            class="flex items-center gap-3 px-3 py-2.5 hover:bg-surface-50 cursor-pointer">
                                            <input type="checkbox" :value="c.id" v-model="selectedCustomers"
                                                class="rounded text-brand-500 shrink-0" />
                                            <div class="min-w-0">
                                                <p class="text-xs font-medium text-surface-800 truncate">{{ c.name }}
                                                </p>
                                                <p class="text-[10px] font-mono text-surface-400">+{{ c.phone }}</p>
                                            </div>
                                            <span
                                                :class="['badge ml-auto text-[9px] shrink-0', c.status === 'active' ? 'bg-brand-100 text-brand-700' : 'bg-surface-100 text-surface-400']">{{
                                                    c.status }}</span>
                                        </label>
                                        <p v-if="!filteredCustomers.length"
                                            class="text-xs text-surface-400 text-center py-6">
                                            No customers found.
                                        </p>
                                    </div>

                                    <!-- ETA estimate -->
				    <div v-if="(selectedCustomers.length > 0 || selectedGroups.length > 0)"
                                        class="bg-amber-50 border border-amber-100 rounded-xl px-3 py-2 text-xs text-amber-700">
                                        ⏱️ Estimated delivery: <strong>~{{ etaMinutes }} minutes</strong>
                                        ({{ totalRecipients }} messages at ~4s apart)
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="p-5 border-t border-surface-100 flex items-center justify-between gap-3 shrink-0">
                            <p class="text-xs text-surface-400">
                                Messages are queued with natural delays to protect your number from blocks.
                            </p>
                            <div class="flex gap-2 shrink-0">
                                <button @click="closeSend" class="px-4 py-2 text-sm text-surface-600">Cancel</button>
				<button @click="submitBroadcast"
                                    :disabled="(selectedCustomers.length === 0 && selectedGroups.length === 0) || sending"
                                    class="px-5 py-2 bg-brand-500 text-white text-sm font-medium rounded-xl hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                                    <span v-if="sending"
                                        class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                                    <template v-else>
                                        Send to
                                        <span class="font-semibold">
                                            {{ totalRecipients }}
                                        </span>
                                        {{ totalRecipients === 1 ? 'recipient' :
                                            'recipients' }}
                                    </template>
                                    <span v-if="sending">Queuing…</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Broadcast success toast -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="broadcastResult"
                    class="fixed bottom-6 right-6 z-50 bg-white rounded-2xl shadow-2xl border border-brand-100 p-5 w-80 animate-slide-up">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl">🎉</span>
                        <div>
                            <p class="font-semibold text-sm text-surface-900">Broadcast Queued!</p>
                            <p class="text-xs text-surface-500 mt-1">{{ broadcastResult.message }}</p>
                            <Link :href="route('templates.broadcasts.history')"
                                class="text-xs text-brand-500 hover:text-brand-700 font-medium mt-2 block">
                                View progress →
                            </Link>
                        </div>
                        <button @click="broadcastResult = null"
                            class="ml-auto text-surface-300 hover:text-surface-500">✕</button>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { computed, reactive, ref, watch, nextTick } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import axios from 'axios';
const formatVar = v => `{{${v}}}`;


const props = defineProps({
    templates: { type: Object, required: true },
    executives: { type: Array, default: () => [] },
    categories: { type: Object, default: () => ({}) },
    isAdmin: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
});

// ── Filters ───────────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
const filterCategory = ref(props.filters.category ?? '');
let searchTimer = null;
function doSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(route('templates.index'), { search: search.value, category: filterCategory.value }, { preserveState: true, replace: true });
    }, 350);
}

// ── Category color ────────────────────────────────────────────────────────────
function categoryColor(key) {
    return props.categories[key]?.color ?? 'bg-surface-100 text-surface-600';
}

// ── Create / Edit form ────────────────────────────────────────────────────────
const showForm = ref(false);
const editingTemplate = ref(null);
const submitting = ref(false);

const form = reactive({
    name: '', body: '', category: 'general', is_active: true, assigned_users: [], media: null,
});

const detectedVars = computed(() => {
    const matches = form.body.match(/\{\{(\w+)\}\}/g) ?? [];
    return [...new Set(matches.map(m => m.replace(/\{\{|\}\}/g, '')))];
});


// ── WhatsApp formatting toolbar ───────────────────────────────────────────────

const bodyTextarea = ref(null); // ref to the <textarea>
const bodyTab = ref('edit'); // 'edit' | 'preview'
const showVarPicker = ref(false);

// Quick-insert variable list: built-in ones always first, then custom detected
const quickVars = computed(() => {
    const builtIn = ['customer_name', 'customer_phone', 'customer_email'];
    const custom = detectedVars.value.filter(v => !builtIn.includes(v));
    return [...builtIn, ...custom];
});

// Format button definitions
// wrap: wraps selection with prefix+suffix  |  prefix-only: inserts at line start
const formatButtons = [
    { label: '<strong>B</strong>', title: 'Bold (*text*)', wrap: '*', class: '' },
    { label: '<em>I</em>', title: 'Italic (_text_)', wrap: '_', class: 'italic' },
    { label: '<s>S</s>', title: 'Strikethrough (~text~)', wrap: '~', class: '' },
    { label: '<code>{ }</code>', title: 'Inline code (```text```)', wrap: '```', class: '' },
    { label: '↵', title: 'New line', insert: '\n', class: '' },
];

/**
 * Apply a format wrap around the current textarea selection.
 * If nothing is selected, inserts markers and places cursor in between.
 */
function applyFormat(fmt) {
    const el = bodyTextarea.value;
    if (!el) return;

    const start = el.selectionStart;
    const end = el.selectionEnd;
    const val = form.body;

    if (fmt.insert !== undefined) {
        // Simple insert (e.g. newline)
        form.body = val.slice(0, start) + fmt.insert + val.slice(end);
        nextTick(() => {
            el.selectionStart = el.selectionEnd = start + fmt.insert.length;
            el.focus();
        });
        return;
    }

    const selected = val.slice(start, end);
    const marker = fmt.wrap;

    // Toggle: if selection already wrapped, unwrap; otherwise wrap
    if (selected.startsWith(marker) && selected.endsWith(marker) && selected.length >= marker.length * 2) {
        const inner = selected.slice(marker.length, selected.length - marker.length);
        form.body = val.slice(0, start) + inner + val.slice(end);
        nextTick(() => {
            el.selectionStart = start;
            el.selectionEnd = start + inner.length;
            el.focus();
        });
    } else {
        const replacement = marker + (selected || 'text') + marker;
        form.body = val.slice(0, start) + replacement + val.slice(end);
        nextTick(() => {
            if (selected) {
                el.selectionStart = start;
                el.selectionEnd = start + replacement.length;
            } else {
                // Place cursor between the markers
                el.selectionStart = start + marker.length;
                el.selectionEnd = start + marker.length + 4; // selects "text"
            }
            el.focus();
        });
    }
}

function insertVariable(varName) {
    insertVariableAtCursor(varName);
    showVarPicker.value = false;
}

function insertVariableAtCursor(varName) {
    const el = bodyTextarea.value;
    bodyTab.value = 'edit';
    nextTick(() => {
        if (!el) {
            form.body += `{{${varName}}}`;
            return;
        }
        const start = el.selectionStart ?? form.body.length;
        const end = el.selectionEnd ?? form.body.length;
        const insert = `{{${varName}}}`;
        form.body = form.body.slice(0, start) + insert + form.body.slice(end);
        nextTick(() => {
            el.selectionStart = el.selectionEnd = start + insert.length;
            el.focus();
        });
    });
}

function insertCustomVar() {
    showVarPicker.value = false;
    const name = prompt('Variable name (letters and underscores only):\ne.g. amount, appointment_date');
    if (!name) return;
    const clean = name.trim().replace(/[^\w]/g, '_');
    if (clean) insertVariableAtCursor(clean);
}

/**
 * Render WhatsApp markdown to HTML for the preview pane.
 * Handles: *bold*, _italic_, ~strikethrough~, ```code```, line breaks.
 * Also highlights {{variables}} with a coloured badge.
 */
function renderWaMarkdown(text) {
    if (!text) return '';

    // If text already contains HTML tags (like <img>), don't escape it
    const hasHtml = /<[^>]+>/.test(text);

    let html = text;
    if (!hasHtml) {
        // Escape HTML only for plain text
        html = html
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // WhatsApp formatting (order matters — code block before others)
    html = html
        // ```code block``` (multiline)
        .replace(/```([\s\S]*?)```/g, '<code class="wa-code">$1</code>')
        // *bold*
        .replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')
        // _italic_
        .replace(/_([^_\n]+)_/g, '<em>$1</em>')
        // ~strikethrough~
        .replace(/~([^~\n]+)~/g, '<del>$1</del>')
        // Line breaks
        .replace(/\n/g, '<br>')
        // {{variables}} — highlight
        .replace(/\{\{(\w+)\}\}/g,
            '<span class="wa-var">{{$1}}</span>');

    return html;
}



function openCreate() {
    editingTemplate.value = null;
    bodyTab.value = 'edit';
    Object.assign(form, { name: '', body: '', category: 'general', is_active: true, assigned_users: [], media: null, });
    showForm.value = true;
}

function openEdit(t) {
    editingTemplate.value = t;
    bodyTab.value = 'edit';
    Object.assign(form, {
        name: t.name, body: t.body, category: t.category,
        is_active: t.is_active,
        assigned_users: t.assigned_users.map(u => u.id),
        media: null,
    });
    showForm.value = true;
}

function closeForm() { showForm.value = false; }

function submitForm() {
    submitting.value = true;
    const url = editingTemplate.value
        ? route('templates.update', editingTemplate.value.id)
        : route('templates.store');
    const method = editingTemplate.value ? 'post' : 'post'; // always POST with FormData

    // Build FormData
    const fd = new FormData();
    fd.append('name', form.name);
    fd.append('body', form.body);
    fd.append('category', form.category);
    fd.append('is_active', form.is_active ? 1 : 0);

    form.assigned_users.forEach((id, i) => {
        fd.append(`assigned_users[${i}]`, id);
    });

    if (form.media) {
        fd.append('media', form.media);
    }

    if (editingTemplate.value) {
        fd.append('_method', 'PATCH'); // Laravel expects PATCH for update
    }

    router.post(url, fd, {
        forceFormData: true, // ensures Inertia sends as multipart/form-data
        onSuccess: () => { closeForm(); },
        onError: () => { /* handle errors */ },
        onFinish: () => { submitting.value = false; },
    });
}


function deleteTemplate(t) {
    if (!confirm(`Delete template "${t.name}"? This cannot be undone.`)) return;
    router.delete(route('templates.destroy', t.id));
}

// ── Send / Broadcast ──────────────────────────────────────────────────────────
const showSend = ref(false);
const sendingTemplate = ref(null);
const sending = ref(false);
const broadcastResult = ref(null);
const selectedCustomers = ref([]);
const customerSearch = ref('');
const customerFilter = ref('');
const allCustomers = ref([]);
const selectedGroups = ref([])
const groupSearch = ref('')
const allGroups = ref([])

const filteredGroups = computed(() => {
    let list = allGroups.value
    if (groupSearch.value) {
        const q = groupSearch.value.toLowerCase()
        list = list.filter(g => g.name.toLowerCase().includes(q))
    }
    return list
})

function selectAllGroups() { selectedGroups.value = filteredGroups.value.map(g => g.id) }
function deselectAllGroups() { selectedGroups.value = [] }

const sendForm = reactive({ variable_values: {} });

// Custom variables = all detected vars minus the auto-filled ones
const autoFilled = ['customer_name', 'customer_phone', 'customer_email'];
const customVariables = computed(() =>
    (sendingTemplate.value?.variables ?? []).filter(v => !autoFilled.includes(v))
);

// Live preview — uses first selected customer name if available
const previewBody = computed(() => {
    if (!sendingTemplate.value) return '';
    const firstCustomer = allCustomers.value.find(c => selectedCustomers.value[0] === c.id);
    const vals = {
        ...sendForm.variable_values,
        customer_name: firstCustomer?.name ?? 'John Smith',
        customer_phone: firstCustomer?.phone ?? '9876543210',
        customer_email: firstCustomer?.email ?? 'customer@example.com',
    };
    let body = sendingTemplate.value.body;
    for (const [k, v] of Object.entries(vals)) {
        body = body.replaceAll(`{{${k}}}`, v || `{{${k}}}`);
    }

    if (sendingTemplate.value.media) {
        const mediaUrl = sendingTemplate.value.media;

        // or use Storage::url() backend accessor to expose public URL
        return `<div class="mb-3">
                    <img src="${mediaUrl}" alt="Attached Media" class="max-h-48 rounded-lg border mb-2" />
                </div>${body}`;
    }

    return body;
});

const filteredCustomers = computed(() => {
    let list = allCustomers.value;
    if (customerSearch.value) {
        const q = customerSearch.value.toLowerCase();
        list = list.filter(c => c.name.toLowerCase().includes(q) || c.phone.includes(q));
    }
    if (customerFilter.value) {
        list = list.filter(c => c.status === customerFilter.value);
    }
    return list;
});

const etaMinutes = computed(() => {
    // count direct customers
    const directCount = selectedCustomers.value.length;

    // count customers from groups (flatten group memberships)
    const groupCustomerIds = selectedGroups.value
        .map(groupId => {
            const g = allGroups.value.find(gr => gr.id === groupId);
            return g?.customers?.map(c => c.id) ?? [];
        })
        .flat();

    // merge and deduplicate
    const uniqueIds = new Set([...selectedCustomers.value, ...groupCustomerIds]);
    const n = uniqueIds.size;

    if (n <= 1) return '< 1';
    const totalSeconds = n * 4; // ~4s per message
    return Math.ceil(totalSeconds / 60);
});

const totalRecipients = computed(() => {
    const groupCustomerIds = selectedGroups.value
        .map(groupId => {
            const g = allGroups.value.find(gr => gr.id === groupId);
            return g?.customers?.map(c => c.id) ?? [];
        })
        .flat();
    const uniqueIds = new Set([...selectedCustomers.value, ...groupCustomerIds]);
    return uniqueIds.size;
});



async function openSend(t) {
    sendingTemplate.value = t;
    sendForm.variable_values = {};
    selectedCustomers.value = [];
    selectedGroups.value = []
    customerSearch.value = '';
    groupSearch.value = ''
    showSend.value = true;

    // Load customers
    try {
        const { data } = await axios.get(route('customers.list'), {
            params: { per_page: 500 },
        });
        // Handle Inertia response vs JSON — if paginated, use .data.data
        allCustomers.value = data?.data?.data ?? data?.data ?? [];

    } catch {
        allCustomers.value = [];
    }

    try {
        const { data } = await axios.get(route('groups.list'), {
            params: { per_page: 200 }
        });
        allGroups.value = data?.data?.data ?? data?.data ?? []
    } catch { allGroups.value = [] }
}

function closeSend() { showSend.value = false; }

function selectAllCustomers() { selectedCustomers.value = filteredCustomers.value.map(c => c.id); }
function deselectAllCustomers() { selectedCustomers.value = []; }

async function submitBroadcast() {
    if (!selectedCustomers.value.length && !selectedGroups.value.length) return;
    sending.value = true;
    try {
        const { data } = await axios.post(route('templates.broadcast', sendingTemplate.value.id), {
            customer_ids: selectedCustomers.value,
	    group_ids: selectedGroups.value,
            variable_values: sendForm.variable_values,
        });
        broadcastResult.value = data;
        closeSend();
        setTimeout(() => { broadcastResult.value = null; }, 8000);
    } catch (err) {
        alert(err.response?.data?.error ?? 'Failed to start broadcast. Please try again.');
    } finally {
        sending.value = false;
    }
}
</script>

<style scoped>
.input-field {
    @apply w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400;
}

.line-clamp-4 {
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.modal-enter-active,
.modal-leave-active {
    transition: opacity .15s ease;
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

/* ── WhatsApp preview pane ─────────────────────────────────────────── */
/* Mimics the WhatsApp message bubble font and formatting */
.wa-preview {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 0.8125rem;
    /* 13px — WA's body size */
    color: #111b21;
    word-break: break-word;
}

.wa-preview strong {
    font-weight: 700;
}

.wa-preview em {
    font-style: italic;
}

.wa-preview del {
    text-decoration: line-through;
    color: #667781;
}

.wa-preview .wa-code {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.75rem;
    background: rgba(0, 0, 0, 0.06);
    border-radius: 4px;
    padding: 0 3px;
    white-space: pre-wrap;
}

/* Variable highlight in preview */
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

/* Card body preview — same rules, slightly smaller */
.wa-preview-card {
    font-size: 0.72rem;
    color: #374151;
}

.wa-preview-card strong {
    font-weight: 700;
}

.wa-preview-card em {
    font-style: italic;
}

.wa-preview-card del {
    text-decoration: line-through;
    color: #9ca3af;
}

.wa-preview-card .wa-code {
    font-family: monospace;
    background: rgba(0, 0, 0, 0.05);
    border-radius: 3px;
    padding: 0 2px;
}

.wa-preview-card .wa-var {
    background: #eff6ff;
    color: #1d4ed8;
    border-radius: 3px;
    padding: 0 3px;
    font-size: 0.65rem;
    font-family: monospace;
}
</style>
