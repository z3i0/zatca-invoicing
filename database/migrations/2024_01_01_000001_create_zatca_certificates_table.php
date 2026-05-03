<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('environment')->default('sandbox'); // sandbox, simulation, production
            $table->string('type')->default('compliance'); // compliance, production
            $table->string('vat_number', 15)->index();
            $table->string('organization_name')->nullable();
            $table->text('csr_content')->nullable();
            $table->text('certificate_content')->nullable();
            $table->text('private_key_content')->nullable();
            $table->string('request_id')->nullable();
            $table->string('secret')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['environment', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_certificates');
    }
};
