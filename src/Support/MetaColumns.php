<?php

namespace SocialDept\AtpParity\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Writes the package's protocol metadata columns so the row always ends up holding them.
 *
 * A save decides what to write by comparing the instance with what it last read,
 * so a value equal to the instance's original writes nothing, even when another
 * process has since changed the row. For a CID that is the difference between
 * the stored address matching the repo and the unchanged-write guard comparing
 * against a CID the repo no longer holds. These columns are written with a direct
 * update keyed on the model instead, and the instance is told they are clean.
 *
 * Everything else about the save it replaces is kept: `updated_at` moves exactly
 * when the save would have moved it, no model events fire, and other pending
 * changes on the instance are still saved quietly.
 */
final class MetaColumns
{
    /**
     * @param  array<string, mixed>  $values  column => value
     */
    public static function write(Model $model, array $values): void
    {
        foreach ($values as $column => $value) {
            $model->setAttribute($column, $value);
        }

        // A model that was never saved has no row to update.
        if (! $model->exists) {
            $model->saveQuietly();

            return;
        }

        $columns = array_keys($values);
        $wouldHaveSaved = $model->isDirty($columns);

        $updatedAt = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;

        if ($wouldHaveSaved && $updatedAt !== null && ! $model->isDirty($updatedAt)) {
            $model->setUpdatedAt($model->freshTimestamp());
            $columns[] = $updatedAt;
        }

        // INFO: the base query, not the Eloquent one. Eloquent's update() stamps
        // `updated_at` on every call, which the save this replaces did not.
        $model->newQueryWithoutScopes()
            ->toBase()
            ->where($model->getKeyName(), $model->getKey())
            ->update(Arr::only($model->getAttributes(), $columns));

        $model->syncOriginalAttributes($columns);

        if ($model->isDirty()) {
            $model->saveQuietly();
        }
    }
}
