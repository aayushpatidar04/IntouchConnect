<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Companies (tenants) ────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();           // e.g. "acme-corp" — used as sessionId on gateway
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ── 2. Add company_id to users ────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            // nullable so super-admin has no company
            $table->foreignId('company_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('companies')
                  ->nullOnDelete();
            $table->index('company_id');
        });

        // ── 3. Add company_id + session_id to whatsapp_sessions ──────────────
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->foreignId('company_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            // session_id matches the folder name in gateway's auth_info/
            $table->string('session_id')->nullable()->after('company_id');
            $table->index('company_id');
            $table->index('session_id');
        });

        // ── 4. Add company_id to customers ────────────────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('company_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->index('company_id');
        });

        // ── 5. Add company_id + session_id to messages ────────────────────────
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('company_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            // Track which gateway session this message came through / was sent via
            $table->string('session_id')->nullable()->after('company_id');
            $table->index('company_id');
        });

        // ── 6. Add company_id to documents ────────────────────────────────────
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('company_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
        });

        // ── 7. Add company_id to audit_logs ──────────────────────────────────
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('company_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('companies')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs',       fn($t) => $t->dropColumn('company_id'));
        Schema::table('documents',        fn($t) => $t->dropColumn('company_id'));
        Schema::table('messages',         fn($t) => $t->dropForeignId('company_id'));
        Schema::table('customers',        fn($t) => $t->dropForeignId('company_id'));
        Schema::table('whatsapp_sessions',fn($t) => $t->dropColumn(['company_id','session_id']));
        Schema::table('users',            fn($t) => $t->dropForeignId('company_id'));
        Schema::dropIfExists('companies');
    }
};