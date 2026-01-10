<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\RecordMapper;
use SocialDept\AtpSchema\Data\Data;

/**
 * Test mapper for main lexicon (app.test.main).
 *
 * Maps ReferenceModel to TestMainRecord.
 */
class TestMainMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return TestMainRecord::class;
    }

    public function modelClass(): string
    {
        return ReferenceModel::class;
    }

    protected function recordToAttributes(Data $record): array
    {
        return [
            'title' => $record->title,
        ];
    }

    protected function modelToRecordData(Model $model): array
    {
        return [
            'title' => $model->title ?? 'Untitled',
            'createdAt' => $model->created_at?->toIso8601String(),
        ];
    }
}
