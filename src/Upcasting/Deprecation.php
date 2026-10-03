<?php

namespace SocialDept\AtpParity\Upcasting;

/**
 * What to do on write about a property a newer shape supersedes.
 *
 * A lexicon cannot drop a property readers still expect, so superseding one is a
 * transition, and which half of it we are in is a decision per field.
 */
final class Deprecation
{
    private function __construct(
        public readonly string $property,
        public readonly ?string $from,
        public readonly bool $drops,
    ) {
        //
    }

    /**
     * Keep writing the old property, filled from the new one, while other clients
     * still read it.
     */
    public static function dualWrite(string $property, string $from): self
    {
        return new self($property, $from, false);
    }

    /**
     * Stop writing the old property, once no reader needs it.
     */
    public static function drop(string $property): self
    {
        return new self($property, null, true);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function applyTo(array $record): array
    {
        if ($this->drops) {
            unset($record[$this->property]);

            return $record;
        }

        if ($this->from !== null && array_key_exists($this->from, $record)) {
            $record[$this->property] = $record[$this->from];
        }

        return $record;
    }
}
