<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpSchema\Data\Data;

/**
 * Test record for unit testing.
 */
class TestRecord extends Data
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $createdAt = null,
    ) {}

    public static function getLexicon(): string
    {
        return 'app.test.record';
    }

    public static function fromArray(array $data): static
    {
        return new static(
            text: $data['text'] ?? '',
            createdAt: $data['createdAt'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'createdAt' => $this->createdAt,
        ], fn ($v) => $v !== null);
    }
}
