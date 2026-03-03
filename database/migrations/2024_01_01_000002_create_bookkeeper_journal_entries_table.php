<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookkeeper_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('memo')->nullable()->comment('Human-readable description of this entry');
            $table->string('reference')->nullable()->comment('External reference number, e.g. invoice ID');
            $table->string('currency', 3)->default('USD');
            $table->json('metadata')->nullable()->comment('Any extra data the host app wants to attach');
            $table->timestamps();

            $table->index('date');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_journal_entries');
    }
};
