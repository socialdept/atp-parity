<?php

namespace SocialDept\AtpParity\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpParity\ParityServiceProvider;
use SocialDept\AtpSignals\SignalServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AtpSupportServiceProvider::class,
            AtpClientServiceProvider::class,
            SignalServiceProvider::class,
            ParityServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('atp-parity.columns.uri', 'atp_uri');
        $app['config']->set('atp-parity.columns.cid', 'atp_cid');
    }

    protected function setUpDatabase(): void
    {
        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            $table->string('content')->nullable();
            $table->string('did')->nullable();
            $table->string('atp_uri')->nullable()->unique();
            $table->string('atp_cid')->nullable();
            $table->timestamp('atp_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('parity_import_states', function (Blueprint $table) {
            $table->id();
            $table->string('did');
            $table->string('collection');
            $table->string('status')->default('pending');
            $table->integer('records_synced')->default(0);
            $table->integer('records_skipped')->default(0);
            $table->integer('records_failed')->default(0);
            $table->string('cursor')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['did', 'collection']);
        });

        Schema::create('parity_conflicts', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->string('uri');
            $table->string('remote_cid');
            $table->json('remote_data');
            $table->string('status')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // Reference model table (single model with dual URIs)
        Schema::create('reference_models', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('did')->nullable();
            $table->atp();
            $table->atpReference();
            $table->timestamps();
        });

        // Main model for pivot pattern
        Schema::create('main_models', function (Blueprint $table) {
            $table->id();
            $table->string('content')->nullable();
            $table->atp();
            $table->timestamps();
        });

        // Pivot reference model table
        Schema::create('pivot_reference_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_model_id')->nullable();
            $table->atp();
            $table->timestamps();
        });

        // Pending syncs table
        Schema::create('parity_pending_syncs', function (Blueprint $table) {
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
}
