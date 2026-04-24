<template>
  <form @submit.prevent="submit" class="space-y-4">
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-xs font-medium text-surface-700 mb-1">Name *</label>
        <input v-model="form.name" type="text" required
          class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
        <p v-if="errors.name" class="text-xs text-red-500 mt-1">{{ errors.name }}</p>
      </div>
      <div>
        <label class="block text-xs font-medium text-surface-700 mb-1">Phone (WhatsApp) *</label>
        <input v-model="form.phone" type="text" required placeholder="919876543210"
          class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-400" />
        <p class="text-[10px] text-surface-400 mt-0.5">E.164 without +</p>
        <p v-if="errors.phone" class="text-xs text-red-500 mt-1">{{ errors.phone }}</p>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-xs font-medium text-surface-700 mb-1">Email</label>
        <input v-model="form.email" type="email"
          class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
      </div>
      <div>
        <label class="block text-xs font-medium text-surface-700 mb-1">Company</label>
        <input v-model="form.company" type="text"
          class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400" />
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-xs font-medium text-surface-700 mb-1">Status</label>
        <select v-model="form.status"
          class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="blocked">Blocked</option>
        </select>
      </div>
      <div v-if="executives.length > 0">
        <label class="block text-xs font-medium text-surface-700 mb-1">Assign To</label>
        <select v-model="form.assigned_to"
          class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
          <option value="">Unassigned</option>
          <option v-for="e in executives" :key="e.id" :value="Number(e.id)">{{ e.name }}</option>
        </select>
      </div>
    </div>

    <div>
      <label class="block text-xs font-medium text-surface-700 mb-1">Notes</label>
      <textarea v-model="form.notes" rows="2"
        class="w-full rounded-xl border border-surface-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 resize-none" />
    </div>

    <div class="flex justify-end gap-2 pt-2">
      <button type="button" @click="$emit('cancel')"
        class="px-4 py-2 text-sm text-surface-600 hover:text-surface-800 transition-colors">Cancel</button>
      <button type="submit" :disabled="submitting"
        class="px-5 py-2 bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-50">
        {{ submitting ? 'Saving…' : (customer ? 'Update' : 'Create') }}
      </button>
    </div>
  </form>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    customer:   { type: Object, default: null },
    executives: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved', 'cancel']);

const { success, error } = useToast();
const submitting = ref(false);
const errors     = ref({});

// Build form state from current customer prop
function buildForm(c) {
    return {
        name:        c?.name        ?? '',
        phone:       c?.phone       ?? '',
        email:       c?.email       ?? '',
        company:     c?.company     ?? '',
        status:      c?.status      ?? 'active',
        // Coerce to Number so <select :value="e.id"> (Number) matches
        assigned_to: c?.assigned_to?.id ? Number(c.assigned_to.id) : '',
        notes:       c?.notes       ?? '',
    };
}

const form = reactive(buildForm(props.customer));

// Re-populate whenever the customer prop changes (modal reopened with different record)
watch(
    () => props.customer,
    (newVal) => {
        const fresh = buildForm(newVal);
        Object.keys(fresh).forEach(k => { form[k] = fresh[k]; });
        errors.value = {};
    },
    { immediate: false }
);

function submit() {
    submitting.value = true;
    errors.value = {};

    const isEdit = !!props.customer;
    const url    = isEdit
        ? route('customers.update', props.customer.id)
        : route('customers.store');

    router[isEdit ? 'patch' : 'post'](url, form, {
        onSuccess: () => { success(isEdit ? 'Customer updated.' : 'Customer created.'); emit('saved'); },
        onError:   (e) => { errors.value = e; },
        onFinish:  ()  => { submitting.value = false; },
    });
}
</script>