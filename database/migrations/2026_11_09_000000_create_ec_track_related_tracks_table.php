<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_track_related_tracks', function (Blueprint $table) {
            $table->unsignedBigInteger('ec_track_id');
            $table->unsignedBigInteger('related_track_id');
            $table->primary(['ec_track_id', 'related_track_id']);
            $table->foreign('ec_track_id')->references('id')->on('ec_tracks')->onDelete('cascade');
            $table->foreign('related_track_id')->references('id')->on('ec_tracks')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_track_related_tracks');
    }
};
