<?php

namespace SocialDept\AtpParity\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpParity\ParityServiceProvider;
use SocialDept\AtpResolver\ResolverServiceProvider;
use SocialDept\AtpSignals\SignalServiceProvider;

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
            ResolverServiceProvider::class,
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

        $app['config']->set('parity.columns.uri', 'atp_uri');
        $app['config']->set('parity.columns.cid', 'atp_cid');
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
    }
}
