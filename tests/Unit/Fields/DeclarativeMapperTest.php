<?php

namespace SocialDept\AtpParity\Tests\Unit\Fields;

use Carbon\Carbon;
use SocialDept\AtpParity\Fields\Field;
use SocialDept\AtpParity\Tests\Fixtures\DeclarativeMapper;
use SocialDept\AtpParity\Tests\Fixtures\DeclaredRecord;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestMode;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * A mapper declares its fields once and both directions follow.
 *
 * The point is not brevity. Two hand-written directions must agree and nothing
 * checks that they do, so a default written on one side and forgotten on the other
 * makes a record and a row disagree permanently. More importantly, a hand-written
 * direction cannot be asked which columns feed the record, so nothing can decide
 * whether a save is worth a write to someone else's repo.
 */
class DeclarativeMapperTest extends TestCase
{
    private DeclarativeMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new DeclarativeMapper();
    }

    private function record(array $overrides = []): DeclaredRecord
    {
        return DeclaredRecord::fromArray(array_merge([
            'text' => 'hello',
            'nested' => ['label' => 'Greeting', 'mode' => 'fast'],
            'payload' => 'ABC',
            'seenAt' => '2026-01-01T00:00:00Z',
        ], $overrides));
    }

    /**
     * Carbon's toArray() returns date parts, so decoding one as a structure
     * reaches a date cast as an array and throws.
     */
    public function test_a_date_on_a_record_survives_the_import(): void
    {
        $record = new DeclaredRecord(text: 'hello', seenAt: Carbon::parse('2026-05-25T00:00:00Z'));

        $model = $this->mapper->toModel($record);

        $this->assertInstanceOf(\DateTimeInterface::class, $model->seen_at);
    }

    public function test_it_imports_a_plain_column_and_a_nested_path(): void
    {
        $model = $this->mapper->toModel($this->record());

        $this->assertSame('hello', $model->content);
        $this->assertSame('Greeting', $model->label);
    }

    public function test_it_writes_a_nested_path_back_into_the_record(): void
    {
        $model = new TestModel(['content' => 'hello', 'label' => 'Greeting', 'mode' => 'fast']);

        $data = $this->mapper->toRecord($model)->toArray();

        $this->assertSame('Greeting', $data['nested']['label']);
        $this->assertSame('fast', $data['nested']['mode']);
    }

    /**
     * The default is declared once. A hand-written mapper repeats it per direction,
     * which is exactly where the two halves drift.
     */
    public function test_a_default_applies_when_the_record_omits_the_field(): void
    {
        $model = $this->mapper->toModel($this->record(['nested' => ['mode' => 'fast']]));

        $this->assertSame('untitled', $model->label);
    }

    public function test_it_casts_an_enum_in_and_out(): void
    {
        $this->assertSame(TestMode::Fast, $this->mapper->toModel($this->record())->mode);

        $model = new TestModel(['content' => 'x', 'mode' => TestMode::Slow]);

        $this->assertSame('slow', $this->mapper->toRecord($model)->toArray()['nested']['mode']);
    }

    public function test_a_codec_handles_both_directions(): void
    {
        $this->assertSame('cba', $this->mapper->toModel($this->record())->payload);

        $model = new TestModel(['content' => 'x', 'payload' => 'cba']);

        $this->assertSame('abc', $this->mapper->toRecord($model)->toArray()['payload']);
    }

    /**
     * `url` on a real publication record is built from its primary domain and is
     * not a column, so it must be written and never imported. Today that is
     * implicit in whichever direction happens to omit it.
     */
    public function test_a_write_only_field_is_written_and_never_imported(): void
    {
        $model = new TestModel(['content' => 'hello']);

        $this->assertSame('derived:hello', $this->mapper->toRecord($model)->toArray()['derived']);
        $this->assertArrayNotHasKey('derived', $this->mapper->toModel($this->record())->getAttributes());
    }

    public function test_an_import_only_field_is_imported_and_never_written(): void
    {
        $this->assertSame('2026-01-01T00:00:00Z', $this->mapper->toModel($this->record())->seen_at);

        $model = new TestModel(['content' => 'x', 'seen_at' => '2026-01-01T00:00:00Z']);

        $this->assertArrayNotHasKey('seenAt', $this->mapper->toRecord($model)->toArray());
    }

    /**
     * The reason the declaration exists. A save touching none of these cannot
     * change the record, so it is not worth a write to the author's repo.
     */
    public function test_it_reports_which_columns_feed_the_record(): void
    {
        $this->assertSame(
            ['content', 'label', 'mode', 'payload'],
            $this->mapper->recordColumns(),
        );
    }

    public function test_an_import_only_column_is_not_reported_as_feeding_the_record(): void
    {
        $this->assertNotContains('seen_at', $this->mapper->recordColumns());
    }

    public function test_it_reports_lossy_columns_so_a_round_trip_can_tolerate_them(): void
    {
        $this->assertSame(['payload'], $this->mapper->fieldMap()->lossyColumns());
    }

    /**
     * A legacy mapper must not read as "nothing in the record", which would
     * suppress every write. Null means unknowable, so assume it changed.
     */
    public function test_a_mapper_that_writes_its_own_directions_reports_null_columns(): void
    {
        $this->assertNull((new TestMapper())->recordColumns());
    }

    public function test_the_lexicon_attribute_wins_over_the_record_class(): void
    {
        $this->assertSame('app.test.declared', $this->mapper->lexicon());
        $this->assertSame('app.test.record', (new TestMapper())->lexicon());
    }

    /**
     * Declaring nothing and overriding nothing maps nothing, which must fail
     * rather than silently produce an empty record.
     */
    public function test_a_mapper_with_neither_a_declaration_nor_an_override_fails_loudly(): void
    {
        $mapper = new class () extends \SocialDept\AtpParity\RecordMapper {
            public function recordClass(): string
            {
                return DeclaredRecord::class;
            }

            public function modelClass(): string
            {
                return TestModel::class;
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares no fields()');

        $mapper->toRecord(new TestModel(['content' => 'x']));
    }

    public function test_a_field_declared_as_a_bare_string_is_a_plain_column(): void
    {
        $map = new \SocialDept\AtpParity\Fields\FieldMap(['text' => 'content']);

        $this->assertSame(['content'], $map->columns());
        $this->assertSame('hello', $map->toAttributes($this->record())['content']);
    }

    public function test_an_absent_optional_value_is_omitted_rather_than_written_as_null(): void
    {
        $model = new TestModel(['content' => 'hello']);

        $data = $this->mapper->toRecord($model)->toArray();

        $this->assertArrayNotHasKey('payload', $data);
    }

    /**
     * A field is built one of two ways, so it always either names a column or states
     * that it has none. Both directions then chain, and `get()` and `set()` are
     * instance methods rather than constructors that look chainable.
     */
    public function test_a_field_names_a_column_or_declares_it_has_none(): void
    {
        $backed = Field::for('content')
            ->get(fn () => 'in')
            ->set(fn () => 'out');

        $this->assertSame('content', $backed->column);
        $this->assertNotNull($backed->getter);
        $this->assertNotNull($backed->setter);
        $this->assertTrue($backed->isImported());
        $this->assertTrue($backed->isWritten());

        $derived = Field::derived(fn () => 'out');

        $this->assertNull($derived->column);
        $this->assertNull($derived->getter);
        $this->assertNotNull($derived->setter);
        $this->assertFalse($derived->isImported(), 'No column means nothing to import into.');
        $this->assertTrue($derived->isWritten());
    }

    public function test_a_field_cannot_be_constructed_without_saying_which(): void
    {
        $this->assertFalse((new \ReflectionClass(Field::class))->getConstructor()->isPublic());
    }
}
