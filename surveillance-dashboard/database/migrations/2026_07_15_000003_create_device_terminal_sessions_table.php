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
        Schema::create('device_terminal_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('jetson_id');
            $table->unsignedBigInteger('command_id')->nullable();
            $table->integer('port');
            $table->string('status')->default('requested'); // requested, open, closed, expired
            $table->integer('timeout_minutes')->default(10);
            $table->string('connection_string')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['jetson_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_terminal_sessions');
    }
};
