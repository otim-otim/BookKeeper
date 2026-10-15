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
        Schema::create('bookkeeper_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_vendor')->default(false);
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_number')->nullable();
            $table->text('address')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->nullableMorphs('contactable');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('bookkeeper_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type')->comment('invoice, credit_note, bill, debit_note');
            $table->string('number')->nullable();
            $table->foreignId('contact_id')->constrained('bookkeeper_contacts')->restrictOnDelete();
            $table->date('issue_date');
            $table->date('due_date');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->boolean('tax_inclusive')->default(false);
            $table->string('status')->default('draft');
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('base_total')->default(0)->comment('Total in base currency, as posted');
            $table->bigInteger('paid_total')->default(0)->comment('Payments and credits applied, document currency');
            $table->foreignId('journal_entry_id')->nullable()->constrained('bookkeeper_journal_entries')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->nullableMorphs('source');
            $table->json('metadata')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'number']);
            $table->index(['contact_id', 'type', 'status']);
        });

        Schema::create('bookkeeper_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('bookkeeper_documents')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('bookkeeper_accounts')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->bigInteger('unit_price')->comment('Document currency, minor units');
            $table->bigInteger('discount')->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('bookkeeper_tax_rates')->restrictOnDelete();
            $table->bigInteger('net_amount');
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('bookkeeper_payments', function (Blueprint $table) {
            $table->id();
            $table->string('direction')->comment('received, made');
            $table->string('number')->unique();
            $table->foreignId('contact_id')->constrained('bookkeeper_contacts')->restrictOnDelete();
            $table->date('date');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->bigInteger('amount')->comment('Gross amount settled, payment currency');
            $table->foreignId('withholding_tax_rate_id')->nullable()->constrained('bookkeeper_tax_rates')->restrictOnDelete();
            $table->bigInteger('withholding_amount')->default(0);
            $table->foreignId('account_id')->constrained('bookkeeper_accounts')->restrictOnDelete()->comment('Bank, cash or mobile money account');
            $table->foreignId('control_account_id')->constrained('bookkeeper_accounts')->restrictOnDelete()->comment('Receivable or payable account settled');
            $table->foreignId('journal_entry_id')->nullable()->constrained('bookkeeper_journal_entries')->restrictOnDelete();
            $table->string('status')->default('posted');
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->string('memo')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->nullableMorphs('source');
            $table->json('metadata')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bookkeeper_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('bookkeeper_documents')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('bookkeeper_payments')->cascadeOnDelete();
            $table->foreignId('credit_document_id')->nullable()->constrained('bookkeeper_documents')->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('amount')->comment('Document currency, minor units');
            $table->bigInteger('base_amount')->comment('Base-currency value relieved from the control account');
            $table->bigInteger('credit_base_amount')->nullable()->comment('Base-currency value consumed from the credit document');
            $table->foreignId('journal_entry_id')->nullable()->constrained('bookkeeper_journal_entries')->restrictOnDelete()->comment('FX adjustment, if any');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_allocations');
        Schema::dropIfExists('bookkeeper_payments');
        Schema::dropIfExists('bookkeeper_document_lines');
        Schema::dropIfExists('bookkeeper_documents');
        Schema::dropIfExists('bookkeeper_contacts');
    }
};
