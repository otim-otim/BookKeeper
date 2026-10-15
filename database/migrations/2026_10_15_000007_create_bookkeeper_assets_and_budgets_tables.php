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
        Schema::create('bookkeeper_fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('asset_account_id')->constrained('bookkeeper_accounts')->restrictOnDelete();
            // Explicit names: the generated ones exceed MySQL's 64-character limit.
            $table->foreignId('accumulated_depreciation_account_id')->constrained('bookkeeper_accounts', indexName: 'bookkeeper_fixed_assets_accum_dep_account_foreign')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')->constrained('bookkeeper_accounts', indexName: 'bookkeeper_fixed_assets_dep_exp_account_foreign')->restrictOnDelete();
            $table->bigInteger('cost');
            $table->bigInteger('salvage_value')->default(0);
            $table->string('method')->default('straight_line');
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->decimal('declining_rate', 9, 6)->nullable()->comment('Annual percentage for declining balance');
            $table->date('acquired_on');
            $table->date('depreciation_start_date');
            $table->string('status')->default('active');
            $table->date('disposed_on')->nullable();
            $table->bigInteger('disposal_proceeds')->nullable();
            $table->foreignId('acquisition_entry_id')->nullable()->constrained('bookkeeper_journal_entries')->restrictOnDelete();
            $table->foreignId('disposal_entry_id')->nullable()->constrained('bookkeeper_journal_entries')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('bookkeeper_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('bookkeeper_fixed_assets')->cascadeOnDelete();
            $table->date('period_end');
            $table->bigInteger('amount');
            $table->foreignId('journal_entry_id')->constrained('bookkeeper_journal_entries')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period_end']);
        });

        Schema::create('bookkeeper_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('fiscal_year_id')->nullable()->constrained('bookkeeper_fiscal_years')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('bookkeeper_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('bookkeeper_budgets')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('bookkeeper_accounts')->restrictOnDelete();
            $table->date('period_start')->comment('First day of the budgeted month');
            $table->bigInteger('amount')->comment('Normal-balance direction, minor units');
            $table->timestamps();

            $table->unique(['budget_id', 'account_id', 'period_start'], 'bookkeeper_budget_lines_account_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_budget_lines');
        Schema::dropIfExists('bookkeeper_budgets');
        Schema::dropIfExists('bookkeeper_asset_depreciations');
        Schema::dropIfExists('bookkeeper_fixed_assets');
    }
};
