<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpSchema\Data\Data;

class TestReferenceRecord extends Data
{
    public function __construct(
        public readonly ?array $subject = null,
        public readonly ?string $document = null,
    ) {}

    public static function getLexicon(): string
    {
        return 'app.test.reference';
    }

    public static function fromArray(array $data): static
    {
        return new static(
            subject: $data['subject'] ?? null,
            document: $data['document'] ?? null,
        );
    }
}
