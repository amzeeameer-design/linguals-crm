<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $t->foreignId('sender_id')->constrained('users');
            $t->string('sender_role');
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_messages');
    }
};
