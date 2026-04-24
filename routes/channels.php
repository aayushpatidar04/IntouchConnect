<?php

use Illuminate\Support\Facades\Broadcast;

// Public channels (no auth needed for gateway events)
Broadcast::channel('messages',         fn() => true);
Broadcast::channel('whatsapp-status',  fn() => true);

// Per-user private channel
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});