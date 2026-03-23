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
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            // Fiscal year starts in July and ends in June of the next calendar year.
            $table->string('label')->unique(); // e.g. 2024/2025
            $table->unsignedInteger('start_year'); // e.g. 2024 => starts 2024-07-01
            $table->date('start_date'); // July 1st
            $table->date('end_date'); // June 30th
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
