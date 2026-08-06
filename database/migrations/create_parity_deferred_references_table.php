<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('atp-parity.deferred_references.table', 'parity_deferred_references');

        Schema::create($table, function (Blueprint $table) {
            $table->id();

            // Unique: an orphan can be re-delivered and must not duplicate.
            $table->string('reference_uri')->unique();

            // The lookup this table exists to serve: "what was waiting for this
            // record?", asked once when a main record is created.
            $table->string('target_uri')->index();

            $table->string('collection');
            $table->string('did')->index();
            $table->string('cid')->nullable();

            // Raw record body, replayed through the mapper verbatim so a lexicon
            // change between parking and replay behaves like a fresh delivery.
            $table->json('record');

            $table->timestamp('parked_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('atp-parity.deferred_references.table', 'parity_deferred_references'));
    }
};
