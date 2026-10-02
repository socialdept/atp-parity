<?php

namespace SocialDept\AtpParity\Tests\Unit\Guards;

use SocialDept\AtpParity\Tests\Fixtures\AutoSyncMapper;
use SocialDept\AtpParity\Tests\Fixtures\GuardedModel;
use SocialDept\AtpParity\Tests\Fixtures\TestRecord;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * A record is untrusted input, so its fields respect the model's mass-assignment
 * rules. The protocol metadata is ours, so it does not.
 *
 * Every other model fixture is `$guarded = []`, which made this whole path invisible:
 * a real `$fillable` silently dropped the uri and the cid. A missing uri makes
 * findByUri() miss, so the next event for the same record inserts a duplicate row
 * instead of updating. A missing cid disables the unchanged-record write guard.
 */
class MassAssignmentTest extends TestCase
{
    private function mapper(): AutoSyncMapper
    {
        return new class () extends AutoSyncMapper {
            public function modelClass(): string
            {
                return GuardedModel::class;
            }
        };
    }

    private function meta(): array
    {
        return ['uri' => 'at://did:plc:x/app.test.record/abc', 'cid' => 'bafyreiStub'];
    }

    public function test_protocol_metadata_reaches_a_model_that_guards_its_attributes(): void
    {
        $model = $this->mapper()->toModel(new TestRecord(text: 'hello'), $this->meta());

        $this->assertSame('at://did:plc:x/app.test.record/abc', $model->atp_uri);
        $this->assertSame('bafyreiStub', $model->atp_cid);
        $this->assertNotNull($model->atp_synced_at);
    }

    public function test_metadata_also_reaches_an_update(): void
    {
        $model = GuardedModel::create(['content' => 'before']);

        $this->mapper()->updateModel($model, new TestRecord(text: 'after'), $this->meta());

        $this->assertSame('at://did:plc:x/app.test.record/abc', $model->atp_uri);
        $this->assertSame('bafyreiStub', $model->atp_cid);
    }

    public function test_metadata_survives_being_saved_and_read_back(): void
    {
        $model = $this->mapper()->toModel(new TestRecord(text: 'hello'), $this->meta());
        $model->save();

        $this->assertSame(
            'at://did:plc:x/app.test.record/abc',
            GuardedModel::query()->whereKey($model->getKey())->value('atp_uri'),
            'A uri that never persists makes the next event insert a duplicate row.',
        );
    }

    /**
     * The other half, and the reason record fields keep going through fill(): a record
     * arrives from any repo on the network, so it must not be able to write a column
     * the app did not open up. `created_at` is the obvious one.
     */
    public function test_a_record_field_cannot_write_a_column_the_model_guards(): void
    {
        $mapper = new class () extends AutoSyncMapper {
            public function modelClass(): string
            {
                return GuardedModel::class;
            }

            public function fields(): array
            {
                return ['text' => 'content', 'createdAt' => 'created_at'];
            }
        };

        $model = $mapper->toModel(
            TestRecord::fromArray(['text' => 'hello', 'createdAt' => '1999-01-01T00:00:00Z']),
            $this->meta(),
        );

        $this->assertSame('hello', $model->content);
        $this->assertNull($model->created_at, 'created_at is not fillable, so a record must not set it.');
    }
}
