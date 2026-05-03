<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->index();
            $table->uuid('uuid')->unique();
            $table->string('type', 20)->default('simplified'); // standard, simplified, credit_note, debit_note
            $table->string('status', 20)->default('draft'); // draft, generated, signed, submitted, cleared, reported, failed
            $table->string('environment', 20)->default('sandbox');
            
            // Seller info
            $table->string('seller_name');
            $table->string('seller_vat_number', 15);
            
            // Buyer info
            $table->string('buyer_name')->nullable();
            $table->string('buyer_vat_number', 15)->nullable();
            
            // Amounts
            $table->decimal('sub_total', 15, 2);
            $table->decimal('tax_total', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            
            // Invoice data
            $table->timestamp('issue_date');
            $table->timestamp('delivery_date')->nullable();
            $table->json('line_items');
            $table->text('notes')->nullable();
            
            // ZATCA specific
            $table->string('invoice_hash')->nullable();
            $table->text('xml_content')->nullable();
            $table->text('signed_xml_content')->nullable();
            $table->text('qr_code')->nullable();
            $table->string('clearance_status')->nullable(); // cleared, reported, rejected
            $table->json('zatca_response')->nullable();
            $table->string('previous_invoice_hash')->nullable();
            $table->string('reference_invoice_number')->nullable();
            
            // Submission
            $table->timestamp('submitted_at')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['status', 'environment']);
            $table->index(['type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_invoices');
    }
};
