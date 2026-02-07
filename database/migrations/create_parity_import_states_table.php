<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('atp-parity.import.state_table', 'parity_import_states');

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('did');
            $table->string('collection');
            $table->string('status')->default('pending');
            $table->string('cursor')->nullable();
            $table->unsignedInteger('records_synced')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['did', 'collection']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        $table = config('atp-parity.import.state_table', 'parity_import_states');

        Schema::dropIfExists($table);
    }
};
