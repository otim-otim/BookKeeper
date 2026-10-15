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
        Schema::create('bookkeeper_tills', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->default('till')->comment('till, vault');
            $table->foreignId('account_id')->unique()->constrained('bookkeeper_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('bookkeeper_till_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('till_id')->constrained('bookkeeper_tills')->restrictOnDelete();
            $table->nullableMorphs('teller');
            $table->string('status')->default('open');
            $table->timestamp('opened_at');
            $table->bigInteger('opening_balance')->comment('Book balance when opened');
            $table->bigInteger('opening_count')->nullable()->comment('Cash counted when opened');
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('expected_balance')->nullable();
            $table->bigInteger('counted_balance')->nullable();
            $table->bigInteger('variance')->nullable()->comment('Counted minus expected');
            $table->foreignId('variance_entry_id')->nullable()->constrained('bookkeeper_journal_entries')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['till_id', 'status']);
        });

        Schema::create('bookkeeper_bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('bookkeeper_accounts')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->bigInteger('opening_balance');
            $table->bigInteger('closing_balance');
            $table->string('status')->default('draft');
            $table->timestamp('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('bookkeeper_bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('bookkeeper_bank_statements')->cascadeOnDelete();
            $table->date('date');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->bigInteger('amount')->comment('Positive: money in. Negative: money out.');
            $table->foreignId('journal_line_id')->nullable()->unique()->constrained('bookkeeper_journal_lines')->restrictOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_bank_statement_lines');
        Schema::dropIfExists('bookkeeper_bank_statements');
        Schema::dropIfExists('bookkeeper_till_sessions');
        Schema::dropIfExists('bookkeeper_tills');
    }
};
