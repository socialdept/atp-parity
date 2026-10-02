<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\RecordMapper;
use SocialDept\AtpSchema\Data\Data;

/**
 * Test mapper for unit testing.
 */
class TestMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return TestRecord::class;
    }

    public function modelClass(): string
    {
        return TestModel::class;
    }

    protected function recordToAttributes(Data $record): array
    {
        return [
            'content' => $record->text,
        ];
    }

    protected function modelToRecordData(Model $model): array
    {
        return [
            'text' => $model->content,
            'createdAt' => $model->created_at?->toIso8601String(),
        ];
    }

    /**
     * A test double standing in for an arbitrary collection, so it accepts freely.
     * A real mapper states a narrower policy.
     */
    public function accepts(): ?Acceptance
    {
        return Acceptance::anything();
    }
}
