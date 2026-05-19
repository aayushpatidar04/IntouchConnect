<template>
    <div class="flex h-screen bg-surface-50 overflow-hidden">
        <!-- Sidebar -->
        <aside :class="[
            'flex flex-col bg-surface-900 text-white transition-all duration-300 shrink-0',
            sidebarOpen ? 'w-64' : 'w-21',
            'sm:relative sm:translate-x-0',
            sidebarOpen ? 'translate-x-0' : '-translate-x-full',
            'fixed sm:static h-full z-40'
        ]">
            <!-- Logo -->
            <div class="flex items-center gap-3 px-4 py-5 border-b border-surface-800">
                <div class="w-16 h-10 rounded-xl flex items-center justify-center shrink-0">
                    <img src="/assets/images/InTouchConnect.webp" alt="InTouch Connect"
                        class="w-16 h-16 rounded-full object-cover absolute border-2 border-white" />
                </div>
                <span v-if="sidebarOpen" class="font-semibold text-md tracking-wide whitespace-nowrap">
                    InTouch Connect
                </span>
            </div>

            <!-- Nav -->
            <nav class="flex-1 py-4 space-y-1 px-2 overflow-y-auto scrollbar-thin">
                <NavItem :href="route('dashboard')" :icon="HomeIcon" label="Dashboard" :open="sidebarOpen" />
                <NavItem :href="route('customers.index')" :icon="UsersIcon" label="Customers" :open="sidebarOpen" />
                <!-- Templates — admin manages, executive sends -->
                <NavItem :href="route('templates.index')" :icon="TemplateIcon" label="Templates" :open="sidebarOpen" />
                <!-- Admin section -->
                <template v-if="isAdmin">
                    <div class="pt-2 pb-1 px-2">
                        <span v-if="sidebarOpen"
                            class="text-xs uppercase tracking-widest text-surface-500 font-medium">Admin</span>
                        <div v-else class="border-t border-surface-700 my-1" />
                    </div>
                    <NavItem :href="route('admin.users')" :icon="ShieldIcon" label="Users" :open="sidebarOpen" />
                    <NavItem :href="route('admin.audit-logs')" :icon="ClipboardIcon" label="Audit Logs"
                        :open="sidebarOpen" />
                    <NavItem
                        v-if="$page.props.auth.user.roles?.includes('admin') || $page.props.auth.user.roles?.includes('auditor')"
                        :href="route('analytics.index')" :icon="ChartIcon" label="Analytics" :open="sidebarOpen" />
                </template>
                <!-- 🔹 Sign out option in sidebar -->
                <Link :href="route('logout')" method="post" as="button"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-surface-400 hover:text-white hover:bg-surface-800 transition-colors w-full">
                    <SignOutIcon class="w-4 h-4 ml-1" />
                    <span v-if="sidebarOpen">Sign out</span>
                </Link>
            </nav>

            <!-- WhatsApp status pill -->
            <div class="px-3 py-3 border-t border-surface-800">
                <div :class="['flex items-center gap-2 rounded-xl px-3 py-2', statusBg]">
                    <span :class="statusDot" />
                    <span v-if="sidebarOpen" class="text-xs font-medium whitespace-nowrap">{{ statusLabel }}</span>
                </div>
            </div>

            <!-- User info + collapse toggle -->
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

        <!-- Overlay for mobile -->
        <div v-if="sidebarOpen" class="fixed inset-0 bg-black/40 sm:hidden" @click="sidebarOpen = false"></div>

        <!-- Main -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <!-- Top bar -->
            <header class="h-14 bg-white border-b border-surface-100 flex items-center px-4 gap-4 shrink-0">
                <!-- Mobile toggle -->
                <button @click="sidebarOpen = !sidebarOpen" class="sm:hidden text-surface-500">
                    <ChevronRightIcon v-if="!sidebarOpen" class="w-6 h-6" />
                    <ChevronLeftIcon v-else class="w-6 h-6" />
                </button>

                <div class="flex-1">
                    <slot name="header">
                        <h1 class="text-base font-semibold text-surface-900">{{ title }}</h1>
                    </slot>
                </div>
                <div class="flex items-center gap-2">
                    <slot name="actions" />
                    <NotificationBell />
                    <Link :href="route('logout')" method="post" as="button"
                        class="text-xs text-surface-400 hover:text-surface-700 transition-colors px-2 py-1 rounded-lg hover:bg-surface-100">
                        Sign out
                    </Link>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 overflow-y-auto scrollbar-thin p-4 sm:p-6">
                <slot />
            </main>
        </div>

        <ToastStack />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useWhatsAppStore } from '@/Stores/whatsapp';
