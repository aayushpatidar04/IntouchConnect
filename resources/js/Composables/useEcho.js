import { onMounted, onUnmounted } from 'vue';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;

function getEcho() {
    if (!echo) {
        window.Pusher = Pusher;
        echo = new Echo({
            broadcaster: 'reverb',
            key:         import.meta.env.VITE_REVERB_APP_KEY,
            wsHost:      import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort:      import.meta.env.VITE_REVERB_PORT ?? 8080,
            wssPort:     import.meta.env.VITE_REVERB_PORT ?? 8080,
            forceTLS:    (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
            enabledTransports: ['ws', 'wss'],
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