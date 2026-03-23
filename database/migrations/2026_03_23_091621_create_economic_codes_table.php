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
        Schema::create('economic_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_category_id')->constrained('cost_categories')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
            $table->unique(['cost_category_id', 'code']);
            $table->index(['cost_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('economic_codes');
    }
};
