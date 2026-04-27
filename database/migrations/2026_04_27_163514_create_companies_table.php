<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // used in URLs
            $table->string('gateway_url')->nullable();   // each company's gateway URL
            $table->string('gateway_secret')->nullable(); // each company's gateway secret
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Add company_id to all tenant-scoped tables
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index('company_id');
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_sessions', fn($t) => $t->dropColumn('company_id'));
        Schema::table('messages', fn($t) => $t->dropColumn('company_id'));
        Schema::table('customers', fn($t) => $t->dropColumn('company_id'));
        Schema::table('users', fn($t) => $t->dropColumn('company_id'));
        Schema::dropIfExists('companies');
    }
};