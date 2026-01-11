<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('parity.blobs.table', 'parity_blob_mappings');

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('cid', 64)->unique();
            $table->string('did');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size');
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('media_id')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('source')->default('remote');
            $table->timestamps();

            $table->index('did');
            $table->index(['disk', 'path']);
        });
    }

    public function down(): void
    {
        $table = config('parity.blobs.table', 'parity_blob_mappings');

        Schema::dropIfExists($table);
    }
};
