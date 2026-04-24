<template>
    <div
        class="min-h-screen flex items-center justify-center bg-gradient-to-br from-surface-900 via-surface-800 to-surface-900 p-4">
        <!-- Background decoration -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute -top-40 -right-40 w-96 h-96 rounded-full bg-brand-500/10 blur-3xl" />
            <div class="absolute -bottom-40 -left-40 w-96 h-96 rounded-full bg-brand-500/5 blur-3xl" />
        </div>

        <div class="relative w-full max-w-sm animate-slide-up">
            <!-- Logo card -->
            <div class="text-center mb-8">
                <div
                    class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-brand-500 shadow-lg shadow-brand-500/40 mb-4">
                    <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                        <path
                            d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" />
                        <path
                            d="M12 0C5.373 0 0 5.373 0 12c0 2.126.557 4.123 1.527 5.855L0 24l6.335-1.51A11.94 11.94 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.81 9.81 0 01-5.002-1.37l-.358-.214-3.724.978.992-3.63-.236-.374A9.818 9.818 0 012.182 12C2.182 6.57 6.57 2.182 12 2.182S21.818 6.57 21.818 12 17.43 21.818 12 21.818z" />
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-white tracking-tight">WhatsApp CRM</h1>
                <p class="text-sm text-surface-400 mt-1">Sign in to your workspace</p>
            </div>

            <!-- Login form -->
            <div class="bg-white/5 backdrop-blur-sm border border-white/10 rounded-2xl p-6 shadow-2xl">
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-surface-300 mb-1.5 text-white">Email</label>
                        <input v-model="form.email" type="email" autocomplete="email" required :class="['w-full rounded-xl bg-white/10 border text-white placeholder-surface-500 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 transition-all',
                            errors.email ? 'border-red-400' : 'border-white/10']" placeholder="you@company.com" />
                        <p v-if="errors.email" class="text-xs text-red-400 mt-1">{{ errors.email }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-surface-300 mb-1.5 text-white">Password</label>
                        <input v-model="form.password" type="password" autocomplete="current-password" required :class="['w-full rounded-xl bg-white/10 border text-white placeholder-surface-500 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 transition-all',
                            errors.password ? 'border-red-400' : 'border-white/10']" placeholder="••••••••" />
                        <p v-if="errors.password" class="text-xs text-red-400 mt-1">{{ errors.password }}</p>
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.remember" type="checkbox"
                                class="rounded border-white/20 bg-white/10 text-brand-500 focus:ring-brand-400" />
                            <span class="text-xs text-surface-400 text-white">Remember me</span>
                        </label>
                    </div>

                    <button type="submit" :disabled="form.processing"
                        class="w-full bg-brand-500 hover:bg-brand-600 disabled:opacity-60 text-white font-semibold py-2.5 rounded-xl transition-all text-sm shadow-lg shadow-brand-500/30 active:scale-[0.98]">
                        {{ form.processing ? 'Signing in…' : 'Sign In' }}
                    </button>
                </form>
            </div>

            <p class="text-center text-xs text-surface-600 mt-6">WhatsApp CRM v1.0 · Powered by Laravel + Vue 3</p>
        </div>
    </div>
</template>

<script setup>
import { useForm, usePage } from '@inertiajs/vue3';

defineProps({ canResetPassword: Boolean, status: String });

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

// Grab validation errors from Inertia page props
const { errors } = usePage().props

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>