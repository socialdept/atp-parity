<?php

namespace SocialDept\AtpParity\Testing;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpSchema\Data\Data;

/**
 * Assertions a host app points at each of its mappers.
 *
 * Nothing used to check that a mapper's two directions were inverses of each other,
 * which for a translation of any size is the highest-risk pair in a codebase: the
 * halves sit far apart, must agree exactly, and disagree silently.
 *
 * The idempotency assertion is the important one. Our own writes come back as
 * firehose events, and a mapper whose ingest is not a no-op marks the row dirty,
 * which re-syncs, which writes the record, which produces the next event. Finding
 * that by hand took a bespoke test reaching into a protected method.
 */
trait AssertsRecordParity
{
    /**
     * A model survives being turned into a record and read back.
     *
     * Checks only the columns the mapper says feed the record, and skips those it
     * declares lossy, since a rounded colour or a reformatted string is a difference
     * the author accepted rather than a defect. Everything else must come back.
     */
    protected function assertRecordRoundTrips(RecordMapper $mapper, Model $model): void
    {
        $columns = $mapper->recordColumns();

        if ($columns === null) {
            $this->markTestSkipped(
                $mapper::class.' writes its own directions, so its columns are not knowable and a round trip cannot be checked. Declare fields() to get this coverage.'
            );
        }

        $lossy = method_exists($mapper, 'fieldMap') ? $mapper->fieldMap()->lossyColumns() : [];
        $returned = $mapper->toModel($mapper->toRecord($model));

        foreach ($columns as $column) {
            if (in_array($column, $lossy, true)) {
                continue;
            }

            $this->assertEquals(
                $model->getAttribute($column),
                $returned->getAttribute($column),
                sprintf(
                    '%s does not round trip `%s`. The two directions disagree, so a record and a row will differ permanently.',
                    $mapper::class,
                    $column,
                ),
            );
        }
    }

    /**
     * Ingesting a record we wrote changes nothing.
     *
     * The invariant whose violation is a sync loop. The model must already be saved,
     * because a dirty check against an unsaved row is meaningless.
     */
    protected function assertIngestIsIdempotent(RecordMapper $mapper, Model $model, ?Data $record = null): void
    {
        $this->assertTrue($model->exists, 'Pass a saved model: a dirty check against an unsaved row proves nothing.');

        $record ??= $mapper->toRecord($model);

        // The first ingest may legitimately change the row, bringing it in line with
        // the record. The second must not.
        $mapper->updateModel($model, $record);
        $model->save();
        $model->refresh();

        $mapper->updateModel($model, $record);

        $this->assertSame(
            [],
            $model->getDirty(),
            sprintf(
                '%s is not idempotent on ingest: re-reading the same record dirties %s. Our own writes return as firehose events, so this re-syncs, which writes, which produces the next event.',
                $mapper::class,
                implode(', ', array_keys($model->getDirty())),
            ),
        );
    }

    /**
     * Both of the above, which is what a mapper's test usually wants.
     */
    protected function assertRecordParity(RecordMapper $mapper, Model $model): void
    {
        $this->assertRecordRoundTrips($mapper, $model);
        $this->assertIngestIsIdempotent($mapper, $model);
    }
}
