<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bookkeeper.connection');
    }

    public function up(): void
    {
        Schema::create('bookkeeper_fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date')->unique();
            $table->date('end_date');
            $table->string('status')->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closing_entry_id')->nullable();
            $table->timestamps();
        });

        Schema::create('bookkeeper_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('bookkeeper_fiscal_years')->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date')->unique();
            $table->date('end_date');
            $table->string('status')->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_periods');
        Schema::dropIfExists('bookkeeper_fiscal_years');
    }
};
