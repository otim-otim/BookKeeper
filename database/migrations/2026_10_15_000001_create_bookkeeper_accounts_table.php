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
        Schema::create('bookkeeper_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('bookkeeper_accounts')->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->comment('asset, liability, equity, revenue, expense');
            $table->string('subtype')->nullable();
            $table->string('role')->nullable()->unique()->comment('Purpose the application refers to the account by');
            $table->text('description')->nullable();
            $table->char('currency', 3)->nullable()->comment('Null: base currency');
            $table->boolean('is_group')->default(false)->comment('Header account: groups children, cannot be posted to');
            $table->boolean('is_control')->default(false)->comment('Control account: every line names a subledger party');
            $table->boolean('is_system')->default(false)->comment('Created by a chart template; cannot be deleted');
            $table->boolean('is_active')->default(true);
            $table->string('cash_flow_category')->nullable()->comment('Overrides the subtype default');
            $table->json('tags')->nullable()->comment('Free classification, e.g. liquid, core_capital');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeper_accounts');
    }
};
