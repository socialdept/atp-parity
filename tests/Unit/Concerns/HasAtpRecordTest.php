<?php

namespace SocialDept\AtpParity\Tests\Unit\Concerns;

use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\Fixtures\TestRecord;
use SocialDept\AtpParity\Tests\TestCase;

class HasAtpRecordTest extends TestCase
{
    public function test_get_atp_uri_returns_uri_from_column(): void
    {
        $model = new TestModel(['atp_uri' => 'at://did:plc:test/app.test.record/abc123']);

        $this->assertSame('at://did:plc:test/app.test.record/abc123', $model->getAtpUri());
    }

    public function test_get_atp_uri_returns_null_when_not_set(): void
    {
        $model = new TestModel();

        $this->assertNull($model->getAtpUri());
    }

    public function test_get_atp_cid_returns_cid_from_column(): void
    {
        $model = new TestModel(['atp_cid' => 'bafyreiabc123']);

        $this->assertSame('bafyreiabc123', $model->getAtpCid());
    }

    public function test_get_atp_cid_returns_null_when_not_set(): void
    {
        $model = new TestModel();

        $this->assertNull($model->getAtpCid());
    }

    public function test_get_atp_did_extracts_did_from_uri(): void
    {
        $model = new TestModel(['atp_uri' => 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/abc123']);

        $this->assertSame('did:plc:z72i7hdynmk6r22z27h6tvur', $model->getAtpDid());
    }

    public function test_get_atp_did_returns_null_when_no_uri(): void
    {
        $model = new TestModel();

        $this->assertNull($model->getAtpDid());
    }

    public function test_get_atp_did_returns_null_for_malformed_uri(): void
    {
        $model = new TestModel(['atp_uri' => 'invalid-uri']);

        $this->assertNull($model->getAtpDid());
    }

    public function test_get_atp_collection_extracts_collection_from_uri(): void
    {
        $model = new TestModel(['atp_uri' => 'at://did:plc:test/app.bsky.feed.post/abc123']);

        $this->assertSame('app.bsky.feed.post', $model->getAtpCollection());
    }

    public function test_get_atp_collection_returns_null_when_no_uri(): void
    {
        $model = new TestModel();

        $this->assertNull($model->getAtpCollection());
    }

    public function test_get_atp_rkey_extracts_rkey_from_uri(): void
    {
        $model = new TestModel(['atp_uri' => 'at://did:plc:test/app.bsky.feed.post/3kj2h4k5j']);

        $this->assertSame('3kj2h4k5j', $model->getAtpRkey());
    }

    public function test_get_atp_rkey_returns_null_when_no_uri(): void
    {
        $model = new TestModel();

        $this->assertNull($model->getAtpRkey());
    }

    public function test_has_atp_record_returns_true_when_uri_set(): void
    {
        $model = new TestModel(['atp_uri' => 'at://did/col/rkey']);

        $this->assertTrue($model->hasAtpRecord());
    }

    public function test_has_atp_record_returns_false_when_no_uri(): void
    {
        $model = new TestModel();

        $this->assertFalse($model->hasAtpRecord());
    }

    public function test_get_atp_mapper_returns_mapper_when_registered(): void
    {
        $registry = app(MapperRegistry::class);
        $registry->register(new TestMapper());

        $model = new TestModel();
        $mapper = $model->getAtpMapper();

        $this->assertInstanceOf(TestMapper::class, $mapper);
    }

    public function test_get_atp_mapper_returns_null_when_not_registered(): void
    {
        // Fresh registry without any mappers
        $this->app->forgetInstance(MapperRegistry::class);
        $this->app->singleton(MapperRegistry::class);

        $model = new TestModel();

        $this->assertNull($model->getAtpMapper());
    }

    public function test_to_atp_record_converts_model_to_record(): void
    {
        $registry = app(MapperRegistry::class);
        $registry->register(new TestMapper());

        $model = new TestModel(['content' => 'Hello world']);
        $record = $model->toAtpRecord();

        $this->assertInstanceOf(TestRecord::class, $record);
        $this->assertSame('Hello world', $record->text);
    }

    public function test_to_atp_record_returns_null_when_no_mapper(): void
    {
        $this->app->forgetInstance(MapperRegistry::class);
        $this->app->singleton(MapperRegistry::class);

        $model = new TestModel(['content' => 'Hello']);

        $this->assertNull($model->toAtpRecord());
    }

    public function test_scope_with_atp_record_filters_synced_models(): void
    {
        TestModel::create(['content' => 'Synced', 'atp_uri' => 'at://did/col/rkey1']);
        TestModel::create(['content' => 'Not synced']);
        TestModel::create(['content' => 'Also synced', 'atp_uri' => 'at://did/col/rkey2']);

        $synced = TestModel::withAtpRecord()->get();

        $this->assertCount(2, $synced);
        $this->assertTrue($synced->every(fn ($m) => $m->atp_uri !== null));
    }

    public function test_scope_without_atp_record_filters_unsynced_models(): void
    {
        TestModel::create(['content' => 'Synced', 'atp_uri' => 'at://did/col/rkey']);
        TestModel::create(['content' => 'Not synced 1']);
        TestModel::create(['content' => 'Not synced 2']);

        $unsynced = TestModel::withoutAtpRecord()->get();

        $this->assertCount(2, $unsynced);
        $this->assertTrue($unsynced->every(fn ($m) => $m->atp_uri === null));
    }

    public function test_scope_where_atp_uri_finds_by_uri(): void
    {
        TestModel::create(['content' => 'Target', 'atp_uri' => 'at://did/col/target']);
        TestModel::create(['content' => 'Other', 'atp_uri' => 'at://did/col/other']);

        $found = TestModel::whereAtpUri('at://did/col/target')->first();

        $this->assertNotNull($found);
        $this->assertSame('Target', $found->content);
    }

    public function test_scope_where_atp_uri_returns_null_when_not_found(): void
    {
        TestModel::create(['content' => 'Some', 'atp_uri' => 'at://did/col/some']);

        $found = TestModel::whereAtpUri('at://did/col/nonexistent')->first();

        $this->assertNull($found);
    }

    public function test_get_desired_atp_rkey_returns_null_by_default(): void
    {
        $model = new TestModel(['content' => 'Test']);

        $this->assertNull($model->getDesiredAtpRkey());
    }
}
