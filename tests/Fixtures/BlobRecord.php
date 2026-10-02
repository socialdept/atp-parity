<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpSchema\Data\Data;

class BlobRecord extends Data
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?array $payload = null,
    ) {
    }

    public static function getLexicon(): string
    {
        return 'app.test.blobrecord';
    }

    public static function fromArray(array $data): static
    {
        return new static(
            text: $data['text'] ?? null,
            payload: $data['payload'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'payload' => $this->payload,
        ], fn ($v) => $v !== null);
    }
}
