<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use Carbon\Carbon;
use SocialDept\AtpParity\Sync\ConflictDetector;
use SocialDept\AtpParity\Tests\Fixtures\SyncableModel;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\Fixtures\TestRecord;
use SocialDept\AtpParity\Tests\TestCase;

class ConflictDetectorTest extends TestCase
{
    private ConflictDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new ConflictDetector();
    }

    public function test_no_conflict_when_cid_matches(): void
    {
        $model = new TestModel([
            'atp_cid' => 'samecid123',
            'atp_synced_at' => Carbon::parse('2024-01-15 12:00:00'),
            'updated_at' => Carbon::parse('2024-01-15 13:00:00'), // local changes
        ]);
        $record = new TestRecord(text: 'Remote content');

        $hasConflict = $this->detector->hasConflict($model, $record, 'samecid123');

        $this->assertFalse($hasConflict);
    }

    public function test_no_conflict_when_no_local_changes(): void
    {
        $model = new TestModel([
            'atp_cid' => 'oldcid',
            'atp_synced_at' => Carbon::parse('2024-01-15 13:00:00'),
            'updated_at' => Carbon::parse('2024-01-15 12:00:00'), // updated before sync
        ]);
        $record = new TestRecord(text: 'Remote content');

        $hasConflict = $this->detector->hasConflict($model, $record, 'newcid');

        $this->assertFalse($hasConflict);
    }

    public function test_conflict_when_cid_differs_and_local_changes(): void
    {
        $model = new TestModel([
            'atp_cid' => 'oldcid',
            'atp_synced_at' => Carbon::parse('2024-01-15 12:00:00'),
            'updated_at' => Carbon::parse('2024-01-15 13:00:00'), // local changes
        ]);
        $record = new TestRecord(text: 'Remote content');

        $hasConflict = $this->detector->hasConflict($model, $record, 'newcid');

        $this->assertTrue($hasConflict);
    }

    public function test_conflict_when_never_synced(): void
    {
        $model = new TestModel([
            'atp_cid' => 'cid',
            // No atp_synced_at means never synced, which implies local changes
        ]);
        $record = new TestRecord(text: 'Remote');

        $hasConflict = $this->detector->hasConflict($model, $record, 'differentcid');

        $this->assertTrue($hasConflict);
    }

    public function test_uses_syncs_with_atp_trait_method(): void
    {
        $model = new SyncableModel([
            'atp_cid' => 'oldcid',
            'atp_synced_at' => Carbon::parse('2024-01-15 12:00:00'),
            'updated_at' => Carbon::parse('2024-01-15 13:00:00'),
        ]);
        $record = new TestRecord(text: 'Remote');

        $hasConflict = $this->detector->hasConflict($model, $record, 'newcid');

        $this->assertTrue($hasConflict);
    }

    public function test_no_conflict_when_synced_after_update_with_trait(): void
    {
        $model = new SyncableModel([
            'atp_cid' => 'oldcid',
            'updated_at' => Carbon::parse('2024-01-15 12:00:00'),
            'atp_synced_at' => Carbon::parse('2024-01-15 13:00:00'),
        ]);
        $record = new TestRecord(text: 'Remote');

        $hasConflict = $this->detector->hasConflict($model, $record, 'newcid');

        $this->assertFalse($hasConflict);
    }

    public function test_no_conflict_without_updated_at(): void
    {
        $model = new TestModel([
            'atp_cid' => 'cid',
            'atp_synced_at' => Carbon::now(),
            // No updated_at
        ]);
        $record = new TestRecord(text: 'Remote');

        $hasConflict = $this->detector->hasConflict($model, $record, 'newcid');

        $this->assertFalse($hasConflict);
    }
}
