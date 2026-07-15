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
        Schema::create('device_agent_commands', function (Blueprint $table) {
            $table->id();
            $table->string('jetson_id');
            $table->string('command');
            $table->json('payload')->nullable();
            $table->string('status')->default('pending'); // pending, executing, completed, failed
            $table->text('result')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            // Index for faster command polling queries
            $table->index(['jetson_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_agent_commands');
    }
};
