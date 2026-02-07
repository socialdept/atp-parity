<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('atp-parity.pending_syncs.table', 'parity_pending_syncs');

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('pending_id')->unique();
            $table->string('did')->index();
            $table->string('model_class');
            $table->string('model_id');
            $table->string('operation');
            $table->string('reference_mapper_class')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['model_class', 'model_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $table = config('atp-parity.pending_syncs.table', 'parity_pending_syncs');

        Schema::dropIfExists($table);
    }
};
