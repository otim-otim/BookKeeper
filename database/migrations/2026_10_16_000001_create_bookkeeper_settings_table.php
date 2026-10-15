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
        Schema::create('bookkeeper_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('bookkeeper_bank_statements', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('account_id')->comment('Statement currency; null means base');
        });

        Schema::table('bookkeeper_documents', function (Blueprint $table) {
            $table->bigInteger('rounding')->default(0)->after('tax_total')
                ->comment('Cash rounding adjustment included in total, document currency');
        });
    }

    public function down(): void
    {
        Schema::table('bookkeeper_documents', function (Blueprint $table) {
            $table->dropColumn('rounding');
        });

        Schema::table('bookkeeper_bank_statements', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::dropIfExists('bookkeeper_settings');
    }
};
