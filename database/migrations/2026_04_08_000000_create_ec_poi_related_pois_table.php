<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_poi_related_pois', function (Blueprint $table) {
            $table->unsignedBigInteger('ec_poi_id');
            $table->unsignedBigInteger('related_poi_id');
            $table->primary(['ec_poi_id', 'related_poi_id']);
            $table->foreign('ec_poi_id')->references('id')->on('ec_pois')->onDelete('cascade');
            $table->foreign('related_poi_id')->references('id')->on('ec_pois')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_poi_related_pois');
    }
};
