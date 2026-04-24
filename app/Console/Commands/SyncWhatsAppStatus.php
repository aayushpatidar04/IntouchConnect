<?php

namespace App\Console\Commands;

use App\Models\WhatsappSession;
use App\Services\GatewayService;
use Illuminate\Console\Command;

class SyncWhatsAppStatus extends Command
{
    protected $signature   = 'whatsapp:sync-status';
    protected $description = 'Poll the WhatsApp gateway and update session status in the database.';

    public function handle(GatewayService $gateway): int
    {
        $data = $gateway->getStatus();

        WhatsappSession::create([
            'status'       => $data['status'] ?? 'disconnected',
            'phone'        => $data['phone']   ?? null,
            'connected_at' => ($data['status'] === 'connected') ? now() : null,
        ]);

        $this->info("WhatsApp status: {$data['status']}");

        return Command::SUCCESS;
    }
}