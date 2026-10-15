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
        Schema::create('bookkeeper_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique()->comment('Assigned when posted');
            $table->date('date');
            $table->string('type')->default('standard');
            $table->string('status')->default('draft');
            $table->string('memo')->nullable();
            $table->string('reference')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->nullableMorphs('source');
            $table->nullableMorphs('creator');
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('period_id')->nullable()->constrained('bookkeeper_periods')->restrictOnDelete();
            $table->foreignId('reverses_id')->nullable()->constrained('bookkeeper_journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('bookkeeper_journal_entries')->restrictOnDelete();
            $table->date('reverse_on')->nullable()->comment('Scheduled automatic reversal date (accruals)');
            $table->timestamp('posted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'date']);
            $table->index('reference');
            $table->index('branch_id');
        });

        Schema::create('bookkeeper_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('bookkeeper_journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('bookkeeper_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('debit')->default(0)->comment('Base currency, minor units');
            $table->unsignedBigInteger('credit')->default(0)->comment('Base currency, minor units');
            $table->string('memo')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->nullableMorphs('subledger');
            $table->char('currency', 3)->nullable()->comment('Transaction currency when not the base currency');
            $table->bigInteger('foreign_amount')->nullable()->comment('Amount in the transaction currency, minor units');
            $table->decimal('exchange_rate', 24, 10)->nullable();
            $table->foreignId('tax_rate_id')->nullable()->constrained('bookkeeper_tax_rates')->restrictOnDelete();
            $table->bigInteger('taxable_amount')->nullable()->comment('Tax base for tax lines, minor units');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['account_id', 'journal_entry_id']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_journal_lines');
        Schema::dropIfExists('bookkeeper_journal_entries');
    }
};
