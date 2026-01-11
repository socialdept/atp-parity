<?php

namespace SocialDept\AtpParity\Tests\Unit\PendingSync;

use SocialDept\AtpParity\PendingSync\PendingSyncRetryResult;
use SocialDept\AtpParity\Tests\TestCase;

class PendingSyncRetryResultTest extends TestCase
{
    public function test_all_succeeded_when_no_failures_or_skipped(): void
    {
        $result = new PendingSyncRetryResult(
            total: 5,
            succeeded: 5,
            failed: 0,
            skipped: 0,
        );

        $this->assertTrue($result->allSucceeded());
        $this->assertFalse($result->hasFailures());
    }

    public function test_has_failures_when_failed_count_greater_than_zero(): void
    {
        $result = new PendingSyncRetryResult(
            total: 5,
            succeeded: 3,
            failed: 2,
            skipped: 0,
        );

        $this->assertTrue($result->hasFailures());
        $this->assertFalse($result->allSucceeded());
    }

    public function test_all_succeeded_is_false_when_skipped(): void
    {
        $result = new PendingSyncRetryResult(
            total: 5,
            succeeded: 4,
            failed: 0,
            skipped: 1,
        );

        $this->assertFalse($result->allSucceeded());
        $this->assertFalse($result->hasFailures());
    }

    public function test_is_empty_when_no_pending_syncs(): void
    {
        $result = new PendingSyncRetryResult(
            total: 0,
            succeeded: 0,
            failed: 0,
            skipped: 0,
        );

        $this->assertTrue($result->isEmpty());
    }

    public function test_is_not_empty_when_has_pending_syncs(): void
    {
        $result = new PendingSyncRetryResult(
            total: 3,
            succeeded: 3,
            failed: 0,
            skipped: 0,
        );

        $this->assertFalse($result->isEmpty());
    }

    public function test_processed_returns_succeeded_plus_skipped(): void
    {
        $result = new PendingSyncRetryResult(
            total: 10,
            succeeded: 6,
            failed: 2,
            skipped: 2,
        );

        $this->assertSame(8, $result->processed());
    }

    public function test_errors_are_accessible(): void
    {
        $errors = [
            'Failed to sync model 1',
            'API error for model 2',
        ];

        $result = new PendingSyncRetryResult(
            total: 5,
            succeeded: 3,
            failed: 2,
            skipped: 0,
            errors: $errors,
        );

        $this->assertSame($errors, $result->errors);
        $this->assertCount(2, $result->errors);
    }

    public function test_properties_are_accessible(): void
    {
        $result = new PendingSyncRetryResult(
            total: 10,
            succeeded: 7,
            failed: 2,
            skipped: 1,
            errors: ['error1'],
        );

        $this->assertSame(10, $result->total);
        $this->assertSame(7, $result->succeeded);
        $this->assertSame(2, $result->failed);
        $this->assertSame(1, $result->skipped);
    }
}
