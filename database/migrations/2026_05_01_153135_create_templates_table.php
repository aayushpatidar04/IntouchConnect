<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── templates ─────────────────────────────────────────────────────────
        // Stores message templates created by company admins.
        // Body supports variables like {{customer_name}}, {{custom_var}}
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');                      // internal label e.g. "Welcome Message"
            $table->text('body');                        // template body with {{variables}}
            $table->json('variables')->nullable();       // extracted variable names e.g. ["customer_name","amount"]
            $table->string('category')->default('general'); // general | followup | promo | reminder
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
        });

        // ── template_user (pivot) ─────────────────────────────────────────────
        // Controls which executives can USE a template.
        // If no rows exist for a template → all executives in the company can use it.
        Schema::create('template_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['template_id', 'user_id']);
        });

        // ── template_broadcasts ───────────────────────────────────────────────
        // Each time an executive sends a template to multiple customers,
        // one broadcast record is created. Individual queued jobs reference this.
        Schema::create('template_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->constrained('users')->cascadeOnDelete();
            $table->json('variable_values')->nullable(); // {"customer_name":"John","amount":"500"}
            $table->integer('total_recipients');
            $table->integer('sent_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('pending_count')->default(0);
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // ── template_broadcast_recipients ─────────────────────────────────────
        // One row per customer per broadcast — tracks individual send status.
        Schema::create('template_broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('template_broadcasts')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'queued', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->string('failure_reason')->nullable();
            $table->string('resolved_body', 4096)->nullable(); // body after variable substitution
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_broadcast_recipients');
        Schema::dropIfExists('template_broadcasts');
        Schema::dropIfExists('template_user');
        Schema::dropIfExists('templates');
    }
};