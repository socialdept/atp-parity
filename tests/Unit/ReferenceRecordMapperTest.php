<?php

namespace SocialDept\AtpParity\Tests\Unit;

use InvalidArgumentException;
use SocialDept\AtpParity\Enums\ReferenceFormat;
use SocialDept\AtpParity\Tests\Fixtures\ReferenceModel;
use SocialDept\AtpParity\Tests\Fixtures\TestAtUriReferenceMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestReferenceMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestReferenceRecord;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;

class ReferenceRecordMapperTest extends TestCase
{
    private TestReferenceMapper $strongRefMapper;

    private TestAtUriReferenceMapper $atUriMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strongRefMapper = new TestReferenceMapper();
        $this->atUriMapper = new TestAtUriReferenceMapper();
    }

    public function test_build_strong_ref_returns_uri_and_cid(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
            'atp_cid' => 'bafyrei123',
        ]);

        $ref = $this->strongRefMapper->buildStrongRef($model);

        $this->assertInstanceOf(StrongRef::class, $ref);
        $this->assertSame('at://did:plc:test/app.test.main/xyz', $ref->uri);
        $this->assertSame('bafyrei123', $ref->cid);
    }

    public function test_build_strong_ref_throws_when_uri_missing(): void
    {
        $model = new ReferenceModel([
            'atp_cid' => 'bafyrei123',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('atp_uri');

        $this->strongRefMapper->buildStrongRef($model);
    }

    public function test_build_strong_ref_throws_when_cid_missing(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('atp_cid');

        $this->strongRefMapper->buildStrongRef($model);
    }

    public function test_build_at_uri_ref_returns_uri_string(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
        ]);

        $uri = $this->atUriMapper->buildAtUriRef($model);

        $this->assertSame('at://did:plc:test/app.test.main/xyz', $uri);
    }

    public function test_build_at_uri_ref_throws_when_uri_missing(): void
    {
        $model = new ReferenceModel();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('atp_uri');

        $this->atUriMapper->buildAtUriRef($model);
    }

    public function test_build_reference_returns_array_for_strong_ref_format(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
            'atp_cid' => 'bafyrei123',
        ]);

        $ref = $this->strongRefMapper->buildReference($model);

        $this->assertIsArray($ref);
        $this->assertSame('at://did:plc:test/app.test.main/xyz', $ref['uri']);
        $this->assertSame('bafyrei123', $ref['cid']);
    }

    public function test_build_reference_returns_string_for_at_uri_format(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
        ]);

        $ref = $this->atUriMapper->buildReference($model);

        $this->assertIsString($ref);
        $this->assertSame('at://did:plc:test/app.test.main/xyz', $ref);
    }

    public function test_reference_format_is_strong_ref_for_strong_ref_mapper(): void
    {
        $this->assertSame(ReferenceFormat::StrongRef, $this->strongRefMapper->referenceFormat());
    }

    public function test_reference_format_is_at_uri_for_at_uri_mapper(): void
    {
        $this->assertSame(ReferenceFormat::AtUri, $this->atUriMapper->referenceFormat());
    }

    public function test_reference_property_returns_correct_value(): void
    {
        $this->assertSame('subject', $this->strongRefMapper->referenceProperty());
        $this->assertSame('document', $this->atUriMapper->referenceProperty());
    }

    public function test_reference_uri_column_returns_default(): void
    {
        $this->assertSame('atp_reference_uri', $this->strongRefMapper->referenceUriColumn());
    }

    public function test_reference_cid_column_returns_default(): void
    {
        $this->assertSame('atp_reference_cid', $this->strongRefMapper->referenceCidColumn());
    }

    public function test_main_lexicon_returns_correct_value(): void
    {
        $this->assertSame('app.test.main', $this->strongRefMapper->mainLexicon());
    }

    public function test_extract_reference_from_strong_ref_record(): void
    {
        $record = new TestReferenceRecord(
            subject: ['uri' => 'at://did:plc:test/app.test.main/xyz', 'cid' => 'bafyrei123']
        );

        $ref = $this->strongRefMapper->extractReference($record);

        $this->assertInstanceOf(StrongRef::class, $ref);
        $this->assertSame('at://did:plc:test/app.test.main/xyz', $ref->uri);
        $this->assertSame('bafyrei123', $ref->cid);
    }

    public function test_extract_reference_from_at_uri_record(): void
    {
        $record = new TestReferenceRecord(
            document: 'at://did:plc:test/app.test.main/xyz'
        );

        $ref = $this->atUriMapper->extractReference($record);

        $this->assertInstanceOf(StrongRef::class, $ref);
        $this->assertSame('at://did:plc:test/app.test.main/xyz', $ref->uri);
        $this->assertSame('', $ref->cid);
    }

    public function test_extract_reference_returns_null_when_missing(): void
    {
        $record = new TestReferenceRecord();

        $this->assertNull($this->strongRefMapper->extractReference($record));
        $this->assertNull($this->atUriMapper->extractReference($record));
    }

    public function test_find_by_reference_uri(): void
    {
        ReferenceModel::create([
            'title' => 'Target',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/target',
        ]);

        $found = $this->strongRefMapper->findByReferenceUri('at://did:plc:test/app.test.ref/target');

        $this->assertNotNull($found);
        $this->assertSame('Target', $found->title);
    }

    public function test_find_by_reference_uri_returns_null_when_not_found(): void
    {
        $found = $this->strongRefMapper->findByReferenceUri('at://did:plc:test/app.test.ref/nonexistent');

        $this->assertNull($found);
    }

    public function test_to_record_builds_correct_data(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
            'atp_cid' => 'bafyrei123',
        ]);

        $record = $this->strongRefMapper->toRecord($model);

        $this->assertInstanceOf(TestReferenceRecord::class, $record);
        $data = $record->toArray();
        $this->assertArrayHasKey('subject', $data);
        $this->assertSame('at://did:plc:test/app.test.main/xyz', $data['subject']['uri']);
        $this->assertSame('bafyrei123', $data['subject']['cid']);
    }
}
