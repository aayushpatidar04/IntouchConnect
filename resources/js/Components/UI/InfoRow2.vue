<template>
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs text-surface-400 shrink-0">{{ label }}</span>
        <span :class="[
            'text-xs text-right text-surface-700 truncate',
            mono ? 'font-mono' : ''
        ]">
            {{ value ?? '—' }}
        </span>
    </div>
</template>

<script setup>
defineProps({
    label: String,
    value: String,
    mono: Boolean,
})
</script>

