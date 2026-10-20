<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Une campagne d'évaluation des assureurs, ouverte par l'admin : l'année
     * évaluée, et la fenêtre pendant laquelle les officines répondent. Une
     * seule campagne par année.
     *
     * `announced_at` date l'annonce de l'ouverture aux officines
     * (evaluations:announce) : une campagne dont l'admin déplace les dates
     * après l'annonce ne doit pas repartir.
     */
    public function up(): void
    {
        Schema::create('evaluation_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->date('opens_on');
            $table->date('closes_on');
            $table->timestamp('announced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_campaigns');
    }
};
