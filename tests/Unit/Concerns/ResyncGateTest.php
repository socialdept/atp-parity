<?php

namespace SocialDept\AtpParity\Tests\Unit\Concerns;

use Mockery;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Sync\SyncResult;
use SocialDept\AtpParity\Sync\SyncService;
use SocialDept\AtpParity\Tests\Fixtures\AutoSyncMapper;
use SocialDept\AtpParity\Tests\Fixtures\AutoSyncModel;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * A save only resyncs when it could have changed the record.
 *
 * Auto-sync fired on every update with no notion of what the record contains, so
 * any column on the row was a trigger, including the many a record never carries.
 * That is how a sync bound to model saves ends up writing identical records into a
 * repo it does not own.
 *
 * The gate is approximate on purpose, answering true whenever the question cannot
 * be settled. A wrong "no" loses an edit; a wrong "yes" costs a write the CID
 * comparison in SyncService then suppresses. The two layers fail in opposite
 * directions so that a mistake in the cheap one cannot reach a PDS.
 */
class ResyncGateTest extends TestCase
{
    private int $resyncs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resyncs = 0;

        $stub = SyncResult::success('at://did:plc:test/app.test.record/abc', 'bafyreiStub');

        $sync = Mockery::mock(SyncService::class);

        // The created hook syncs too, and this test is only about resyncs.
        $sync->shouldReceive('syncAs', 'syncAsWithMapper')->andReturn($stub);

        $sync->shouldReceive('resync')->andReturnUsing(function () use ($stub) {
            $this->resyncs++;

            return $stub;
        });

        $this->app->instance(SyncService::class, $sync);
    }

    private function registerMapper(object $mapper): void
    {
        $registry = new MapperRegistry();
        $registry->register($mapper);
        $this->app->instance(MapperRegistry::class, $registry);
    }

    private function syncedModel(): AutoSyncModel
    {
        $model = AutoSyncModel::create([
            'content' => 'original',
            'local_only' => 'before',
            'did' => 'did:plc:test',
            'atp_uri' => 'at://did:plc:test/app.test.record/abc',
            'atp_cid' => 'bafyreiStub',
        ]);

        $this->resyncs = 0;

        return $model;
    }

    public function test_changing_a_column_the_record_carries_resyncs(): void
    {
        $this->registerMapper(new AutoSyncMapper());

        $model = $this->syncedModel();
        $model->update(['content' => 'changed']);

        $this->assertSame(1, $this->resyncs);
    }

    /**
     * The incident in one assertion. `local_only` is not in the record, so writing
     * it cannot change the record, so it must not reach the author's repo.
     */
    public function test_changing_a_column_the_record_does_not_carry_does_not_resync(): void
    {
        $this->registerMapper(new AutoSyncMapper());

        $model = $this->syncedModel();
        $model->update(['local_only' => 'after']);

        $this->assertSame(0, $this->resyncs);
    }

    public function test_a_save_that_changes_nothing_does_not_resync(): void
    {
        $this->registerMapper(new AutoSyncMapper());

        $model = $this->syncedModel();
        $model->update(['content' => 'original']);

        $this->assertSame(0, $this->resyncs);
    }

    public function test_changing_both_resyncs_once(): void
    {
        $this->registerMapper(new AutoSyncMapper());

        $model = $this->syncedModel();
        $model->update(['content' => 'changed', 'local_only' => 'after']);

        $this->assertSame(1, $this->resyncs);
    }

    /**
     * A mapper that writes its own directions cannot report its columns, and must
     * keep the old behaviour rather than silently stop syncing. Returning an empty
     * list there would read as "nothing in the record" and suppress every write.
     */
    public function test_a_mapper_with_unknowable_columns_still_resyncs_on_any_change(): void
    {
        $mapper = new class () extends TestMapper {
            public function modelClass(): string
            {
                return AutoSyncModel::class;
            }
        };

        $this->assertNull($mapper->recordColumns());

        $this->registerMapper($mapper);

        $model = $this->syncedModel();
        $model->update(['local_only' => 'after']);

        $this->assertSame(1, $this->resyncs);
    }

    public function test_an_unregistered_model_still_resyncs(): void
    {
        $this->registerMapper(new TestMapper());

        $model = $this->syncedModel();
        $model->update(['local_only' => 'after']);

        $this->assertSame(1, $this->resyncs, 'No mapper for this model means the question cannot be settled.');
    }

    /**
     * A declaration whose fields are all derived has no columns to gate on, so
     * gating would block every write. Treated as unknowable for the same reason.
     */
    public function test_a_declaration_with_no_columns_still_resyncs(): void
    {
        $mapper = new class () extends AutoSyncMapper {
            public function fields(): array
            {
                return ['text' => \SocialDept\AtpParity\Fields\Field::set(fn (TestModel|AutoSyncModel $m) => 'derived')];
            }
        };

        $this->assertSame([], $mapper->recordColumns());

        $this->registerMapper($mapper);

        $model = $this->syncedModel();
        $model->update(['local_only' => 'after']);

        $this->assertSame(1, $this->resyncs);
    }
}
