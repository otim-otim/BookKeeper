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
        Schema::create('bookkeeper_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });

        Schema::create('bookkeeper_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->timestamps();
        });

        Schema::create('bookkeeper_account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('bookkeeper_accounts')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('debit')->comment('Cumulative debits up to and including date');
            $table->unsignedBigInteger('credit')->comment('Cumulative credits up to and including date');
            $table->timestamps();

            $table->unique(['account_id', 'date']);
            $table->index('date');
        });

        Schema::create('bookkeeper_close_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->comment('day, month, year');
            $table->date('date');
            $table->timestamp('completed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_close_runs');
        Schema::dropIfExists('bookkeeper_account_balances');
        Schema::dropIfExists('bookkeeper_snapshots');
        Schema::dropIfExists('bookkeeper_sequences');
    }
};
