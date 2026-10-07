<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpSchema\Data\Data;

/**
 * Lands content and then "publishes" by saving again, the way an app that keeps
 * revisions does from `afterUpsert()`.
 */
class PublishingMapper extends AutoSyncMapper
{
    protected function applyMeta(array $attributes, array $meta): array
    {
        return $attributes + ['did' => $meta['did'] ?? null];
    }

    protected function afterUpsert(Model $model, Data $record, array $meta, bool $created): void
    {
        $model->content = 'published: '.$record->text;
        $model->save();
    }
}
