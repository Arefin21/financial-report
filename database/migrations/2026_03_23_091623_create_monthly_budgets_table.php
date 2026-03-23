<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monthly_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->unsignedTinyInteger('fiscal_month'); // 1..12 (July..June)
            $table->foreignId('cost_category_id')->constrained('cost_categories')->cascadeOnDelete();
            $table->foreignId('economic_code_id')->constrained('economic_codes')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'fiscal_month', 'cost_category_id', 'economic_code_id'], 'mb_unique');
            $table->index(['fiscal_year_id', 'fiscal_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_budgets');
    }
};
