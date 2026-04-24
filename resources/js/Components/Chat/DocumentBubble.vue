<template>
    <a :href="route('documents.download', document.id)"
        class="flex items-center gap-3 bg-surface-50 border border-surface-200 rounded-xl px-3 py-2.5 hover:bg-surface-100 transition-colors group max-w-xs">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" :class="iconBg">
            <span class="text-lg">{{ icon }}</span>
        </div>
        <div class="min-w-0">
            <p class="text-xs font-medium text-surface-800 truncate">{{ document.original_filename }}</p>
            <p class="text-[10px] text-surface-400">{{ document.formatted_size }}</p>
        </div>
        <svg class="w-4 h-4 text-surface-300 group-hover:text-brand-500 transition-colors ml-auto shrink-0" fill="none"
            stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
        </svg>
    </a>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({ document: { type: Object, required: true } });

const icon = computed(() => {
    if (props.document.mime_type?.includes('pdf')) return '📄';
    if (props.document.mime_type?.includes('image')) return '🖼️';
    if (props.document.mime_type?.includes('video')) return '🎥';
    if (props.document.mime_type?.includes('audio')) return '🎵';
    return '📎';
});
const iconBg = computed(() => {
    if (props.document.mime_type?.includes('pdf')) return 'bg-red-50';
    if (props.document.mime_type?.includes('image')) return 'bg-blue-50';
    return 'bg-surface-100';
});
</script>