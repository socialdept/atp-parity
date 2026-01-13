<?php

namespace SocialDept\AtpParity\Tests\Unit\Concerns;

use SocialDept\AtpParity\Tests\Fixtures\ReferenceModel;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;

class HasReferenceRecordTest extends TestCase
{
    public function test_get_reference_uri_returns_column_value(): void
    {
        $model = new ReferenceModel([
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/abc123',
        ]);

        $this->assertSame('at://did:plc:test/app.test.ref/abc123', $model->getReferenceUri());
    }

    public function test_get_reference_uri_returns_null_when_not_set(): void
    {
        $model = new ReferenceModel();

        $this->assertNull($model->getReferenceUri());
    }

    public function test_get_reference_cid_returns_column_value(): void
    {
        $model = new ReferenceModel([
            'atp_reference_cid' => 'bafyrei123',
        ]);

        $this->assertSame('bafyrei123', $model->getReferenceCid());
    }

    public function test_get_reference_ref_returns_strong_ref(): void
    {
        $model = new ReferenceModel([
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/abc123',
            'atp_reference_cid' => 'bafyrei123',
        ]);

        $ref = $model->getReferenceRef();

        $this->assertInstanceOf(StrongRef::class, $ref);
        $this->assertSame('at://did:plc:test/app.test.ref/abc123', $ref->uri);
        $this->assertSame('bafyrei123', $ref->cid);
    }

    public function test_get_reference_ref_returns_null_when_no_uri(): void
    {
        $model = new ReferenceModel();

        $this->assertNull($model->getReferenceRef());
    }

    public function test_get_main_ref_returns_strong_ref_from_atp_columns(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
            'atp_cid' => 'bafyrei456',
        ]);

        $ref = $model->getMainRef();

        $this->assertInstanceOf(StrongRef::class, $ref);
        $this->assertSame('at://did:plc:test/app.test.main/xyz', $ref->uri);
        $this->assertSame('bafyrei456', $ref->cid);
    }

    public function test_has_main_record_returns_true_when_atp_uri_set(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
        ]);

        $this->assertTrue($model->hasMainRecord());
    }

    public function test_has_main_record_returns_false_when_no_uri(): void
    {
        $model = new ReferenceModel();

        $this->assertFalse($model->hasMainRecord());
    }

    public function test_has_reference_record_returns_true_when_reference_uri_set(): void
    {
        $model = new ReferenceModel([
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/abc123',
        ]);

        $this->assertTrue($model->hasReferenceRecord());
    }

    public function test_has_reference_record_returns_false_when_no_uri(): void
    {
        $model = new ReferenceModel();

        $this->assertFalse($model->hasReferenceRecord());
    }

    public function test_is_fully_synced_returns_true_when_both_set(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/abc123',
        ]);

        $this->assertTrue($model->isFullySynced());
    }

    public function test_is_fully_synced_returns_false_when_main_missing(): void
    {
        $model = new ReferenceModel([
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/abc123',
        ]);

        $this->assertFalse($model->isFullySynced());
    }

    public function test_is_fully_synced_returns_false_when_reference_missing(): void
    {
        $model = new ReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
        ]);

        $this->assertFalse($model->isFullySynced());
    }

    public function test_scope_with_reference_record_filters_correctly(): void
    {
        ReferenceModel::create([
            'title' => 'With Reference',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/abc123',
        ]);
        ReferenceModel::create(['title' => 'Without Reference']);

        $withReference = ReferenceModel::withReferenceRecord()->get();

        $this->assertCount(1, $withReference);
        $this->assertSame('With Reference', $withReference->first()->title);
    }

    public function test_scope_without_reference_record_filters_correctly(): void
    {
        ReferenceModel::create([
            'title' => 'With Reference',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/abc123',
        ]);
        ReferenceModel::create(['title' => 'Without Reference']);

        $withoutReference = ReferenceModel::withoutReferenceRecord()->get();

        $this->assertCount(1, $withoutReference);
        $this->assertSame('Without Reference', $withoutReference->first()->title);
    }

    public function test_scope_where_reference_uri_finds_model(): void
    {
        ReferenceModel::create([
            'title' => 'Target',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/target',
        ]);
        ReferenceModel::create([
            'title' => 'Other',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/other',
        ]);

        $found = ReferenceModel::whereReferenceUri('at://did:plc:test/app.test.ref/target')->first();

        $this->assertSame('Target', $found->title);
    }

    public function test_scope_fully_synced_filters_correctly(): void
    {
        ReferenceModel::create([
            'title' => 'Fully Synced',
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/abc',
        ]);
        ReferenceModel::create([
            'title' => 'Main Only',
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz2',
        ]);
        ReferenceModel::create([
            'title' => 'Reference Only',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/abc2',
        ]);

        $fullySynced = ReferenceModel::fullySynced()->get();

        $this->assertCount(1, $fullySynced);
        $this->assertSame('Fully Synced', $fullySynced->first()->title);
    }

    public function test_get_desired_atp_reference_rkey_returns_null_by_default(): void
    {
        $model = new ReferenceModel(['title' => 'Test']);

        $this->assertNull($model->getDesiredAtpReferenceRkey());
    }
}
