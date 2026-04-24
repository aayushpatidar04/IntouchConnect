<script setup>
import { useWhatsAppStore } from '@/Stores/whatsapp';
import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
const showQrPopup = ref(false);

const store = useWhatsAppStore();
const page = usePage();
const isAdmin = page.props.auth.user.roles.includes('admin');

defineProps({
  showAlways: { type: Boolean, default: false },
  showLogout: { type: Boolean, default: false },
});
</script>

<template>
  <div v-if="(store.status === 'qr_ready' && isAdmin) || showAlways" class="w-full">
    <!-- QR scan prompt -->
    <div v-if="store.status === 'qr_ready' && isAdmin"
      class="flex items-center gap-2 bg-red-50 border border-red-100 rounded-xl px-3 py-2">
      <span class="dot-qr" />
      <button class="text-xs font-medium text-yellow-600 capitalize" @click="showQrPopup = true">{{ store.status.replace('_', ' ') }}</button >
      <button @click="store.fetchStatus()" class="ml-auto text-xs text-surface-500 hover:text-surface-700">
        Refresh
      </button>
    </div>

    <!-- Connected -->
    <div v-else-if="store.status === 'connected'"
      class="flex items-center gap-2 bg-brand-50 border border-brand-100 rounded-xl px-3 py-2">
      <span class="dot-connected" />
      <span class="text-xs font-medium text-brand-700">Connected: +{{ store.phone }}</span>
      <button v-if="showLogout && isAdmin" @click="store.logout()"
        class="ml-auto text-xs text-red-500 hover:text-red-700 transition-colors">
        Disconnect
      </button>
    </div>

    <!-- Disconnected/offline -->
    <div v-else class="flex items-center gap-2 bg-red-50 border border-red-100 rounded-xl px-3 py-2">
      <span class="dot-disconnected" />
      <span class="text-xs font-medium text-red-600 capitalize">{{ store.status.replace('_', ' ') }}</span>
      <button @click="store.fetchStatus()" class="ml-auto text-xs text-surface-500 hover:text-surface-700">
        Refresh
      </button>
    </div>

    <!-- QR Popup -->
    <div v-if="showQrPopup" class="fixed inset-0 flex items-center justify-center bg-black/50 z-50">
      <div class="bg-white rounded-2xl p-6 shadow-xl max-w-sm w-full text-center relative">
        <button @click="showQrPopup = false" class="absolute top-2 right-2 text-surface-400 hover:text-surface-600">
          ✕
        </button>
        <p class="text-sm font-semibold text-amber-800 mb-3">Scan QR Code to connect WhatsApp</p>
        <div class="flex justify-center">
          <img v-if="store.qrCode" :src="store.qrCode" alt="WhatsApp QR Code"
            class="w-64 h-64 rounded-xl border border-amber-100 shadow-md" />
        </div>
        <p class="text-xs text-amber-600 mt-3">Open WhatsApp → Linked Devices → Link a Device</p>
      </div>
    </div>
  </div>
</template>
