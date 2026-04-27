// resources/js/Stores/notifications.js
// NEW FILE — manages in-app notification bell and toast alerts for inbound messages

import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useNotificationStore = defineStore('notifications', () => {
    const items     = ref([]);  // { id, message_id, customer_id, customer_name, body, type, is_unassigned, created_at, read }
    const maxItems  = 50;

    const unreadCount = computed(() => items.value.filter(n => !n.read).length);

    function add(payload) {
        // Prepend, cap at maxItems
        items.value.unshift({
            id:           `notif_${Date.now()}_${Math.random().toString(36).slice(2)}`,
            message_id:   payload.message_id,
            customer_id:  payload.customer_id,
            customer_name:payload.customer_name,
            customer_phone:payload.customer_phone,
            body:         payload.body,
            type:         payload.type,
            has_document: payload.has_document,
            is_unassigned:payload.is_unassigned,
            created_at:   payload.created_at,
            read:         false,
        });
        if (items.value.length > maxItems) items.value.splice(maxItems);
    }

    function markRead(id) {
        const n = items.value.find(n => n.id === id);
        if (n) n.read = true;
    }

    function markAllRead() {
        items.value.forEach(n => { n.read = true; });
    }

    function clear() {
        items.value = [];
    }

    return { items, unreadCount, add, markRead, markAllRead, clear };
});