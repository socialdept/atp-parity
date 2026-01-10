<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpParity\Sync\ReferenceSyncResult;

class ReferenceSyncResultTest extends TestCase
{
    public function test_success_creates_successful_result(): void
    {
        $result = ReferenceSyncResult::success(
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyrei123',
            referenceUri: 'at://did:plc:test/app.test.ref/xyz',
            referenceCid: 'bafyrei456'
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertSame('at://did:plc:test/app.test.main/abc', $result->mainUri);
        $this->assertSame('bafyrei123', $result->mainCid);
        $this->assertSame('at://did:plc:test/app.test.ref/xyz', $result->referenceUri);
        $this->assertSame('bafyrei456', $result->referenceCid);
        $this->assertNull($result->error);
    }

    public function test_failed_creates_failed_result(): void
    {
        $result = ReferenceSyncResult::failed('Something went wrong');

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailed());
        $this->assertSame('Something went wrong', $result->error);
        $this->assertNull($result->mainUri);
        $this->assertNull($result->referenceUri);
    }

    public function test_reference_success_creates_result_with_reference_data(): void
    {
        $result = ReferenceSyncResult::referenceSuccess(
            referenceUri: 'at://did:plc:test/app.test.ref/xyz',
            referenceCid: 'bafyrei456',
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyrei123'
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('at://did:plc:test/app.test.ref/xyz', $result->referenceUri);
        $this->assertSame('bafyrei456', $result->referenceCid);
    }

    public function test_is_fully_synced_returns_true_when_both_uris_present(): void
    {
        $result = ReferenceSyncResult::success(
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyrei123',
            referenceUri: 'at://did:plc:test/app.test.ref/xyz',
            referenceCid: 'bafyrei456'
        );

        $this->assertTrue($result->isFullySynced());
    }

    public function test_is_fully_synced_returns_false_when_reference_missing(): void
    {
        $result = ReferenceSyncResult::success(
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyrei123'
        );

        $this->assertFalse($result->isFullySynced());
    }

    public function test_has_main_only_returns_true_when_only_main_synced(): void
    {
        $result = ReferenceSyncResult::success(
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyrei123'
        );

        $this->assertTrue($result->hasMainOnly());
        $this->assertFalse($result->hasReferenceOnly());
    }

    public function test_has_reference_only_returns_true_when_only_reference_synced(): void
    {
        $result = ReferenceSyncResult::referenceSuccess(
            referenceUri: 'at://did:plc:test/app.test.ref/xyz',
            referenceCid: 'bafyrei456'
        );

        $this->assertTrue($result->hasReferenceOnly());
        $this->assertFalse($result->hasMainOnly());
    }
}
