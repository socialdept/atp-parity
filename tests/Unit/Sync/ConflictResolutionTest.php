<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use SocialDept\AtpParity\Sync\ConflictResolution;
use SocialDept\AtpParity\Sync\PendingConflict;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

class ConflictResolutionTest extends TestCase
{
    public function test_remote_wins_creates_resolved_resolution(): void
    {
        $model = new TestModel();

        $resolution = ConflictResolution::remoteWins($model);

        $this->assertTrue($resolution->isResolved());
        $this->assertFalse($resolution->isPending());
        $this->assertSame('remote', $resolution->winner);
        $this->assertSame($model, $resolution->model);
        $this->assertNull($resolution->pending);
    }

    public function test_local_wins_creates_resolved_resolution(): void
    {
        $model = new TestModel();

        $resolution = ConflictResolution::localWins($model);

        $this->assertTrue($resolution->isResolved());
        $this->assertFalse($resolution->isPending());
        $this->assertSame('local', $resolution->winner);
        $this->assertSame($model, $resolution->model);
        $this->assertNull($resolution->pending);
    }

    public function test_pending_creates_unresolved_resolution(): void
    {
        $pending = new PendingConflict();

        $resolution = ConflictResolution::pending($pending);

        $this->assertFalse($resolution->isResolved());
        $this->assertTrue($resolution->isPending());
        $this->assertSame('manual', $resolution->winner);
        $this->assertNull($resolution->model);
        $this->assertSame($pending, $resolution->pending);
    }

    public function test_is_resolved_returns_correct_boolean(): void
    {
        $resolved = new ConflictResolution(resolved: true, winner: 'remote');
        $unresolved = new ConflictResolution(resolved: false, winner: 'manual');

        $this->assertTrue($resolved->isResolved());
        $this->assertFalse($unresolved->isResolved());
    }

    public function test_is_pending_returns_false_when_no_pending_conflict(): void
    {
        $resolution = new ConflictResolution(resolved: false, winner: 'manual');

        $this->assertFalse($resolution->isPending());
    }
}
