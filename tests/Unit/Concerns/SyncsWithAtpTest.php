<?php

namespace SocialDept\AtpParity\Tests\Unit\Concerns;

use Carbon\Carbon;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Tests\Fixtures\SyncableMapper;
use SocialDept\AtpParity\Tests\Fixtures\SyncableModel;
use SocialDept\AtpParity\Tests\Fixtures\TestRecord;
use SocialDept\AtpParity\Tests\TestCase;

class SyncsWithAtpTest extends TestCase
{
    public function test_get_atp_synced_at_column_returns_default(): void
    {
        $model = new SyncableModel();

        $this->assertSame('atp_synced_at', $model->getAtpSyncedAtColumn());
    }

    public function test_get_atp_synced_at_returns_timestamp(): void
    {
        $now = Carbon::now();
        $model = new SyncableModel(['atp_synced_at' => $now]);

        $this->assertEquals($now->toDateTimeString(), $model->getAtpSyncedAt()->toDateTimeString());
    }

    public function test_get_atp_synced_at_returns_null_when_not_set(): void
    {
        $model = new SyncableModel();

        $this->assertNull($model->getAtpSyncedAt());
    }

    public function test_mark_as_synced_sets_all_attributes(): void
    {
        Carbon::setTestNow('2024-01-15 12:00:00');

        $model = new SyncableModel();
        $model->markAsSynced('at://did/col/rkey', 'cid123');

        $this->assertSame('at://did/col/rkey', $model->atp_uri);
        $this->assertSame('cid123', $model->atp_cid);
        $this->assertSame('2024-01-15 12:00:00', $model->atp_synced_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_has_local_changes_returns_true_when_never_synced(): void
    {
        $model = new SyncableModel();

        $this->assertTrue($model->hasLocalChanges());
    }

    public function test_has_local_changes_returns_false_when_no_updated_at(): void
    {
        $model = new SyncableModel([
            'atp_synced_at' => Carbon::now(),
        ]);

        $this->assertFalse($model->hasLocalChanges());
    }

    public function test_has_local_changes_returns_true_when_updated_after_sync(): void
    {
        $model = new SyncableModel([
            'atp_synced_at' => Carbon::parse('2024-01-15 12:00:00'),
            'updated_at' => Carbon::parse('2024-01-15 13:00:00'),
        ]);

        $this->assertTrue($model->hasLocalChanges());
    }

    public function test_has_local_changes_returns_false_when_synced_after_update(): void
    {
        $model = new SyncableModel([
            'updated_at' => Carbon::parse('2024-01-15 12:00:00'),
            'atp_synced_at' => Carbon::parse('2024-01-15 13:00:00'),
        ]);

        $this->assertFalse($model->hasLocalChanges());
    }

    public function test_update_from_record_updates_model_and_sync_timestamp(): void
    {
        Carbon::setTestNow('2024-01-15 14:00:00');

        $registry = app(MapperRegistry::class);
        $registry->register(new SyncableMapper());

        $model = new SyncableModel(['content' => 'Original']);
        $record = new TestRecord(text: 'From remote');

        $model->updateFromRecord($record, 'at://did/col/rkey', 'newcid');

        $this->assertSame('From remote', $model->content);
        $this->assertSame('at://did/col/rkey', $model->atp_uri);
        $this->assertSame('newcid', $model->atp_cid);
        $this->assertSame('2024-01-15 14:00:00', $model->atp_synced_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_update_from_record_does_nothing_without_mapper(): void
    {
        $this->app->forgetInstance(MapperRegistry::class);
        $this->app->singleton(MapperRegistry::class);

        $model = new SyncableModel(['content' => 'Original']);
        $record = new TestRecord(text: 'From remote');

        $model->updateFromRecord($record, 'at://did/col/rkey', 'cid');

        $this->assertSame('Original', $model->content);
    }

    public function test_inherits_has_atp_record_methods(): void
    {
        $model = new SyncableModel(['atp_uri' => 'at://did:plc:test/app.test.record/rkey']);

        $this->assertTrue($model->hasAtpRecord());
        $this->assertSame('did:plc:test', $model->getAtpDid());
        $this->assertSame('app.test.record', $model->getAtpCollection());
        $this->assertSame('rkey', $model->getAtpRkey());
    }
}
