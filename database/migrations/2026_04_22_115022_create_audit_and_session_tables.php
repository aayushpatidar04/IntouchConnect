<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');           // e.g. 'message.sent', 'document.viewed'
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'action']);
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->enum('status', ['disconnected', 'qr_ready', 'connected', 'failed'])->default('disconnected');
            $table->text('qr_code')->nullable();     // base64 QR image
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->string('disconnect_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('notify_new_message')->default(true);
            $table->boolean('notify_new_document')->default(true);
            $table->boolean('notify_session_drop')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('whatsapp_sessions');
        Schema::dropIfExists('audit_logs');
    }
};