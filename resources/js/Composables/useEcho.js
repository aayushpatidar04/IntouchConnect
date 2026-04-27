// resources/js/Composables/useEcho.js

import { onMounted, onUnmounted } from 'vue';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;

function getEcho() {
    if (!echo) {
        window.Pusher = Pusher;
        echo = new Echo({
            broadcaster: 'pusher',
            key:         import.meta.env.VITE_PUSHER_APP_KEY,
            cluster:     import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'ap2',
            forceTLS:    true,    // Pusher always uses HTTPS/WSS
        });
    }
    return echo;
}

export function useEcho() {
    return { echo: getEcho() };
}

export function useChannel(channelName, events) {
    let channel = null;

    onMounted(() => {
        channel = getEcho().channel(channelName);
        Object.entries(events).forEach(([event, handler]) => {
            channel.listen('.' + event, handler);
        });
    });

    onUnmounted(() => {
        if (channel) {
            Object.keys(events).forEach(event => channel.stopListening('.' + event));
            getEcho().leaveChannel(channelName);
        }
    });
}