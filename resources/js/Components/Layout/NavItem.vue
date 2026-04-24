<template>
    <Link :href="href" :class="[
        'flex items-center gap-3 px-3 py-2 rounded-xl text-sm font-medium transition-all duration-150',
        isActive
            ? 'bg-brand-500 text-white shadow-sm shadow-brand-500/40'
            : 'text-surface-300 hover:bg-surface-800 hover:text-white'
    ]">
        <component :is="icon" class="w-5 h-5 shrink-0" />
        <span v-if="open" class="whitespace-nowrap">{{ label }}</span>
    </Link>
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    href: { type: String, required: true },
    icon: { type: Object, required: true },
    label: { type: String, required: true },
    open: { type: Boolean, default: true },
});

const page = usePage();
const isActive = computed(() => page.url.startsWith(new URL(props.href, window.location.origin).pathname));
</script>