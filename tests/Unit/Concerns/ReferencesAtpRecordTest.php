<?php

namespace SocialDept\AtpParity\Tests\Unit\Concerns;

use SocialDept\AtpParity\Tests\Fixtures\MainModel;
use SocialDept\AtpParity\Tests\Fixtures\PivotReferenceModel;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;

class ReferencesAtpRecordTest extends TestCase
{
    public function test_main_model_relationship_returns_related_model(): void
    {
        $main = MainModel::create(['content' => 'Main Content']);
        $pivot = PivotReferenceModel::create(['main_model_id' => $main->id]);

        $relatedMain = $pivot->mainModel;

        $this->assertInstanceOf(MainModel::class, $relatedMain);
        $this->assertSame($main->id, $relatedMain->id);
    }

    public function test_get_main_ref_returns_strong_ref_from_related_model(): void
    {
        $main = MainModel::create([
            'content' => 'Main Content',
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
            'atp_cid' => 'bafyrei123',
        ]);
        $pivot = PivotReferenceModel::create(['main_model_id' => $main->id]);

        $ref = $pivot->getMainRef();

        $this->assertInstanceOf(StrongRef::class, $ref);
        $this->assertSame('at://did:plc:test/app.test.main/xyz', $ref->uri);
        $this->assertSame('bafyrei123', $ref->cid);
    }

    public function test_get_main_ref_returns_null_when_no_main_model(): void
    {
        $pivot = PivotReferenceModel::create(['main_model_id' => null]);

        $this->assertNull($pivot->getMainRef());
    }

    public function test_get_main_ref_returns_null_when_main_not_synced(): void
    {
        $main = MainModel::create(['content' => 'Not Synced']);
        $pivot = PivotReferenceModel::create(['main_model_id' => $main->id]);

        $this->assertNull($pivot->getMainRef());
    }

    public function test_has_main_record_returns_true_when_main_synced(): void
    {
        $main = MainModel::create([
            'content' => 'Synced',
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
        ]);
        $pivot = PivotReferenceModel::create(['main_model_id' => $main->id]);

        $this->assertTrue($pivot->hasMainRecord());
    }

    public function test_has_main_record_returns_false_when_main_not_synced(): void
    {
        $main = MainModel::create(['content' => 'Not Synced']);
        $pivot = PivotReferenceModel::create(['main_model_id' => $main->id]);

        $this->assertFalse($pivot->hasMainRecord());
    }

    public function test_has_reference_record_uses_has_atp_record(): void
    {
        $pivot = new PivotReferenceModel([
            'atp_uri' => 'at://did:plc:test/app.test.ref/abc',
        ]);

        $this->assertTrue($pivot->hasReferenceRecord());

        $pivotWithout = new PivotReferenceModel();

        $this->assertFalse($pivotWithout->hasReferenceRecord());
    }

    public function test_is_fully_synced_checks_both_records(): void
    {
        $main = MainModel::create([
            'content' => 'Synced Main',
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
        ]);
        $pivot = PivotReferenceModel::create([
            'main_model_id' => $main->id,
            'atp_uri' => 'at://did:plc:test/app.test.ref/abc',
        ]);

        $this->assertTrue($pivot->isFullySynced());
    }

    public function test_is_fully_synced_returns_false_when_main_not_synced(): void
    {
        $main = MainModel::create(['content' => 'Not Synced']);
        $pivot = PivotReferenceModel::create([
            'main_model_id' => $main->id,
            'atp_uri' => 'at://did:plc:test/app.test.ref/abc',
        ]);

        $this->assertFalse($pivot->isFullySynced());
    }

    public function test_scope_with_synced_main_filters_correctly(): void
    {
        $syncedMain = MainModel::create([
            'content' => 'Synced',
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
        ]);
        $notSyncedMain = MainModel::create(['content' => 'Not Synced']);

        PivotReferenceModel::create(['main_model_id' => $syncedMain->id]);
        PivotReferenceModel::create(['main_model_id' => $notSyncedMain->id]);

        $withSyncedMain = PivotReferenceModel::withSyncedMain()->get();

        $this->assertCount(1, $withSyncedMain);
        $this->assertSame($syncedMain->id, $withSyncedMain->first()->main_model_id);
    }

    public function test_scope_without_synced_main_filters_correctly(): void
    {
        $syncedMain = MainModel::create([
            'content' => 'Synced',
            'atp_uri' => 'at://did:plc:test/app.test.main/xyz',
        ]);
        $notSyncedMain = MainModel::create(['content' => 'Not Synced']);

        PivotReferenceModel::create(['main_model_id' => $syncedMain->id]);
        PivotReferenceModel::create(['main_model_id' => $notSyncedMain->id]);

        $withoutSyncedMain = PivotReferenceModel::withoutSyncedMain()->get();

        $this->assertCount(1, $withoutSyncedMain);
        $this->assertSame($notSyncedMain->id, $withoutSyncedMain->first()->main_model_id);
    }
}
