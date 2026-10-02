<?php

namespace SocialDept\AtpParity\Fields;

use Closure;

/**
 * One field of a record, declared once instead of written twice.
 *
 * Deliberately shaped like {@see \Illuminate\Database\Eloquent\Casts\Attribute}:
 * three static constructors, then chainable refinements. The record is the
 * storage side of that analogy, so `get` reads the record and produces a model
 * attribute, and `set` reads the model and produces a record value.
 *
 * The point is not brevity. A mapper that writes its two directions as separate
 * methods cannot be asked which model columns feed the record, so nothing can
 * decide whether a given save is worth a write. A declaration can be asked.
 *
 * `make()`, `get()` and `set()` are **constructors**, so they do not chain. PHP
 * cannot give a class both a static `get()` and an instance `get()`, and calling a
 * static through `->` silently builds a fresh object, discarding whatever was
 * configured. Use `using()` to add closures to an existing field.
 *
 * Omitting one side is meaningful rather than an oversight:
 *
 * - no `set`: imported, never written. A field we accept but do not author.
 * - no `get`: written, never imported. A value derived from elsewhere, such as a
 *   url built from a domain, that has no column to import into.
 */
class Field
{
    /** The model column this field reads from and writes to, if any. */
    public ?string $column = null;

    public mixed $default = null;

    public bool $hasDefault = false;

    /**
     * The round trip through this field is not an identity, so a round-trip
     * assertion must tolerate the difference rather than discover it.
     */
    public bool $lossy = false;

    /** @var class-string|null */
    public ?string $codec = null;

    /** @var class-string|null */
    public ?string $enum = null;

    public bool $blob = false;

    /** Accepted from a record but never written back. */
    public bool $importOnly = false;

    public function __construct(
        public ?Closure $get = null,
        public ?Closure $set = null,
    ) {
        //
    }

    /**
     * Create a field with both directions.
     */
    public static function make(?callable $get = null, ?callable $set = null): static
    {
        return new static(
            $get === null ? null : Closure::fromCallable($get),
            $set === null ? null : Closure::fromCallable($set),
        );
    }

    /**
     * Create a field that is imported but never written.
     */
    public static function get(callable $get): static
    {
        return new static(Closure::fromCallable($get));
    }

    /**
     * Create a field that is written but never imported.
     */
    public static function set(callable $set): static
    {
        return new static(null, Closure::fromCallable($set));
    }

    /**
     * Create a field that moves a column straight across, in both directions.
     */
    public static function for(string $column): static
    {
        return (new static())->column($column);
    }

    /**
     * Add either direction to a field that already has a column.
     *
     * The chainable counterpart to `make()`. Named differently because PHP cannot
     * overload a static constructor and an instance method on one name, and the
     * alternative, `Field::for('x')->make(...)`, silently throws away the column.
     */
    public function using(?callable $get = null, ?callable $set = null): static
    {
        if ($get !== null) {
            $this->get = Closure::fromCallable($get);
        }

        if ($set !== null) {
            $this->set = Closure::fromCallable($set);
        }

        return $this;
    }

    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    /**
     * The value to use when the record omits this field.
     *
     * Declared once here rather than repeated per direction, which is where the
     * two halves of a hand-written mapper drift apart.
     */
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

    /**
     * A blob field, resolved by the package rather than built by the mapper.
     */
    public function blob(bool $blob = true): static
    {
        $this->blob = $blob;

        return $this;
    }

    public function importOnly(bool $importOnly = true): static
    {
        $this->importOnly = $importOnly;

        return $this;
    }

    /**
     * Whether this field puts a value into the record.
     *
     * A field with no column has nowhere to import into, so it can only be
     * written. One marked `importOnly` is the reverse.
     */
    public function isWritten(): bool
    {
        return ! $this->importOnly && ($this->set !== null || $this->column !== null);
    }

    /**
     * Whether this field takes a value out of a record, and into which column.
     */
    public function isImported(): bool
    {
        return $this->column !== null;
    }
}
