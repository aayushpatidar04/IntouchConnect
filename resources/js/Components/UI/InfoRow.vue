<template>
    <div v-if="value" class="flex items-center justify-between">
        <span class="text-surface-400 flex items-center gap-1.5"><span>{{ icon }}</span>{{ label }}</span>
        <span class="text-surface-700 font-medium truncate ml-2 max-w-[160px]">{{ value }}</span>
    </div>
</template>
<script setup>
defineProps({ icon: String, label: String, value: [String, Number] });
</script>