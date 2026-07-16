import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';

export const useWhatsAppStore = defineStore('whatsapp', () => {
    const { success, error } = useToast();
    const status   = ref('disconnected');
    const qrCode   = ref(null);
    const phone    = ref(null);
    const isReady  = ref(false);
    const loading  = ref(false);
    const queueStats = ref({ waiting: 0, active: 0, completed: 0, failed: 0 });

    async function fetchStatus() {
        try {
            const { data } = await axios.get(route('gateway.status'));
            status.value  = data.status ?? 'disconnected';
            qrCode.value  = data.qr ?? null;
            phone.value   = data.phone ?? null;
            isReady.value = data.is_ready ?? false;
        } catch {
            status.value  = 'unreachable';
            isReady.value = false;
        }
    }

    async function fetchQueueStats() {
        try {
            const { data } = await axios.get(route('gateway.queue-stats'));
            queueStats.value = data;
        } catch {}
    }

    async function createSession() {
        loading.value = true;
        try {
            const { data } = await axios.post(route('gateway.session.create'));
         
            if (data.success) {
                if (data.alreadyExists) {
                    error('Session already exists. Check status below.');
                } else {
                    success('WhatsApp session created! Waiting for QR code...');
                }
            }
        
            // Fetch updated status after a short delay
            await new Promise(r => setTimeout(r, 1500));
            await fetchStatus();
        
        } catch (err) {
            const errorMsg = err.response?.data?.error 
                || err.response?.data?.message 
                || err.message 
                || 'Failed to create WhatsApp session';
        
            error(errorMsg);
            console.error('createSession failed:', err);
        } finally {
            loading.value = false;
        }
    }

    async function logout() {
        loading.value = true;
        try {
            await axios.post(route('gateway.logout'));
            status.value  = 'disconnected';
            isReady.value = false;
            qrCode.value  = null;
            phone.value   = null;
        } finally {
            loading.value = false;
        }
    }

    // Handle real-time push from Echo
    function handleStatusEvent(payload) {
        status.value  = payload.status;
        qrCode.value  = payload.qr ?? null;
        isReady.value = payload.status === 'connected';
        if (payload.status === 'connected') phone.value = payload.phone ?? null;
    }

    return { status, qrCode, phone, isReady, loading, queueStats, fetchStatus, fetchQueueStats, createSession, logout, handleStatusEvent };
});
