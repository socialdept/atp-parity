<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\Fields\Field;
use SocialDept\AtpParity\RecordMapper;

class BlobMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return BlobRecord::class;
    }

    public function modelClass(): string
    {
        return TestModel::class;
    }

    public function fields(): array
    {
        return [
            'text' => 'content',
            'payload' => Field::for('payload')->blob(),
        ];
    }

    public function accepts(): ?Acceptance
    {
        return Acceptance::anything();
    }
}
