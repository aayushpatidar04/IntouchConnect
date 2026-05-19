<?php

// config/ziggy.php
// Tightenco Ziggy exposes named Laravel routes to JavaScript via the route() helper.

return [
    // Only expose routes that start with these groups (security best practice)
    'only' => [
        'dashboard',
        'analytics.*',
        'customers.*',
        'templates.*',
        'messages.*',
        'documents.*',
        'gateway.*',
        'superadmin.*',
        'admin.*',
        'login',
        'logout',
    ],
];