<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpParity\Fields\Field;
use SocialDept\AtpParity\RecordMapper;

/**
 * @extends RecordMapper<OverflowRecord, TestModel>
 */
class OverflowMapper extends RecordMapper
{
    public const THRESHOLD = 300;

    public function recordClass(): string
    {
        return OverflowRecord::class;
    }

    public function modelClass(): string
    {
        return TestModel::class;
    }

    public function fields(): array
    {
        return [
            'title' => Field::for('title'),
            'content.items' => Field::for('body')->overflowsToBlob(
                blob: 'content.blob',
                references: 'content.references',
                threshold: self::THRESHOLD,
            ),
        ];
    }
}
