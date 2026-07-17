<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('bitrix_lead_id')
                ->nullable()
                ->after('id');

            $table->unsignedBigInteger('bitrix_assigned_by_id')
                ->nullable()
                ->after('bitrix_lead_id');

            $table->timestamp('bitrix_created_at')
                ->nullable()
                ->after('bitrix_assigned_by_id');

            $table->timestamp('bitrix_synced_at')
                ->nullable()
                ->after('bitrix_created_at');

            $table->unique(
                'bitrix_lead_id',
                'customers_bitrix_lead_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(
                'customers_bitrix_lead_id_unique'
            );

            $table->dropColumn([
                'bitrix_lead_id',
                'bitrix_assigned_by_id',
                'bitrix_created_at',
                'bitrix_synced_at',
            ]);
        });
    }
};
