<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Parent company for hierarchy (self-referencing)
            $table->foreignId('parent_company_id')
                ->nullable()
                ->after('slug')
                ->constrained('companies')
                ->nullOnDelete();

            // Link back to external department ID for re-sync
            $table->string('external_department_id')
                ->nullable()
                ->after('parent_company_id');

            $table->index('external_department_id');
            $table->index('parent_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // IMPORTANT: Must drop FOREIGN KEY before dropping INDEX in MySQL
            // Laravel's dropConstrainedForeignId handles both FK and index automatically
            $table->dropConstrainedForeignId('parent_company_id');
            
            $table->dropColumn('external_department_id');
        });
    }
};
