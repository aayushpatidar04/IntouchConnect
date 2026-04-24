<template>
    <span class="text-[10px]" :title="status">
        <span v-if="status === 'pending'" class="text-surface-300">⏳</span>
        <span v-else-if="status === 'queued'" class="text-surface-300">🕐</span>
        <span v-else-if="status === 'sent'" class="text-surface-400">✓</span>
        <span v-else-if="status === 'delivered'" class="text-surface-400">✓✓</span>
        <span v-else-if="status === 'read'" class="text-brand-400">✓✓</span>
        <span v-else-if="status === 'failed'" class="text-red-400">✕</span>
    </span>
</template>
<script setup>
defineProps({ status: String });
</script>