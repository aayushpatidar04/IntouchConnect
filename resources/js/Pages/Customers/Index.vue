<template>
  <AppLayout title="Customers">
    <template #actions>
      <button @click="showCreateModal = true"
        class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors shadow-sm shadow-brand-500/30">
        <span>+</span> New Customer
      </button>
    </template>

    <div class="p-6 animate-fade-in">
      <!-- Filters -->
      <div class="flex flex-wrap gap-3 mb-5">
        <input v-model="filters.search" @input="debouncedSearch" type="text" placeholder="Search name, phone, company…"
          class="flex-1 min-w-[200px] rounded-xl border border-surface-200 bg-white px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
        <select v-model="filters.status" @change="applyFilters"
          class="w-1/4 rounded-xl border border-surface-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="blocked">Blocked</option>
        </select>
        <select v-if="$page.props.auth.user.roles?.includes('admin')" v-model="filters.assigned_to"
          @change="applyFilters"
          class="w-1/4 rounded-xl border border-surface-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
          <option value="">All executives</option>
          <option v-for="exec in executives" :key="exec.id" :value="exec.id">{{ exec.name }}</option>
        </select>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-2xl border border-surface-100 overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-surface-100 bg-surface-50">
              <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Customer
              </th>
              <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Phone</th>
              <th
                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">
                Assigned To</th>
              <th
                class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">
                Last Contact</th>
              <th class="text-left px-5 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider">Status
              </th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-surface-50">
            <tr v-if="customers.data.length === 0">
              <td colspan="6" class="text-center py-12 text-sm text-surface-400">No customers found</td>
            </tr>
            <tr v-for="c in customers.data" :key="c.id"
              class="hover:bg-surface-50 transition-colors cursor-pointer group"
              @click="router.visit(route('customers.show', c.id))">
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-3">
                  <img
                    :src="`https://ui-avatars.com/api/?name=${encodeURIComponent(c.name)}&background=e2e8f0&color=475569&size=40`"
                    class="w-8 h-8 rounded-full" />
                  <div>
                    <p class="font-medium text-surface-900">{{ c.name }}</p>
                    <p v-if="c.company" class="text-xs text-surface-400">{{ c.company }}</p>
                  </div>
                  <!-- Unread badge -->
                  <span v-if="c.unread_count > 0"
                    class="ml-1 w-5 h-5 rounded-full bg-brand-500 text-white text-[10px] font-bold flex items-center justify-center">
                    {{ c.unread_count > 9 ? '9+' : c.unread_count }}
                  </span>
                </div>
              </td>
              <td class="px-5 py-3.5 font-mono text-xs text-surface-600">+{{ c.phone }}</td>
              <td class="px-5 py-3.5 hidden md:table-cell text-surface-500 text-xs">{{ c.assigned_to?.name ?? '—' }}
              </td>
              <td class="px-5 py-3.5 hidden lg:table-cell text-surface-400 text-xs">
                {{ c.last_contacted_at ? timeAgo(c.last_contacted_at) : 'Never' }}
              </td>
              <td class="px-5 py-3.5">
                <span :class="['badge', statusClass(c.status)]">{{ c.status }}</span>
              </td>
              <td class="px-5 py-3.5 text-right" @click.stop>
                <Link :href="route('customers.show', c.id)"
                  class="text-xs font-medium text-brand-600 hover:text-brand-700 opacity-0 group-hover:opacity-100 transition-opacity">
                  Open →
                </Link>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div v-if="customers.last_page > 1"
          class="border-t border-surface-50 px-5 py-3 flex items-center justify-between">
          <p class="text-xs text-surface-400">Showing {{ customers.from }}–{{ customers.to }} of {{ customers.total }}
          </p>
          <div class="flex gap-1">
            <Link v-if="customers.prev_page_url" :href="customers.prev_page_url"
              class="px-3 py-1.5 text-xs rounded-lg bg-surface-100 hover:bg-surface-200 transition-colors">← Prev</Link>
            <Link v-if="customers.next_page_url" :href="customers.next_page_url"
              class="px-3 py-1.5 text-xs rounded-lg bg-surface-100 hover:bg-surface-200 transition-colors">Next →</Link>
          </div>
        </div>
      </div>
    </div>

    <!-- Create customer modal -->
    <Teleport to="body">
      <Transition name="modal">
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="showCreateModal = false" />
          <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 animate-slide-up">
            <h2 class="text-base font-semibold mb-5">New Customer</h2>
            <CustomerForm :executives="executives" @saved="showCreateModal = false" @cancel="showCreateModal = false" />
          </div>
        </div>
      </Transition>
    </Teleport>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import CustomerForm from '@/Components/UI/CustomerForm.vue';
import { formatDistanceToNow } from 'date-fns';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
  customers: { type: Object, required: true },
  executives: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
});

const showCreateModal = ref(false);
const filters = reactive({ ...props.filters });

const debouncedSearch = useDebounceFn(applyFilters, 400);

function applyFilters() {
  router.get(route('customers.index'), filters, { preserveState: true, replace: true });
}

function statusClass(s) {
  return { active: 'bg-brand-100 text-brand-700', inactive: 'bg-surface-100 text-surface-600', blocked: 'bg-red-100 text-red-700' }[s] ?? '';
}
function timeAgo(ts) {
  return formatDistanceToNow(new Date(ts), { addSuffix: true });
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