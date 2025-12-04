<?php

namespace SocialDept\AtpParity\Tests\Unit\Publish;

use SocialDept\AtpParity\Publish\PublishResult;
use SocialDept\AtpParity\Tests\TestCase;

class PublishResultTest extends TestCase
{
    public function test_success_creates_successful_result(): void
    {
        $result = PublishResult::success(
            uri: 'at://did:plc:test/app.bsky.feed.post/abc123',
            cid: 'bafyreiabc123'
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertSame('at://did:plc:test/app.bsky.feed.post/abc123', $result->uri);
        $this->assertSame('bafyreiabc123', $result->cid);
        $this->assertNull($result->error);
    }

    public function test_failed_creates_failed_result(): void
    {
        $result = PublishResult::failed('Authentication required');

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailed());
        $this->assertNull($result->uri);
        $this->assertNull($result->cid);
        $this->assertSame('Authentication required', $result->error);
    }

    public function test_is_success_returns_correct_boolean(): void
    {
        $success = new PublishResult(success: true);
        $failure = new PublishResult(success: false);

        $this->assertTrue($success->isSuccess());
        $this->assertFalse($failure->isSuccess());
    }

    public function test_is_failed_returns_correct_boolean(): void
    {
        $success = new PublishResult(success: true);
        $failure = new PublishResult(success: false);

        $this->assertFalse($success->isFailed());
        $this->assertTrue($failure->isFailed());
    }

    public function test_success_result_properties_are_accessible(): void
    {
        $result = PublishResult::success('at://did/col/rkey', 'cid123');

        $this->assertTrue($result->success);
        $this->assertSame('at://did/col/rkey', $result->uri);
        $this->assertSame('cid123', $result->cid);
    }

    public function test_failed_result_error_is_accessible(): void
    {
        $result = PublishResult::failed('Something went wrong');

        $this->assertFalse($result->success);
        $this->assertSame('Something went wrong', $result->error);
    }
}
