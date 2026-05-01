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
                    class="inline-flex items-center justify-center w-14 h-14 rounded-2xl shadow-lg shadow-brand-500/40 mb-4">
                    <img src="/assets/images/InTouchConnect.webp" alt="InTouch Connect" class="w-20 h-20 rounded-full object-cover absolute border-2 border-white" />
                </div>
                <h1 class="text-2xl font-bold text-white tracking-tight">InTouch Connect</h1>
                <p class="text-sm text-surface-400 mt-1 text-white">Sign in to your workspace</p>
            </div>

            <!-- Login form -->
            <div class="bg-white/5 backdrop-blur-sm border border-white/10 rounded-2xl p-6 shadow-2xl">

                <!-- Global flash error -->
                <div v-if="page.props.flash?.error" class="mb-4 text-xs text-red-400">
                    {{ page.props.flash.error }}
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-surface-300 mb-1.5 text-white">Email</label>
                        <input v-model="form.email" type="email" autocomplete="email" required :class="['w-full rounded-xl bg-white/10 border text-white placeholder-surface-500 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 transition-all',
                            page.props.errors.email ? 'border-red-400' : 'border-white/10']" placeholder="you@company.com" />
                        <p v-if="page.props.errors?.email" class="text-xs text-red-400 mt-1">
                            {{ page.props.errors.email }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-surface-300 mb-1.5 text-white">Password</label>
                        <input v-model="form.password" type="password" autocomplete="current-password" required :class="['w-full rounded-xl bg-white/10 border text-white placeholder-surface-500 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 transition-all',
                            page.props.errors.password ? 'border-red-400' : 'border-white/10']" placeholder="••••••••" />
                        <p v-if="page.props.errors?.password" class="text-xs text-red-400 mt-1">
                            {{ page.props.errors.password }}
                        </p>
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

const page = usePage()

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>