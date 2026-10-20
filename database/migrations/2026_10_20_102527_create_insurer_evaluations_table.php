<?php

use App\Enums\EvaluationCriterion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Une évaluation par officine, par assureur et par campagne : une colonne
     * par critère du canevas. Le total sur 20 n'est pas stocké, il se somme.
     *
     * `suggested_payment_delay` garde la note que le système proposait au
     * moment de l'enregistrement : ce qui a été suggéré et ce que l'officine a
     * validé restent distinguables.
     */
    public function up(): void
    {
        Schema::create('insurer_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurer_id')->constrained()->cascadeOnDelete();
            // Comme les « pas de facture » : l'auteur survit au compte.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');

            foreach (EvaluationCriterion::columns() as $column) {
                $table->unsignedTinyInteger($column);
            }

            $table->unsignedTinyInteger('suggested_payment_delay')->nullable();
            $table->timestamps();

            $table->unique(['evaluation_campaign_id', 'pharmacy_id', 'insurer_id'], 'insurer_eval_campaign_pharmacy_insurer_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurer_evaluations');
    }
};
