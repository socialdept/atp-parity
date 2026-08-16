<?php

namespace SocialDept\AtpParity\Tests\Unit;

use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\Fixtures\TestRecord;
use SocialDept\AtpParity\Tests\TestCase;

class RecordMapperTest extends TestCase
{
    private TestMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new TestMapper();
    }

    public function test_record_class_returns_correct_class(): void
    {
        $this->assertSame(TestRecord::class, $this->mapper->recordClass());
    }

    public function test_model_class_returns_correct_class(): void
    {
        $this->assertSame(TestModel::class, $this->mapper->modelClass());
    }

    public function test_lexicon_returns_record_lexicon(): void
    {
        $this->assertSame('app.test.record', $this->mapper->lexicon());
    }

    public function test_to_model_creates_model_with_attributes(): void
    {
        $record = new TestRecord(text: 'Hello world');

        $model = $this->mapper->toModel($record);

        $this->assertInstanceOf(TestModel::class, $model);
        $this->assertSame('Hello world', $model->content);
        $this->assertFalse($model->exists);
    }

    public function test_to_model_applies_meta(): void
    {
        $record = new TestRecord(text: 'Test');
        $meta = [
            'uri' => 'at://did:plc:test/app.test.record/abc123',
            'cid' => 'bafyreiabc',
        ];

        $model = $this->mapper->toModel($record, $meta);

        $this->assertSame('at://did:plc:test/app.test.record/abc123', $model->atp_uri);
        $this->assertSame('bafyreiabc', $model->atp_cid);
    }

    public function test_meta_does_not_store_the_rkey_by_default(): void
    {
        $model = $this->mapper->toModel(new TestRecord(text: 'Test'), ['rkey' => 'abc123']);

        // Opt-in: most models have nowhere to put it, and filling a column that
        // does not exist fails the insert.
        $this->assertNull($model->atp_rkey);
    }

    public function test_meta_stores_the_rkey_when_a_column_is_configured(): void
    {
        config()->set('atp-parity.columns.rkey', 'atp_rkey');

        $model = $this->mapper->toModel(new TestRecord(text: 'Test'), ['rkey' => 'abc123']);

        $this->assertSame('abc123', $model->atp_rkey);
    }

    public function test_an_existing_locally_assigned_rkey_is_replaced_by_the_real_one(): void
    {
        config()->set('atp-parity.columns.rkey', 'atp_rkey');

        $model = TestModel::create([
            'content' => 'old',
            'atp_uri' => 'at://did:plc:test/app.test.record/abc123',
            'atp_rkey' => 'locally-generated-tid',
        ]);

        // An app that assigns the rkey at creation holds a value the repo never
        // had. Import is the moment the real one becomes known.
        $this->mapper->updateModel($model, new TestRecord(text: 'new'), [
            'uri' => 'at://did:plc:test/app.test.record/abc123',
            'rkey' => 'abc123',
        ]);

        $this->assertSame('abc123', $model->atp_rkey);
    }

    public function test_to_record_converts_model_to_record(): void
    {
        $model = new TestModel(['content' => 'Test content']);

        $record = $this->mapper->toRecord($model);

        $this->assertInstanceOf(TestRecord::class, $record);
        $this->assertSame('Test content', $record->text);
    }

    public function test_update_model_fills_model_without_saving(): void
    {
        $model = new TestModel(['content' => 'Original']);
        $model->save();
        $originalUpdatedAt = $model->updated_at;

        $record = new TestRecord(text: 'Updated');

        $result = $this->mapper->updateModel($model, $record);

        $this->assertSame($model, $result);
        $this->assertSame('Updated', $model->content);
        // Model is filled but not saved
        $this->assertTrue($model->isDirty('content'));
    }

    public function test_update_model_applies_meta(): void
    {
        $model = new TestModel(['content' => 'Original']);
        $record = new TestRecord(text: 'Updated');
        $meta = ['uri' => 'at://test/col/rkey', 'cid' => 'cid123'];

        $this->mapper->updateModel($model, $record, $meta);

        $this->assertSame('at://test/col/rkey', $model->atp_uri);
        $this->assertSame('cid123', $model->atp_cid);
    }

    public function test_find_by_uri_returns_model_when_exists(): void
    {
        $model = TestModel::create([
            'content' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.record/abc',
        ]);

        $found = $this->mapper->findByUri('at://did:plc:test/app.test.record/abc');

        $this->assertNotNull($found);
        $this->assertSame($model->id, $found->id);
    }

    public function test_find_by_uri_returns_null_when_not_exists(): void
    {
        $found = $this->mapper->findByUri('at://nonexistent/col/rkey');

        $this->assertNull($found);
    }

    public function test_upsert_creates_new_model_when_uri_not_found(): void
    {
        $record = new TestRecord(text: 'New record');
        $meta = [
            'uri' => 'at://did:plc:test/app.test.record/new123',
            'cid' => 'bafyrei123',
        ];

        $model = $this->mapper->upsert($record, $meta);

        $this->assertTrue($model->exists);
        $this->assertSame('New record', $model->content);
        $this->assertSame('at://did:plc:test/app.test.record/new123', $model->atp_uri);
    }

    public function test_upsert_updates_existing_model_when_uri_found(): void
    {
        $existing = TestModel::create([
            'content' => 'Original',
            'atp_uri' => 'at://did:plc:test/app.test.record/exists',
            'atp_cid' => 'old_cid',
        ]);

        $record = new TestRecord(text: 'Updated content');
        $meta = [
            'uri' => 'at://did:plc:test/app.test.record/exists',
            'cid' => 'new_cid',
        ];

        $model = $this->mapper->upsert($record, $meta);

        $this->assertSame($existing->id, $model->id);
        $this->assertSame('Updated content', $model->content);
        $this->assertSame('new_cid', $model->atp_cid);
    }

    public function test_upsert_without_uri_creates_new_model(): void
    {
        $record = new TestRecord(text: 'No URI');

        $model = $this->mapper->upsert($record, []);

        $this->assertTrue($model->exists);
        $this->assertSame('No URI', $model->content);
        $this->assertNull($model->atp_uri);
    }

    public function test_delete_by_uri_deletes_model_when_exists(): void
    {
        TestModel::create([
            'content' => 'To delete',
            'atp_uri' => 'at://did:plc:test/app.test.record/todelete',
        ]);

        $result = $this->mapper->deleteByUri('at://did:plc:test/app.test.record/todelete');

        $this->assertTrue($result);
        $this->assertNull($this->mapper->findByUri('at://did:plc:test/app.test.record/todelete'));
    }

    public function test_delete_by_uri_returns_false_when_not_exists(): void
    {
        $result = $this->mapper->deleteByUri('at://nonexistent/col/rkey');

        $this->assertFalse($result);
    }

    public function test_should_import_returns_true_by_default(): void
    {
        $record = new TestRecord(text: 'Test');
        $meta = ['did' => 'did:plc:test', 'rkey' => 'abc123'];

        $this->assertTrue($this->mapper->shouldImport($record, $meta));
    }

    public function test_upsert_returns_null_when_should_import_returns_false(): void
    {
        $mapper = new class () extends TestMapper {
            public function shouldImport(\SocialDept\AtpSchema\Data\Data $record, array $meta = []): bool
            {
                return false;
            }
        };

        $record = new TestRecord(text: 'Should not import');
        $meta = [
            'uri' => 'at://did:plc:unknown/app.test.record/abc',
            'cid' => 'bafyrei123',
            'did' => 'did:plc:unknown',
            'rkey' => 'abc',
        ];

        $result = $mapper->upsert($record, $meta);

        $this->assertNull($result);
    }

    public function test_upsert_does_not_create_model_when_should_import_returns_false(): void
    {
        $mapper = new class () extends TestMapper {
            public function shouldImport(\SocialDept\AtpSchema\Data\Data $record, array $meta = []): bool
            {
                return false;
            }
        };

        $record = new TestRecord(text: 'Should not import');
        $meta = [
            'uri' => 'at://did:plc:unknown/app.test.record/skip',
            'cid' => 'bafyrei123',
        ];

        $mapper->upsert($record, $meta);

        // Verify no model was created
        $this->assertNull($mapper->findByUri('at://did:plc:unknown/app.test.record/skip'));
    }

    public function test_should_import_receives_meta_with_did_and_rkey(): void
    {
        $receivedMeta = null;

        $mapper = new class ($receivedMeta) extends TestMapper {
            public function __construct(private ?array &$receivedMeta)
            {
            }

            public function shouldImport(\SocialDept\AtpSchema\Data\Data $record, array $meta = []): bool
            {
                $this->receivedMeta = $meta;

                return true;
            }
        };

        $record = new TestRecord(text: 'Test');
        $meta = [
            'uri' => 'at://did:plc:test/app.test.record/xyz',
            'cid' => 'bafyrei456',
            'did' => 'did:plc:test',
            'rkey' => 'xyz',
        ];

        $mapper->upsert($record, $meta);

        $this->assertSame('did:plc:test', $receivedMeta['did']);
        $this->assertSame('xyz', $receivedMeta['rkey']);
    }

    public function test_upsert_proceeds_when_should_import_returns_true(): void
    {
        $mapper = new class () extends TestMapper {
            public function shouldImport(\SocialDept\AtpSchema\Data\Data $record, array $meta = []): bool
            {
                // Only import if did is known
                return ($meta['did'] ?? null) === 'did:plc:known';
            }
        };

        $record = new TestRecord(text: 'Known user record');
        $meta = [
            'uri' => 'at://did:plc:known/app.test.record/allowed',
            'cid' => 'bafyrei789',
            'did' => 'did:plc:known',
            'rkey' => 'allowed',
        ];

        $model = $mapper->upsert($record, $meta);

        $this->assertNotNull($model);
        $this->assertTrue($model->exists);
        $this->assertSame('Known user record', $model->content);
    }

    public function test_after_upsert_runs_on_create_and_reports_it_as_a_create(): void
    {
        $seen = [];
        $mapper = new class ($seen) extends TestMapper {
            public function __construct(public array &$seen)
            {
            }

            protected function afterUpsert(\Illuminate\Database\Eloquent\Model $model, \SocialDept\AtpSchema\Data\Data $record, array $meta, bool $created): void
            {
                $this->seen[] = ['key' => $model->getKey(), 'created' => $created];
            }
        };

        $mapper->upsert(new TestRecord(text: 'Hello'), ['uri' => 'at://did:plc:test/app.test.record/new']);

        // Must run after save, or the model has no key to hang related rows off.
        $this->assertCount(1, $seen);
        $this->assertNotNull($seen[0]['key']);
        $this->assertTrue($seen[0]['created']);
    }

    public function test_after_upsert_reports_an_update_as_not_created(): void
    {
        TestModel::create([
            'content' => 'old',
            'atp_uri' => 'at://did:plc:test/app.test.record/existing',
        ]);

        $seen = [];
        $mapper = new class ($seen) extends TestMapper {
            public function __construct(public array &$seen)
            {
            }

            protected function afterUpsert(\Illuminate\Database\Eloquent\Model $model, \SocialDept\AtpSchema\Data\Data $record, array $meta, bool $created): void
            {
                $this->seen[] = $created;
            }
        };

        $mapper->upsert(new TestRecord(text: 'new'), ['uri' => 'at://did:plc:test/app.test.record/existing']);

        $this->assertSame([false], $seen);
    }

    public function test_after_upsert_does_not_run_when_the_import_is_refused(): void
    {
        $ran = false;
        $mapper = new class ($ran) extends TestMapper {
            public function __construct(public bool &$ran)
            {
            }

            public function shouldImport(\SocialDept\AtpSchema\Data\Data $record, array $meta = []): bool
            {
                return false;
            }

            protected function afterUpsert(\Illuminate\Database\Eloquent\Model $model, \SocialDept\AtpSchema\Data\Data $record, array $meta, bool $created): void
            {
                $this->ran = true;
            }
        };

        $mapper->upsert(new TestRecord(text: 'nope'), ['uri' => 'at://did:plc:test/app.test.record/refused']);

        $this->assertFalse($ran);
    }
}
