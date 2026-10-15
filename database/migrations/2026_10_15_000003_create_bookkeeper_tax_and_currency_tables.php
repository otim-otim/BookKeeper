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
        Schema::create('bookkeeper_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('type')->comment('sales, purchase, withholding');
            $table->decimal('rate', 9, 6)->comment('Percentage, e.g. 18.000000');
            $table->foreignId('account_id')->nullable()->constrained('bookkeeper_accounts')->restrictOnDelete()
                ->comment('Null on a purchase rate: tax is not recoverable and is added to the cost');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['code', 'effective_from']);
        });

        Schema::create('bookkeeper_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('date');
            $table->decimal('rate', 24, 10)->comment('Base-currency units per 1 unit of currency');
            $table->timestamps();

            $table->unique(['currency', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_exchange_rates');
        Schema::dropIfExists('bookkeeper_tax_rates');
    }
};
