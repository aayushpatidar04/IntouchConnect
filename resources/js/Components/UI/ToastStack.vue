<template>
    <Teleport to="body">
        <div class="fixed bottom-6 right-6 z-50 flex flex-col gap-2 pointer-events-none">
            <TransitionGroup name="toast">
                <div v-for="toast in toasts" :key="toast.id" :class="[
                    'pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-2xl shadow-xl text-sm font-medium max-w-xs animate-slide-up',
                    toast.type === 'success' ? 'bg-brand-500 text-white' :
                        toast.type === 'error' ? 'bg-red-500 text-white' :
                            'bg-surface-800 text-white'
                ]">
                    <span v-if="toast.type === 'success'">✓</span>
                    <span v-else-if="toast.type === 'error'">✕</span>
                    <span v-else>ℹ</span>
                    {{ toast.message }}
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>

<script setup>
import { useToast } from '@/Composables/useToast';
const { toasts } = useToast();
</script>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all .2s ease;
}

.toast-enter-from {
    opacity: 0;
    transform: translateY(8px);
}

.toast-leave-to {
    opacity: 0;
    transform: translateX(100%);
}
</style>