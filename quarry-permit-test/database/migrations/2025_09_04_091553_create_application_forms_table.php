<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Minimal legacy table to satisfy older references; safe to keep even if unused.
        if (!Schema::hasTable('application_forms')) {
            Schema::create('application_forms', function (Blueprint $table) {
                $table->id();
                if (Schema::hasTable('users')) {
                    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                } else {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
                $table->string('applicant_name');
                $table->text('applicant_address');
                $table->string('applied_area')->nullable();
                $table->string('resources')->nullable();
                $table->string('quantity')->nullable();
                $table->string('province_city')->nullable();
                $table->string('north')->nullable();
                $table->string('east')->nullable();
                $table->string('south')->nullable();
                $table->string('west')->nullable();
                $table->string('approx_area')->nullable();
                $table->string('fee')->nullable();
                $table->string('receipt_no')->nullable();
                $table->date('date_paid')->nullable();
                $table->string('tin')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_forms');
    }
};
