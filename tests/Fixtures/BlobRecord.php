<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpSchema\Data\Data;

class BlobRecord extends Data
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $media
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?array $payload = null,
        public readonly ?array $media = null,
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
            media: $data['media'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'payload' => $this->payload,
            'media' => $this->media,
        ], fn ($v) => $v !== null);
    }
}
