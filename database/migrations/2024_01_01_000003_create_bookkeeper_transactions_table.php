<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookkeeper_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')
                ->constrained('bookkeeper_journal_entries')
                ->cascadeOnDelete();
            $table->foreignId('account_id')
                ->constrained('bookkeeper_accounts')
                ->restrictOnDelete();
            $table->enum('type', ['debit', 'credit']);
            $table->decimal('amount', 20, 4);
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'type']);
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_transactions');
    }
};
