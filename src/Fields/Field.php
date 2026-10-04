<?php

namespace SocialDept\AtpParity\Fields;

use Closure;

/**
 * One field of a record, declared once instead of written twice.
 *
 * The record is the storage side of the analogy, so `get()` reads the record and
 * produces a model attribute, and `set()` reads the model and produces a record
 * value.
 *
 * Built only through {@see self::for()} or {@see self::derived()}, so a field always
 * either names a column or states that it has none.
 */
class Field
{
    public ?string $column = null;

    public ?Closure $getter = null;

    public ?Closure $setter = null;

    public mixed $default = null;

    public bool $hasDefault = false;

    /** Round trips are not an identity here, so parity assertions must allow it. */
    public bool $lossy = false;

    /** @var class-string|null */
    public ?string $codec = null;

    /** @var class-string|null */
    public ?string $enum = null;

    public bool $blob = false;

    public bool $importOnly = false;

    /** Where the blob goes when this field's value overflows, as a record path. */
    public ?string $overflowBlobPath = null;

    /** Where the blobs found inside an overflowed value are re-listed. */
    public ?string $overflowReferencesPath = null;

    /** Encoded record size past which this field overflows, or null for the configured default. */
    public ?int $overflowThreshold = null;

    public string $overflowMimeType = 'text/plain';

    /**
     * Columns a closure reads beyond the one this field writes.
     *
     * @var array<int, string>
     */
    public array $reads = [];

    private function __construct()
    {
        //
    }

    /**
     * A field backed by a model column, in both directions.
     */
    public static function for(string $column): static
    {
        $field = new static();
        $field->column = $column;

        return $field;
    }

    /**
     * A field computed from the model and written to the record, with no column.
     *
     * Nothing to import into, so it is write-only by construction rather than by an
     * absence somewhere else.
     */
    public static function derived(callable $set): static
    {
        return (new static())->set($set);
    }

    /**
     * Record value to model attribute value.
     */
    public function get(callable $get): static
    {
        $this->getter = Closure::fromCallable($get);

        return $this;
    }

    /**
     * Model attribute value to record value.
     */
    public function set(callable $set): static
    {
        $this->setter = Closure::fromCallable($set);

        return $this;
    }

    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function default(mixed $default): static
    {
        $this->default = $default;
        $this->hasDefault = true;

        return $this;
    }

    public function lossy(bool $lossy = true): static
    {
        $this->lossy = $lossy;

        return $this;
    }

    /**
     * @param  class-string  $codec
     */
    public function codec(string $codec): static
    {
        $this->codec = $codec;

        return $this;
    }

    /**
     * @param  class-string  $enum
     */
    public function enum(string $enum): static
    {
        $this->enum = $enum;

        return $this;
    }

    public function blob(bool $blob = true): static
    {
        $this->blob = $blob;

        return $this;
    }

    /**
     * Declare columns a `set` closure reads in addition to this field's own.
     *
     * A closure cannot be introspected, so a field whose outbound direction draws on
     * several columns would otherwise under-report, and a change to one of the
     * others would not be recognised as changing the record.
     */
    public function reads(string ...$columns): static
    {
        $this->reads = [...$this->reads, ...$columns];

        return $this;
    }

    public function importOnly(bool $importOnly = true): static
    {
        $this->importOnly = $importOnly;

        return $this;
    }

    /**
     * Write this field's value to a blob once the encoded record exceeds the threshold.
     *
     * A PDS sweeps blobs no record references, and blobs inside an overflowed
     * value are invisible to it, so `$references` re-lists them.
     *
     * @param  string  $blob  record path the blob is written to
     * @param  string|null  $references  record path the contained blobs are re-listed at
     * @param  int|null  $threshold  encoded bytes past which to overflow, or null for the configured default
     */
    public function overflowsToBlob(
        string $blob,
        ?string $references = null,
        ?int $threshold = null,
        string $mimeType = 'text/plain',
    ): static {
        $this->overflowBlobPath = $blob;
        $this->overflowReferencesPath = $references;
        $this->overflowThreshold = $threshold;
        $this->overflowMimeType = $mimeType;

        return $this;
    }

    public function overflows(): bool
    {
        return $this->overflowBlobPath !== null;
    }

    /** A field with no column has nowhere to import into, so it can only be written. */
    public function isWritten(): bool
    {
        return ! $this->importOnly && ($this->setter !== null || $this->column !== null);
    }

    public function isImported(): bool
    {
        return $this->column !== null;
    }
}
