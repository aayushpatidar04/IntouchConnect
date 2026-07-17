<?php

namespace App\Console\Commands;

use App\Models\WhatsappSession;
use App\Services\GatewayService;
use Illuminate\Console\Command;

class SyncWhatsAppStatus extends Command
{
    protected $signature = 'whatsapp:sync-status';
    protected $description = 'Poll the WhatsApp gateway and update session status in the database.';

    public function handle(GatewayService $gateway): int
    {
        $sessions = $gateway->getStatus();

        if (empty($sessions)) {
            $this->warn('Gateway returned no session data.');
            return Command::FAILURE;
        }

        foreach ($sessions as $sessionId => $data) {
            if (!is_array($data)) {
                continue;
            }

            WhatsappSession::upsertForSession($sessionId, [
                'status' => $data['status'] ?? 'disconnected',
                'phone' => $data['phone'] ?? null,
                'qr_code' => $data['qr'] ?? null,
                'connected_at' =>
                    ($data['status'] ?? null) === 'connected'
                    ? now()
                    : null,
                'disconnected_at' =>
                    ($data['status'] ?? null) === 'disconnected'
                    ? now()
                    : null,
            ]);

            $this->line(
                "{$sessionId}: " .
                ($data['status'] ?? 'unknown')
            );
        }

        return Command::SUCCESS;
    }
}