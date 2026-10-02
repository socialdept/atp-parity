<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\Attributes\Lexicon;
use SocialDept\AtpParity\Fields\Field;
use SocialDept\AtpParity\RecordMapper;

/**
 * A mapper that declares its fields instead of writing both directions.
 *
 * Covers the shapes a real mapper needs: a plain column, a nested path, a
 * default, an enum, a codec, a lossy translation, a write-only derived value and
 * an import-only one.
 */
#[Lexicon('app.test.declared')]
class DeclarativeMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return DeclaredRecord::class;
    }

    public function modelClass(): string
    {
        return TestModel::class;
    }

    public function fields(): array
    {
        return [
            'text' => 'content',
            'nested.label' => Field::for('label')->default('untitled'),
            'nested.mode' => Field::for('mode')->enum(TestMode::class),
            'payload' => Field::for('payload')->codec(ReversingCodec::class)->lossy(),
            'derived' => Field::set(fn (TestModel $model) => 'derived:'.$model->content),
            'seenAt' => Field::for('seen_at')->importOnly(),
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
