<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Enums\ReferenceFormat;
use SocialDept\AtpParity\ReferenceRecordMapper;
use SocialDept\AtpSchema\Data\Data;

/**
 * Test mapper using AtUri format.
 */
class TestAtUriReferenceMapper extends ReferenceRecordMapper
{
    protected string $referenceProperty = 'document';

    protected ReferenceFormat $referenceFormat = ReferenceFormat::AtUri;

    public function recordClass(): string
    {
        return TestReferenceRecord::class;
    }

    public function modelClass(): string
    {
        return ReferenceModel::class;
    }

    public function mainLexicon(): string
    {
        return 'app.test.main';
    }

    protected function recordToAttributes(Data $record): array
    {
        return [];
    }

    protected function modelToRecordData(Model $model): array
    {
        return [
            'document' => $this->buildReference($model),
        ];
    }
}
