<?php

namespace SocialDept\AtpParity\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpParity\Support\BlobCid;

/**
 * Pins the local blob address to the one a PDS assigns.
 *
 * The overflow guard compares a record built with this CID against the record
 * last written with the PDS's own, so a mismatch would make every guarded resync
 * of a long record upload and write again.
 */
class BlobCidTest extends TestCase
{
    public function test_it_matches_the_raw_sha256_cid_a_pds_assigns(): void
    {
        // CIDv1, raw codec, sha2-256 of zero bytes: a published reference value.
        $this->assertSame('bafkreihdwdcefgh4dqkjv67uzcmw7ojee6xedzdetojuzjevtenxquvyku', BlobCid::for(''));
    }

    public function test_different_bytes_get_different_addresses(): void
    {
        $this->assertNotSame(BlobCid::for('a'), BlobCid::for('b'));
        $this->assertStringStartsWith('bafkrei', BlobCid::for('a'));
    }
}
