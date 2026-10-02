<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpSchema\Data\Data;

class DeclaredRecord extends Data
{
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?object $nested = null,
        public readonly ?string $payload = null,
        public readonly ?string $derived = null,
        public readonly ?string $seenAt = null,
    ) {
    }

    public static function getLexicon(): string
    {
        return 'app.test.declaredrecord';
    }

    public static function fromArray(array $data): static
    {
        return new static(
            text: $data['text'] ?? null,
            nested: isset($data['nested']) ? (object) $data['nested'] : null,
            payload: $data['payload'] ?? null,
            derived: $data['derived'] ?? null,
            seenAt: $data['seenAt'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'nested' => $this->nested === null ? null : (array) $this->nested,
            'payload' => $this->payload,
            'derived' => $this->derived,
            'seenAt' => $this->seenAt,
        ], fn ($v) => $v !== null);
    }
}
