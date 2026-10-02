<?php

namespace SocialDept\AtpParity\Fields;

use Closure;

/**
 * One field of a record, declared once instead of written twice.
 *
 * Shaped like {@see \Illuminate\Database\Eloquent\Casts\Attribute}. The record is
 * the storage side of that analogy, so `get` reads the record and `set` reads the
 * model.
 *
 * FIX: `make()`, `get()` and `set()` are constructors and do not chain. PHP allows
 * calling a static through `->`, which silently builds a fresh object and discards
 * the column, so use `using()` to add closures to an existing field.
 */
class Field
{
    public ?string $column = null;

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

    public function __construct(
        public ?Closure $get = null,
        public ?Closure $set = null,
    ) {
        //
    }

    public static function make(?callable $get = null, ?callable $set = null): static
    {
        return new static(
            $get === null ? null : Closure::fromCallable($get),
            $set === null ? null : Closure::fromCallable($set),
        );
    }

    public static function get(callable $get): static
    {
        return new static(Closure::fromCallable($get));
    }

    public static function set(callable $set): static
    {
        return new static(null, Closure::fromCallable($set));
    }

    public static function for(string $column): static
    {
        return (new static())->column($column);
    }

    /** The chainable counterpart to `make()`, which cannot share its name. */
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

    public function importOnly(bool $importOnly = true): static
    {
        $this->importOnly = $importOnly;

        return $this;
    }

    /** A field with no column has nowhere to import into, so it can only be written. */
    public function isWritten(): bool
    {
        return ! $this->importOnly && ($this->set !== null || $this->column !== null);
    }

    public function isImported(): bool
    {
        return $this->column !== null;
    }
}
