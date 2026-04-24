<template>
    <div :class="[
        'relative bg-white rounded-2xl border p-4 flex flex-col gap-2 overflow-hidden transition-all hover:shadow-sm',
        alert ? 'border-amber-200 bg-amber-50' : 'border-surface-100'
    ]">
        <div class="flex items-center justify-between">
            <span class="text-xl">{{ icon }}</span>
            <span v-if="alert" class="w-2 h-2 rounded-full bg-amber-400 animate-pulse-dot" />
        </div>
        <div>
            <p class="text-2xl font-bold text-surface-900 tabular-nums">{{ value }}</p>
            <p class="text-xs text-surface-400 font-medium">{{ label }}</p>
        </div>
    </div>
</template>

<script setup>
defineProps({
    label: String,
    value: [Number, String],
    icon: String,
    color: String,
    alert: Boolean,
});
</script>