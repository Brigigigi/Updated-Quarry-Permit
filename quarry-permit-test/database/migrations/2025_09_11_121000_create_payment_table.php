<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment')) {
            Schema::create('payment', function (Blueprint $table) {
                $table->id();
                if (Schema::hasTable('permit_application')) {
                    $table->foreignId('application_id')->constrained('permit_application');
                } else {
                    $table->unsignedBigInteger('application_id');
                }
                $table->string('method');
                $table->string('reference');
                $table->decimal('amount', 14, 2)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment');
    }
};

