<template>
    <div class="flex h-screen bg-surface-50 overflow-hidden">
        <!-- Sidebar -->
        <aside :class="[
            'flex flex-col bg-surface-900 text-white transition-all duration-300 shrink-0',
            sidebarOpen ? 'w-64' : 'w-21'
        ]">
            <!-- Logo -->
            <div class="flex items-center gap-3 px-4 py-5 border-b border-surface-800">
                <div class="w-16 h-10 rounded-xl bg-brand-500 flex items-center justify-center shrink-0">
                    <img src="/assets/images/InTouchConnect.webp" alt="InTouch Connect" class="w-16 h-16 rounded-full object-cover absolute border-2 border-white" />
                </div>
                <div v-if="sidebarOpen" class="min-w-0">
                    <span class="font-semibold text-sm tracking-wide whitespace-nowrap">InTouch Connect</span>
                    <p class="text-xs text-amber-400 font-medium">Super Admin</p>
                </div>
            </div>

            <!-- Nav — super admin only sees platform management items -->
            <nav class="flex-1 py-4 space-y-1 px-2 overflow-y-auto scrollbar-thin">
                <NavItem :href="route('dashboard')" :icon="HomeIcon" label="Overview" :open="sidebarOpen" />

                <div class="pt-2 pb-1 px-2">
                    <span v-if="sidebarOpen"
                        class="text-xs uppercase tracking-widest text-surface-500 font-medium">Platform</span>
                    <div v-else class="border-t border-surface-700 my-1" />
                </div>

                <NavItem :href="route('superadmin.companies.index')" :icon="BuildingIcon" label="Companies"
                    :open="sidebarOpen" />
            </nav>

            <!-- Super admin badge -->
            <div class="px-3 py-3 border-t border-surface-800">
                <div class="flex items-center gap-2 rounded-xl px-3 py-2 bg-amber-500/10 text-amber-400">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <span v-if="sidebarOpen" class="text-xs font-medium whitespace-nowrap">Super Admin</span>
                </div>
            </div>

            <!-- User + collapse -->
            <div class="p-3 border-t border-surface-800 flex items-center gap-2">
                <img :src="$page.props.auth.user.avatar_url" class="w-8 h-8 rounded-full shrink-0 object-cover" />
                <div v-if="sidebarOpen" class="min-w-0 flex-1">
                    <p class="text-xs font-medium truncate">{{ $page.props.auth.user.name }}</p>
                    <p class="text-xs text-surface-400 truncate">{{ $page.props.auth.user.email }}</p>
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

        <!-- Toast -->
        <ToastStack />
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import NavItem from '@/Components/Layout/NavItem.vue';
import ToastStack from '@/Components/UI/ToastStack.vue';
import HomeIcon from '@/Components/Icons/HomeIcon.vue';
import ChevronLeftIcon from '@/Components/Icons/ChevronLeftIcon.vue';
import ChevronRightIcon from '@/Components/Icons/ChevronRightIcon.vue';

// Inline building/office icon — no new icon file needed
const BuildingIcon = {
    template: `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
    </svg>`
};

defineProps({ title: String });

const sidebarOpen = ref(true);
</script>