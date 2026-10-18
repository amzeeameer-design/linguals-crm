<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_messages', function (Blueprint $table): void {
            $table->string('title', 180)->nullable();
            $table->boolean('requires_acknowledgement')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portal_messages', function (Blueprint $table): void {
            $table->dropColumn(['title', 'requires_acknowledgement', 'read_at', 'acknowledged_at']);
        });
    }
};