import { useChannel } from '@/Composables/useEcho';
import { useNotificationStore } from '@/Stores/notifications';
import { useToast } from '@/Composables/useToast';
import NavItem from '@/Components/Layout/NavItem.vue';
import NotificationBell from '@/Components/UI/NotificationBell.vue';
import ToastStack from '@/Components/UI/ToastStack.vue';
import HomeIcon from '@/Components/Icons/HomeIcon.vue';
import UsersIcon from '@/Components/Icons/UsersIcon.vue';
import ShieldIcon from '@/Components/Icons/ShieldIcon.vue';
import ClipboardIcon from '@/Components/Icons/ClipboardIcon.vue';
import ChevronLeftIcon from '@/Components/Icons/ChevronLeftIcon.vue';
import ChevronRightIcon from '@/Components/Icons/ChevronRightIcon.vue';
import TemplateIcon from '@/Components/Icons/TemplateIcon.vue';
import ChartIcon from '@/Components/Icons/ChartIcon.vue';
import SignOutIcon from '@/Components/Icons/SignOutIcon.vue';

defineProps({ title: String });

const sidebarOpen = ref(false); // default false

onMounted(() => {
    // Tailwind's "sm" breakpoint is 640px
    if (window.innerWidth >= 640) {
        sidebarOpen.value = true;   // open on desktop
    } else {
        sidebarOpen.value = false;  // closed on mobile
    }
});

const page = usePage();
const wa = useWhatsAppStore();
const notifStore = useNotificationStore();
const { info } = useToast();

const userRoles = computed(() => page.props.auth.user.roles ?? []);
const userId = computed(() => page.props.auth.user.id);

const isSuperAdmin = computed(() => userRoles.value.includes('super_admin'));
const isAdmin = computed(() => userRoles.value.includes('admin'));

if (isSuperAdmin.value) {
    router.visit(route('dashboard'));
}

onMounted(() => {
    wa.fetchStatus();
    setInterval(wa.fetchStatus, 30_000);
});

if (isAdmin.value || userRoles.value.includes('auditor')) {
    useChannel('admin-notifications', {
        'new.message': (data) => {
            notifStore.add(data);
            if (data.is_unassigned) info(`📲 New message from unassigned: ${data.customer_name}`);
        },
    });
}

if (userRoles.value.includes('executive')) {
    useChannel(`executive-notifications.${userId.value}`, {
        'new.message': (data) => {
            notifStore.add(data);
            info(`💬 New message from ${data.customer_name}`);
        },
    });
}

const waSessionId = page.props.whatsapp_session_id ?? 'default';
useChannel(`whatsapp-status.${waSessionId}`, {
    'status.changed': (data) => wa.handleStatusEvent(data),
});

const statusBg = computed(() => ({
    connected: 'bg-brand-500/10 text-brand-400',
    qr_ready: 'bg-amber-500/10 text-amber-400',
    disconnected: 'bg-surface-700 text-surface-400',
    unreachable: 'bg-red-500/10 text-red-400',
}[wa.status] ?? 'bg-surface-700 text-surface-400'));

const statusDot = computed(() => ({
    connected: 'dot-connected',
    qr_ready: 'dot-qr',
    disconnected: 'dot-disconnected',
    unreachable: 'dot-disconnected',
}[wa.status] ?? 'dot-disconnected'));

const statusLabel = computed(() => ({
    connected: `WA: ${wa.phone ?? 'Connected'}`,
    qr_ready: 'Scan QR Code',
    disconnected: 'WA: Disconnected',
    unreachable: 'Gateway Offline',
}[wa.status] ?? 'Unknown'));

const roleLabel = computed(() => {
    const r = userRoles.value[0] ?? '';
    return r ? r.charAt(0).toUpperCase() + r.slice(1) : '';
});
</script>
