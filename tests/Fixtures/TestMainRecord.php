<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpSchema\Data\Data;

/**
 * Test record for main lexicon (app.test.main).
 */
class TestMainRecord extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $createdAt = null,
    ) {}

    public static function getLexicon(): string
    {
        return 'app.test.main';
    }

    public static function fromArray(array $data): static
    {
        return new static(
            title: $data['title'] ?? '',
            createdAt: $data['createdAt'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'createdAt' => $this->createdAt,
        ], fn ($v) => $v !== null);
    }
}
