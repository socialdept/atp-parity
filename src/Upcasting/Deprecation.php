<?php

namespace SocialDept\AtpParity\Upcasting;

/**
 * What to do on write about a property a newer shape supersedes.
 *
 * A lexicon cannot drop a property that readers still expect, and it certainly
 * cannot drop a required one, so superseding a field is a transition rather than an
 * edit. Which half of the transition we are in is a decision per field.
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
     * Keep writing the old property, filled from the new one.
     *
     * The right answer while other clients still read it, which for a shared
     * lexicon is a conversation rather than an assumption.
     */
    public static function dualWrite(string $property, string $from): self
    {
        return new self($property, $from, false);
    }

    /**
     * Stop writing the old property.
     *
     * Only once no reader needs it, which is a fact about the network and not about
     * our code.
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
