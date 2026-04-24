<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete(); // null = received from WA
            $table->string('original_filename');
            $table->string('stored_filename');      // encrypted filename on disk
            $table->string('disk')->default('local');
            $table->string('path');                 // relative path on disk
            $table->string('mime_type');
            $table->unsignedBigInteger('size');     // bytes
            $table->enum('source', ['whatsapp', 'manual_upload'])->default('whatsapp');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->string('encryption_key_id')->nullable(); // reference to key store
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};