<?php

namespace SocialDept\AtpParity\Tests\Unit\Import;

use SocialDept\AtpParity\Import\ImportResult;
use SocialDept\AtpParity\Tests\TestCase;

class ImportResultTest extends TestCase
{
    public function test_success_creates_completed_result(): void
    {
        $result = ImportResult::success(
            did: 'did:plc:test123',
            collection: 'app.bsky.feed.post',
            synced: 50,
            skipped: 5,
            failed: 2
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isPartial());
        $this->assertFalse($result->isFailed());
        $this->assertTrue($result->completed);
        $this->assertNull($result->error);
        $this->assertSame(50, $result->recordsSynced);
        $this->assertSame(5, $result->recordsSkipped);
        $this->assertSame(2, $result->recordsFailed);
    }

    public function test_partial_creates_incomplete_result(): void
    {
        $result = ImportResult::partial(
            did: 'did:plc:test123',
            collection: 'app.bsky.feed.post',
            synced: 100,
            cursor: 'abc123'
        );

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isPartial());
        $this->assertFalse($result->isFailed());
        $this->assertFalse($result->completed);
        $this->assertSame('abc123', $result->cursor);
        $this->assertNull($result->error);
    }

    public function test_failed_creates_error_result(): void
    {
        $result = ImportResult::failed(
            did: 'did:plc:test123',
            collection: 'app.bsky.feed.post',
            error: 'Connection failed'
        );

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isPartial()); // no records synced
        $this->assertTrue($result->isFailed());
        $this->assertSame('Connection failed', $result->error);
    }

    public function test_failed_with_partial_progress(): void
    {
        $result = ImportResult::failed(
            did: 'did:plc:test123',
            collection: 'app.bsky.feed.post',
            error: 'Connection lost',
            synced: 50,
            cursor: 'xyz789'
        );

        $this->assertTrue($result->isFailed());
        $this->assertTrue($result->isPartial()); // has synced records
        $this->assertSame(50, $result->recordsSynced);
        $this->assertSame('xyz789', $result->cursor);
    }

    public function test_total_processed_sums_all_records(): void
    {
        $result = ImportResult::success(
            did: 'did:plc:test123',
            collection: 'app.bsky.feed.post',
            synced: 50,
            skipped: 10,
            failed: 5
        );

        $this->assertSame(65, $result->totalProcessed());
    }

    public function test_aggregate_combines_multiple_results(): void
    {
        $results = [
            ImportResult::success('did:plc:test', 'app.bsky.feed.post', synced: 50),
            ImportResult::success('did:plc:test', 'app.bsky.feed.like', synced: 100, failed: 5),
        ];

        $aggregate = ImportResult::aggregate('did:plc:test', $results);

        $this->assertTrue($aggregate->isSuccess());
        $this->assertSame('*', $aggregate->collection);
        $this->assertSame(150, $aggregate->recordsSynced);
        $this->assertSame(5, $aggregate->recordsFailed);
        $this->assertNull($aggregate->error);
    }

    public function test_aggregate_marks_incomplete_when_any_incomplete(): void
    {
        $results = [
            ImportResult::success('did:plc:test', 'app.bsky.feed.post', synced: 50),
            ImportResult::partial('did:plc:test', 'app.bsky.feed.like', synced: 100, cursor: 'abc'),
        ];

        $aggregate = ImportResult::aggregate('did:plc:test', $results);

        $this->assertFalse($aggregate->completed);
    }

    public function test_aggregate_combines_errors(): void
    {
        $results = [
            ImportResult::failed('did:plc:test', 'app.bsky.feed.post', error: 'Error 1'),
            ImportResult::failed('did:plc:test', 'app.bsky.feed.like', error: 'Error 2'),
        ];

        $aggregate = ImportResult::aggregate('did:plc:test', $results);

        $this->assertTrue($aggregate->isFailed());
        $this->assertStringContainsString('app.bsky.feed.post: Error 1', $aggregate->error);
        $this->assertStringContainsString('app.bsky.feed.like: Error 2', $aggregate->error);
    }

    public function test_aggregate_with_empty_array(): void
    {
        $aggregate = ImportResult::aggregate('did:plc:test', []);

        $this->assertTrue($aggregate->completed);
        $this->assertSame(0, $aggregate->recordsSynced);
    }
}
