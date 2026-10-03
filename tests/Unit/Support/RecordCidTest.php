<?php

namespace SocialDept\AtpParity\Tests\Unit\Support;

use SocialDept\AtpParity\Support\RecordCid;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * Pins local CID computation against a record read from a live PDS.
 *
 * A sync skips a write when this agrees with the CID it stored last time, so a
 * computation that drifts from what a PDS does takes the guard with it. Drifting
 * towards "never matches" wastes writes. Drifting towards "always matches" means a
 * real edit never leaves the process, which is why this is pinned to a real record
 * and its real CID rather than to our own encoder's output.
 *
 * The record is public: `site.standard.publication/3mjha4g6ro22l` in
 * `did:plc:eob75vcjtmbaef2tn4evc4sl`. It carries a blob, which is the case that
 * makes the JSON-to-dag-cbor conversion load-bearing.
 */
class RecordCidTest extends TestCase
{
    private const REAL_CID = 'bafyreibxr2uddzr24eqkky7brjaxgczl23h23p6gjc5kqd7f3jqaxhn76y';

    /**
     * @return array<string, mixed>
     */
    private function realRecord(): array
    {
        $path = __DIR__.'/../../Fixtures/Records/publication.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_computes_the_cid_a_pds_assigned_to_a_real_record(): void
    {
        $this->assertSame(self::REAL_CID, RecordCid::for($this->realRecord()));
    }

    /**
     * The whole trap in one test: this record carries a blob, so encoding the JSON
     * form verbatim hashes `{"$link": ...}` as a map and silently produces a CID no
     * PDS will ever report.
     */
    public function test_it_encodes_a_blob_link_as_a_native_link_not_a_map(): void
    {
        $record = $this->realRecord();

        $this->assertArrayHasKey('$link', $record['icon']['ref']);

        $withLinkAsPlainMap = $record;
        $withLinkAsPlainMap['icon']['ref'] = ['notALink' => $record['icon']['ref']['$link']];

        $this->assertNotSame(RecordCid::for($record), RecordCid::for($withLinkAsPlainMap));
    }

    /**
     * The `$type` is part of a record's address, so it must be part of what we hash.
     *
     * A PDS adds the top-level `$type` itself before hashing, which is why every
     * other test here agrees with a real CID: they all start from a record read back
     * from a PDS, where it is already present. `Data::toArray()` omits it and
     * `Data::toRecord()` adds it, so a guard built on the former compares a hash no
     * PDS will ever report and skips nothing.
     */
    public function test_the_type_is_part_of_the_hashed_record(): void
    {
        $record = $this->realRecord();

        $this->assertArrayHasKey('$type', $record, 'The fixture must be the record as a PDS stores it.');

        $withoutType = $record;
        unset($withoutType['$type']);

        $this->assertSame(self::REAL_CID, RecordCid::for($record));
        $this->assertNotSame(self::REAL_CID, RecordCid::for($withoutType));
    }

    public function test_it_is_stable_across_calls(): void
    {
        $this->assertSame(RecordCid::for($this->realRecord()), RecordCid::for($this->realRecord()));
    }

    public function test_a_changed_value_changes_the_cid(): void
    {
        $changed = $this->realRecord();
        $changed['name'] = $changed['name'].' ';

        $this->assertNotSame(self::REAL_CID, RecordCid::for($changed));
    }

    /**
     * Key order is not part of a record's identity: dag-cbor sorts map keys, so a
     * mapper that happens to build its array in a different order must not read as
     * a change. Were this false the guard would never fire for any record whose
     * field order differed from the one we last wrote.
     */
    public function test_key_order_does_not_affect_the_cid(): void
    {
        $reversed = array_reverse($this->realRecord(), preserve_keys: true);

        $this->assertSame(self::REAL_CID, RecordCid::for($reversed));
    }

    public function test_it_decodes_bytes_rather_than_hashing_the_base64_text(): void
    {
        $asBytes = ['payload' => ['$bytes' => base64_encode('hello')]];
        $asText = ['payload' => base64_encode('hello')];

        $this->assertNotSame(RecordCid::for($asText), RecordCid::for($asBytes));
    }

    public function test_it_returns_null_rather_than_throwing_when_a_record_cannot_be_addressed(): void
    {
        $this->assertNull(RecordCid::for(['ref' => ['$link' => 'not-a-cid']]));
        $this->assertNull(RecordCid::for(['payload' => ['$bytes' => '!!!not base64!!!']]));
    }
}
