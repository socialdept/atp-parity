<?php

namespace SocialDept\AtpParity\Tests\Unit;

use SocialDept\AtpParity\Support\SchemaMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\Fixtures\TestRecord;
use SocialDept\AtpParity\Tests\TestCase;

class SchemaMapperTest extends TestCase
{
    public function test_record_class_returns_schema_class(): void
    {
        $mapper = new SchemaMapper(
            schemaClass: TestRecord::class,
            modelClass: TestModel::class,
            toAttributes: fn () => [],
            toRecordData: fn () => [],
        );

        $this->assertSame(TestRecord::class, $mapper->recordClass());
    }

    public function test_model_class_returns_model_class(): void
    {
        $mapper = new SchemaMapper(
            schemaClass: TestRecord::class,
            modelClass: TestModel::class,
            toAttributes: fn () => [],
            toRecordData: fn () => [],
        );

        $this->assertSame(TestModel::class, $mapper->modelClass());
    }

    public function test_lexicon_returns_schema_lexicon(): void
    {
        $mapper = new SchemaMapper(
            schemaClass: TestRecord::class,
            modelClass: TestModel::class,
            toAttributes: fn () => [],
            toRecordData: fn () => [],
        );

        $this->assertSame('app.test.record', $mapper->lexicon());
    }

    public function test_to_model_invokes_to_attributes_closure(): void
    {
        $closureCalled = false;

        $mapper = new SchemaMapper(
            schemaClass: TestRecord::class,
            modelClass: TestModel::class,
            toAttributes: function (TestRecord $record) use (&$closureCalled) {
                $closureCalled = true;

                return ['content' => $record->text.'_transformed'];
            },
            toRecordData: fn () => [],
        );

        $record = new TestRecord(text: 'original');
        $model = $mapper->toModel($record);

        $this->assertTrue($closureCalled);
        $this->assertSame('original_transformed', $model->content);
    }

    public function test_to_record_invokes_to_record_data_closure(): void
    {
        $closureCalled = false;

        $mapper = new SchemaMapper(
            schemaClass: TestRecord::class,
            modelClass: TestModel::class,
            toAttributes: fn () => [],
            toRecordData: function (TestModel $model) use (&$closureCalled) {
                $closureCalled = true;

                return ['text' => strtoupper($model->content)];
            },
        );

        $model = new TestModel(['content' => 'hello']);
        $record = $mapper->toRecord($model);

        $this->assertTrue($closureCalled);
        $this->assertSame('HELLO', $record->text);
    }

    public function test_closures_receive_correct_types(): void
    {
        $receivedRecordType = null;
        $receivedModelType = null;

        $mapper = new SchemaMapper(
            schemaClass: TestRecord::class,
            modelClass: TestModel::class,
            toAttributes: function ($record) use (&$receivedRecordType) {
                $receivedRecordType = get_class($record);

                return ['content' => $record->text];
            },
            toRecordData: function ($model) use (&$receivedModelType) {
                $receivedModelType = get_class($model);

                return ['text' => $model->content];
            },
        );

        $mapper->toModel(new TestRecord(text: 'test'));
        $mapper->toRecord(new TestModel(['content' => 'test']));

        $this->assertSame(TestRecord::class, $receivedRecordType);
        $this->assertSame(TestModel::class, $receivedModelType);
    }

    public function test_upsert_works_with_schema_mapper(): void
    {
        $mapper = new SchemaMapper(
            schemaClass: TestRecord::class,
            modelClass: TestModel::class,
            toAttributes: fn (TestRecord $r) => ['content' => $r->text],
            toRecordData: fn (TestModel $m) => ['text' => $m->content],
        );

        $record = new TestRecord(text: 'schema mapper test');
        $meta = ['uri' => 'at://test/col/rkey', 'cid' => 'cid123'];

        $model = $mapper->upsert($record, $meta);

        $this->assertTrue($model->exists);
        $this->assertSame('schema mapper test', $model->content);
        $this->assertSame('at://test/col/rkey', $model->atp_uri);
    }
}
