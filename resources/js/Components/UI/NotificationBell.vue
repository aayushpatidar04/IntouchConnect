<!-- resources/js/Components/UI/NotificationBell.vue -->
<!-- NEW FILE — notification bell that appears in the top bar -->

<template>
  <div class="relative" ref="bellRef">
    <!-- Bell button -->
    <button
      @click="open = !open"
      class="relative flex items-center justify-center w-9 h-9 rounded-xl hover:bg-surface-100 transition-colors"
      title="Notifications"
    >
      <svg class="w-5 h-5 text-surface-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
      </svg>
      <!-- Unread badge -->
      <span
        v-if="store.unreadCount > 0"
        class="absolute -top-0.5 -right-0.5 w-4 h-4 rounded-full bg-red-500 text-white text-[9px] font-bold flex items-center justify-center"
      >
        {{ store.unreadCount > 9 ? '9+' : store.unreadCount }}
      </span>
    </button>

    <!-- Dropdown panel -->
    <Teleport to="body">
      <Transition name="dropdown">
        <div
          v-if="open"
          class="fixed z-50 w-80 bg-white rounded-2xl shadow-2xl border border-surface-100 overflow-hidden"
          :style="dropdownStyle"
        >
          <!-- Header -->
          <div class="flex items-center justify-between px-4 py-3 border-b border-surface-100">
            <span class="text-sm font-semibold text-surface-900">Notifications</span>
            <button
              v-if="store.unreadCount > 0"
              @click="store.markAllRead()"
              class="text-xs text-brand-600 hover:text-brand-700 font-medium"
            >
              Mark all read
            </button>
          </div>

          <!-- List -->
          <div class="max-h-80 overflow-y-auto scrollbar-thin divide-y divide-surface-50">
            <div v-if="store.items.length === 0" class="text-center py-8 text-sm text-surface-400">
              No notifications yet
            </div>

            <div
              v-for="notif in store.items"
              :key="notif.id"
              @click="openCustomer(notif)"
              :class="[
                'flex gap-3 px-4 py-3 cursor-pointer hover:bg-surface-50 transition-colors',
                !notif.read ? 'bg-brand-50/40' : ''
              ]"
            >
              <!-- Icon -->
              <div :class="[
                'w-8 h-8 rounded-full flex items-center justify-center shrink-0 text-sm',
                notif.is_unassigned ? 'bg-amber-100' : 'bg-brand-100'
              ]">
                {{ notif.has_document ? '📎' : '💬' }}
              </div>

              <!-- Content -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-1">
                  <p class="text-xs font-semibold text-surface-900 truncate">{{ notif.customer_name }}</p>
                  <span class="text-[10px] text-surface-400 shrink-0">{{ timeAgo(notif.created_at) }}</span>
                </div>
                <p class="text-xs text-surface-500 truncate mt-0.5">
                  {{ notif.body || (notif.has_document ? 'Sent a document' : `[${notif.type}]`) }}
                </p>
                <span
                  v-if="notif.is_unassigned"
                  class="inline-block mt-1 text-[9px] font-medium bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full"
                >
                  Unassigned
                </span>
              </div>

              <!-- Unread dot -->
              <span v-if="!notif.read" class="w-2 h-2 rounded-full bg-brand-500 shrink-0 mt-1.5" />
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { useNotificationStore } from '@/Stores/notifications';
import { formatDistanceToNow } from 'date-fns';

const store    = useNotificationStore();
const open     = ref(false);
const bellRef  = ref(null);
const dropdownStyle = ref({});

// Position dropdown below the bell button
function updatePosition() {
    if (!bellRef.value) return;
    const rect = bellRef.value.getBoundingClientRect();
    dropdownStyle.value = {
        top:   `${rect.bottom + 8}px`,
        right: `${window.innerWidth - rect.right}px`,
    };
}

// Close on click outside
function handleClickOutside(e) {
    if (open.value && bellRef.value && !bellRef.value.contains(e.target)) {
        open.value = false;
    }
}

// Watch open state to recompute position
const stopWatch = () => {};
import { watch } from 'vue';
watch(open, (val) => { if (val) updatePosition(); });

onMounted(() => document.addEventListener('click', handleClickOutside, true));
onUnmounted(() => document.removeEventListener('click', handleClickOutside, true));

function openCustomer(notif) {
    store.markRead(notif.id);
    open.value = false;
    router.visit(route('customers.show', notif.customer_id));
}

function timeAgo(ts) {
    if (!ts) return '';
    try { return formatDistanceToNow(new Date(ts), { addSuffix: true }); } catch { return ''; }
}
</script>

<style scoped>
.dropdown-enter-active, .dropdown-leave-active { transition: all .15s ease; }
.dropdown-enter-from, .dropdown-leave-to { opacity: 0; transform: translateY(-4px); }
</style>