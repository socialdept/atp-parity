<?php

namespace SocialDept\AtpParity\Tests\Unit\Testing;

use PHPUnit\Framework\AssertionFailedError;
use SocialDept\AtpParity\Fields\Field;
use SocialDept\AtpParity\Testing\AssertsRecordParity;
use SocialDept\AtpParity\Tests\Fixtures\AutoSyncMapper;
use SocialDept\AtpParity\Tests\Fixtures\AutoSyncModel;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * The helper has to fail on a mapper that is actually broken, which is the only
 * thing that makes it worth pointing at eight of them.
 */
class AssertsRecordParityTest extends TestCase
{
    use AssertsRecordParity;

    public function test_a_sound_mapper_round_trips(): void
    {
        $model = AutoSyncModel::create(['content' => 'hello']);

        $this->assertRecordParity(new AutoSyncMapper(), $model);
    }

    public function test_it_fails_a_mapper_whose_directions_disagree(): void
    {
        $mapper = new class () extends AutoSyncMapper {
            public function fields(): array
            {
                // Writes the content, reads back something else. The shape of a
                // default applied on one side and forgotten on the other.
                return [
                    'text' => Field::for('content')->using(
                        get: fn () => 'something else',
                        set: fn (AutoSyncModel $m) => $m->content,
                    ),
                ];
            }
        };

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('does not round trip `content`');

        $this->assertRecordRoundTrips($mapper, AutoSyncModel::create(['content' => 'hello']));
    }

    public function test_it_fails_a_mapper_whose_ingest_is_not_a_no_op(): void
    {
        $mapper = new class () extends AutoSyncMapper {
            public function fields(): array
            {
                return ['text' => 'content'];
            }

            // Stamps a changing value on every read, which is the loop in miniature.
            protected function recordToAttributes(\SocialDept\AtpSchema\Data\Data $record): array
            {
                return ['content' => $record->text, 'local_only' => uniqid()];
            }
        };

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not idempotent on ingest');

        $this->assertIngestIsIdempotent($mapper, AutoSyncModel::create(['content' => 'hello']));
    }

    /**
     * A legacy mapper cannot report its columns, so the round trip is unknowable
     * rather than passing. Skipping says so out loud instead of reporting green.
     */
    public function test_it_skips_rather_than_passes_a_mapper_that_writes_its_own_directions(): void
    {
        $model = AutoSyncModel::create(['content' => 'hello']);

        try {
            $this->assertRecordRoundTrips(new TestMapper(), $model);
        } catch (\PHPUnit\Framework\SkippedWithMessageException $e) {
            $this->assertStringContainsString('Declare fields()', $e->getMessage());

            return;
        }

        $this->fail('A mapper with unknowable columns must skip, not pass.');
    }

    public function test_it_refuses_an_unsaved_model(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Pass a saved model');

        $this->assertIngestIsIdempotent(new AutoSyncMapper(), new AutoSyncModel(['content' => 'x']));
    }
}
