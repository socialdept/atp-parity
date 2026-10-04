<?php

namespace SocialDept\AtpParity\Tests\Unit\Support;

use SocialDept\AtpCbor\Core\CBOR;
use SocialDept\AtpParity\Support\RecordSize;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * What a record costs once a PDS stores it, not what it costs on the wire.
 *
 * Measuring the JSON form counts a map where the server counts a tag-42 link,
 * so every record carrying blobs reads larger than it is.
 */
class RecordSizeTest extends TestCase
{
    private const CID = 'bafyreiasqx6lkeilrygjtklpw36tu5ranufbi3in3udvh3yofhbnlpyrkm';

    private function record(): array
    {
        return [
            '$type' => 'app.test.overflowrecord',
            'title' => 'A document',
            'image' => ['$type' => 'blob', 'ref' => ['$link' => self::CID], 'mimeType' => 'image/jpeg', 'size' => 1024],
        ];
    }

    public function test_it_measures_a_link_as_a_link_and_not_as_an_object(): void
    {
        $measured = RecordSize::of($this->record());
        $wireForm = strlen(CBOR::encode($this->record()));

        $this->assertNotNull($measured);
        $this->assertLessThan($wireForm, $measured);
    }

    public function test_it_reports_null_for_a_record_it_cannot_encode(): void
    {
        $this->assertNull(RecordSize::of(['image' => ['$link' => 'not-a-cid']]));
    }

    /** Null is not zero, or an unmeasurable record skips the overflow it needed. */
    public function test_an_unmeasurable_record_does_not_read_as_under_the_limit(): void
    {
        $this->assertFalse(RecordSize::exceeds(['image' => ['$link' => 'not-a-cid']], 10));
    }

    public function test_it_compares_against_a_limit(): void
    {
        $this->assertTrue(RecordSize::exceeds($this->record(), 10));
        $this->assertFalse(RecordSize::exceeds($this->record(), 10_000));
    }
}
