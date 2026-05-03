<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20)->default('info'); // debug, info, warning, error
            $table->string('category', 50)->default('general'); // api, invoice, certificate, validation
            $table->string('environment', 20)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('zatca_invoices')->nullOnDelete();
            $table->string('action', 100); // generate_csr, request_csid, submit_invoice, etc.
            $table->text('message');
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->string('status_code')->nullable();
            $table->text('error_message')->nullable();
            $table->float('duration_ms')->nullable(); // request duration
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            
            $table->index(['level', 'category']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_logs');
    }
};
