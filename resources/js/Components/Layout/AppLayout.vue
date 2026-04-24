<template>
    <div class="flex h-screen bg-surface-50 overflow-hidden">
        <!-- Sidebar -->
        <aside :class="[
            'flex flex-col bg-surface-900 text-white transition-all duration-300 shrink-0',
            sidebarOpen ? 'w-64' : 'w-16'
        ]">
            <!-- Logo -->
            <div class="flex items-center gap-3 px-4 py-5 border-b border-surface-800">
                <div class="w-8 h-8 rounded-xl bg-brand-500 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                        <path
                            d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" />
                        <path
                            d="M12 0C5.373 0 0 5.373 0 12c0 2.126.557 4.123 1.527 5.855L0 24l6.335-1.51A11.94 11.94 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.81 9.81 0 01-5.002-1.37l-.358-.214-3.724.978.992-3.63-.236-.374A9.818 9.818 0 012.182 12C2.182 6.57 6.57 2.182 12 2.182S21.818 6.57 21.818 12 17.43 21.818 12 21.818z" />
                    </svg>
                </div>
                <span v-if="sidebarOpen" class="font-semibold text-sm tracking-wide whitespace-nowrap">WhatsApp
                    CRM</span>
            </div>

            <!-- Nav -->
            <nav class="flex-1 py-4 space-y-1 px-2 overflow-y-auto scrollbar-thin">
                <NavItem :href="route('dashboard')" :icon="HomeIcon" label="Dashboard" :open="sidebarOpen" />
                <NavItem :href="route('customers.index')" :icon="UsersIcon" label="Customers" :open="sidebarOpen" />
                <template v-if="$page.props.auth.user.roles?.includes('admin')">
                    <div class="pt-2 pb-1 px-2">
                        <span v-if="sidebarOpen"
                            class="text-xs uppercase tracking-widest text-surface-500 font-medium">Admin</span>
                        <div v-else class="border-t border-surface-700 my-1" />
                    </div>
                    <NavItem :href="route('admin.users')" :icon="ShieldIcon" label="Users" :open="sidebarOpen" />
                    <NavItem :href="route('admin.audit-logs')" :icon="ClipboardIcon" label="Audit Logs"
                        :open="sidebarOpen" />
                </template>
            </nav>

            <!-- WhatsApp status pill -->
            <div class="px-3 py-3 border-t border-surface-800">
                <div :class="['flex items-center gap-2 rounded-xl px-3 py-2', statusBg]">
                    <span :class="statusDot" />
                    <span v-if="sidebarOpen" class="text-xs font-medium whitespace-nowrap">{{ statusLabel }}</span>
                </div>
            </div>

            <!-- User + collapse -->
            <div class="p-3 border-t border-surface-800 flex items-center gap-2">
                <img :src="$page.props.auth.user.avatar_url" class="w-8 h-8 rounded-full shrink-0 object-cover" />
                <div v-if="sidebarOpen" class="min-w-0 flex-1">
                    <p class="text-xs font-medium truncate">{{ $page.props.auth.user.name }}</p>
                    <p class="text-xs text-surface-400 truncate">{{ roleLabel }}</p>
                </div>
                <button @click="sidebarOpen = !sidebarOpen"
                    class="ml-auto text-surface-400 hover:text-white transition-colors">
                    <ChevronLeftIcon v-if="sidebarOpen" class="w-4 h-4" />
                    <ChevronRightIcon v-else class="w-4 h-4" />
                </button>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <!-- Top bar -->
            <header class="h-14 bg-white border-b border-surface-100 flex items-center px-6 gap-4 shrink-0">
                <div class="flex-1">
                    <slot name="header">
                        <h1 class="text-base font-semibold text-surface-900">{{ title }}</h1>
                    </slot>
                </div>
                <div class="flex items-center gap-2">
                    <slot name="actions" />
                    <!-- Logout -->
                    <Link :href="route('logout')" method="post" as="button"
                        class="text-xs text-surface-400 hover:text-surface-700 transition-colors px-2 py-1 rounded-lg hover:bg-surface-100">
                        Sign out
                    </Link>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 overflow-y-auto scrollbar-thin">
                <slot />
            </main>
        </div>

        <!-- Toast container -->
        <ToastStack />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useWhatsAppStore } from '@/Stores/whatsapp';
import { useChannel } from '@/Composables/useEcho';
import NavItem from '@/Components/Layout/NavItem.vue';
import ToastStack from '@/Components/UI/ToastStack.vue';

// Icons (heroicons inline SVG via components)
import HomeIcon from '@/Components/Icons/HomeIcon.vue';
import UsersIcon from '@/Components/Icons/UsersIcon.vue';
import ShieldIcon from '@/Components/Icons/ShieldIcon.vue';
import ClipboardIcon from '@/Components/Icons/ClipboardIcon.vue';
import ChevronLeftIcon from '@/Components/Icons/ChevronLeftIcon.vue';
import ChevronRightIcon from '@/Components/Icons/ChevronRightIcon.vue';

defineProps({ title: String });

const sidebarOpen = ref(true);
const page = usePage();
const wa = useWhatsAppStore();

onMounted(() => {
    wa.fetchStatus();
    setInterval(wa.fetchStatus, 30000);
});

// Listen for real-time WhatsApp status changes
useChannel('whatsapp-status', {
    'status.changed': (data) => wa.handleStatusEvent(data),
});

const statusBg = computed(() => ({
    'connected': 'bg-brand-500/10 text-brand-400',
    'qr_ready': 'bg-amber-500/10 text-amber-400',
    'disconnected': 'bg-surface-700 text-surface-400',
    'unreachable': 'bg-red-500/10 text-red-400',
}[wa.status] ?? 'bg-surface-700 text-surface-400'));

const statusDot = computed(() => ({
    'connected': 'dot-connected',
    'qr_ready': 'dot-qr',
    'disconnected': 'dot-disconnected',
    'unreachable': 'dot-disconnected',
}[wa.status] ?? 'dot-disconnected'));

const statusLabel = computed(() => ({
    'connected': `WA: ${wa.phone ?? 'Connected'}`,
    'qr_ready': 'Scan QR Code',
    'disconnected': 'WA: Disconnected',
    'unreachable': 'Gateway Offline',
}[wa.status] ?? 'Unknown'));

const roleLabel = computed(() => {
    const roles = page.props.auth.user.roles ?? [];
    return roles[0] ? roles[0].charAt(0).toUpperCase() + roles[0].slice(1) : '';
});
</script>