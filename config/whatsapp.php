<?php

return [
    'gateway_url'    => env('WHATSAPP_GATEWAY_URL', 'https://whatsapp.intouchsoftwaresolution.in'),
    // 'gateway_url'    => env('WHATSAPP_GATEWAY_URL', 'https://mustang-cuddly-terminally.ngrok-free.app'),
    // 'gateway_url'    => env('WHATSAPP_GATEWAY_URL', 'http://127.0.0.1:3000'),
    'gateway_secret' => env('WHATSAPP_GATEWAY_SECRET', 'aayush-patidar'),

    // The session identifier sent to the multi-session gateway.
    // Single company: 'default' — Multi-tenant: company slug / ID.
    'session_id'     => env('WHATSAPP_SESSION_ID', 'default'),
];