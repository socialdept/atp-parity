<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpSchema\Data\Data;

/**
 * A record whose content object accepts either an inline array or a blob.
 */
class OverflowRecord extends Data
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?object $content = null,
    ) {
    }

    public static function getLexicon(): string
    {
        return 'app.test.overflowrecord';
    }

    public static function fromArray(array $data): static
    {
        return new static(
            title: $data['title'] ?? null,
            content: isset($data['content']) ? (object) $data['content'] : null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'content' => $this->content === null ? null : (array) $this->content,
        ], fn ($v) => $v !== null);
    }
}
