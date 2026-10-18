<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->longText('value');
            $t->timestamps();
        });
        Schema::create('portal_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action');
            $t->json('details')->nullable();
            $t->timestamps();
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->string('encoding')->nullable();
            $t->unsignedBigInteger('original_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['encoding', 'original_bytes']));
        Schema::dropIfExists('portal_audit');
        Schema::dropIfExists('portal_settings');
    }
};
