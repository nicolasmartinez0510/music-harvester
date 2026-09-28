<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->text('url');
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error')->nullable();
            $table->text('destination_path')->nullable();
            $table->json('options_json')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_jobs');
    }
};
