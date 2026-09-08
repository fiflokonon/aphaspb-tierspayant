<?php

use App\Actions\Declarations\RecordDeclarationRevision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A declaration is corrected: a month is filed, an insurer pays late, an
     * amount turns out wrong. Until now each save overwrote the last, and the
     * instalments are rewritten wholesale, so nothing recorded what a figure
     * used to be — an officine questioned on a number had no way to show what
     * it had declared, or when.
     *
     * One snapshot per save that actually changes something. Snapshots rather
     * than diffs: reading a state needs no replay, and a list of instalments
     * has no natural field-by-field diff anyway.
     *
     * The private note is deliberately absent. The trace is about the figures
     * the network reads; the note belongs to the officine alone, and keeping
     * copies of it here would only widen where it could leak from.
     */
    public function up(): void
    {
        Schema::create('declaration_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('declaration_id')->constrained()->cascadeOnDelete();
            // The author survives the account: an officine's history must not
            // lose its own past because a locum's Joomla account was deleted.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');
            $table->unsignedBigInteger('amount_invoiced');
            $table->unsignedBigInteger('amount_received');
            $table->string('status', 20);
            $table->date('invoice_deposited_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->unsignedSmallInteger('delay_days')->nullable();
            /** @see RecordDeclarationRevision */
            $table->json('payments');
            $table->timestamps();

            $table->index(['declaration_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('declaration_revisions');
    }
};
