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
        Schema::create('qnap_settings', function (Blueprint $table) {
            $table->id();
            $table->text('qnap_host');
            $table->integer('qnap_port')->default(443);
            $table->string('qnap_protocol')->default('https');
            $table->text('qnap_username');
            $table->text('qnap_password');
            $table->string('qnap_remote_path')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qnap_settings');
    }
};
