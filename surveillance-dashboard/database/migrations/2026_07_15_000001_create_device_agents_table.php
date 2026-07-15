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
        Schema::create('device_agents', function (Blueprint $table) {
            $table->id();
            $table->string('jetson_id')->unique();
            $table->string('hostname')->nullable();
            $table->string('agent_version')->default('1.0');
            $table->boolean('online')->default(false);
            $table->timestamp('last_seen')->nullable();
            $table->integer('uptime')->default(0);
            $table->integer('cpu')->default(0);
            $table->integer('ram')->default(0);
            $table->integer('disk')->default(0);
            $table->integer('temperature')->default(0);
            $table->json('system_info')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_agents');
    }
};
