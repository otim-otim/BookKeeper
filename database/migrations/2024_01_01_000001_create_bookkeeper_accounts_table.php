<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookkeeper_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Unique account code, e.g. 1000');
            $table->string('name');
            $table->string('type')->comment('asset, liability, equity, revenue, expense');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_accounts');
    }
};
