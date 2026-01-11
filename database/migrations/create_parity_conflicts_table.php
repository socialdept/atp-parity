<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skip if not using manual conflict resolution
        if (config('parity.conflicts.strategy', 'remote') !== 'manual') {
            return;
        }

        $table = config('parity.conflicts.table', 'parity_conflicts');

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('uri')->nullable();
            $table->json('local_data');
            $table->json('remote_data');
            $table->string('status')->default('pending');
            $table->string('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index('status');
            $table->index('uri');
        });
    }

    public function down(): void
    {
        $table = config('parity.conflicts.table', 'parity_conflicts');

        Schema::dropIfExists($table);
    }
};
