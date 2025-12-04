<?php

namespace SocialDept\AtpParity\Tests\Unit;

use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\Fixtures\TestRecord;
use SocialDept\AtpParity\Tests\TestCase;

class MapperRegistryTest extends TestCase
{
    private MapperRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new MapperRegistry();
    }

    public function test_register_adds_mapper_to_all_indices(): void
    {
        $mapper = new TestMapper();

        $this->registry->register($mapper);

        $this->assertSame($mapper, $this->registry->forRecord(TestRecord::class));
        $this->assertSame($mapper, $this->registry->forModel(TestModel::class));
        $this->assertSame($mapper, $this->registry->forLexicon('app.test.record'));
    }

    public function test_for_record_returns_null_for_unregistered_class(): void
    {
        $result = $this->registry->forRecord('NonExistent\\Record');

        $this->assertNull($result);
    }

    public function test_for_model_returns_null_for_unregistered_class(): void
    {
        $result = $this->registry->forModel('NonExistent\\Model');

        $this->assertNull($result);
    }

    public function test_for_lexicon_returns_null_for_unregistered_nsid(): void
    {
        $result = $this->registry->forLexicon('app.unknown.record');

        $this->assertNull($result);
    }

    public function test_has_lexicon_returns_true_when_registered(): void
    {
        $this->registry->register(new TestMapper());

        $this->assertTrue($this->registry->hasLexicon('app.test.record'));
    }

    public function test_has_lexicon_returns_false_when_not_registered(): void
    {
        $this->assertFalse($this->registry->hasLexicon('app.unknown.record'));
    }

    public function test_lexicons_returns_all_registered_nsids(): void
    {
        $this->registry->register(new TestMapper());

        $lexicons = $this->registry->lexicons();

        $this->assertContains('app.test.record', $lexicons);
        $this->assertCount(1, $lexicons);
    }

    public function test_lexicons_returns_empty_array_when_no_mappers_registered(): void
    {
        $this->assertEmpty($this->registry->lexicons());
    }

    public function test_all_returns_all_registered_mappers(): void
    {
        $mapper = new TestMapper();
        $this->registry->register($mapper);

        $all = $this->registry->all();

        $this->assertCount(1, $all);
        $this->assertSame($mapper, $all[0]);
    }

    public function test_all_returns_empty_array_when_no_mappers_registered(): void
    {
        $this->assertEmpty($this->registry->all());
    }

    public function test_registering_same_mapper_twice_overwrites(): void
    {
        $mapper1 = new TestMapper();
        $mapper2 = new TestMapper();

        $this->registry->register($mapper1);
        $this->registry->register($mapper2);

        $this->assertSame($mapper2, $this->registry->forRecord(TestRecord::class));
        $this->assertCount(1, $this->registry->all());
    }
}
