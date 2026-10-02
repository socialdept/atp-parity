<?php

namespace SocialDept\AtpParity\Fields;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Contracts\RecordCodec;

/**
 * Applies a mapper's `fields()` declaration in both directions, and owns the
 * dotted-path traversal that a nested field such as `preferences.timezone` needs.
 */
class FieldMap
{
    /**
     * @param  array<string, Field|string>  $fields
     */
    public function __construct(
        protected array $fields,
    ) {
        //
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * The model columns that end up in the record, so a save touching none of them
     * can be known not to need a write.
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        $columns = [];

        foreach ($this->fields as $field) {
            $field = $this->normalise($field);

            if ($field->isWritten() && $field->column !== null) {
                $columns[] = $field->column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return array<int, string>
     */
    public function lossyColumns(): array
    {
        $columns = [];

        foreach ($this->fields as $field) {
            $field = $this->normalise($field);

            if ($field->lossy && $field->column !== null) {
                $columns[] = $field->column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(object $record): array
    {
        $attributes = [];

        foreach ($this->fields as $path => $field) {
            $field = $this->normalise($field);

            if (! $field->isImported()) {
                continue;
            }

            $raw = $this->read($record, $path);

            if ($raw === null && $field->hasDefault) {
                $attributes[$field->column] = $field->default;

                continue;
            }

            $attributes[$field->column] = $this->decode($field, $raw, $record);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRecordData(Model $model): array
    {
        $data = [];

        foreach ($this->fields as $path => $field) {
            $field = $this->normalise($field);

            if (! $field->isWritten() || $field->blob) {
                continue;
            }

            $value = $this->encode($field, $model);

            // INFO: absent, not null. Writing an explicit null fails validation for
            // a field the lexicon declares optional but typed.
            if ($value === null) {
                continue;
            }

            $this->write($data, $path, $value);
        }

        return $data;
    }

    /**
     * @return array<int, string>
     */
    public function blobPaths(): array
    {
        $paths = [];

        foreach ($this->fields as $path => $field) {
            if ($this->normalise($field)->blob) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    protected function decode(Field $field, mixed $raw, object $record): mixed
    {
        if ($field->get !== null) {
            return ($field->get)($record);
        }

        if ($raw === null) {
            return null;
        }

        if ($field->codec !== null) {
            return $this->codec($field)->get($raw);
        }

        if ($field->enum !== null) {
            return $this->toEnum($field->enum, $raw);
        }

        return $this->plain($raw);
    }

    protected function encode(Field $field, Model $model): mixed
    {
        if ($field->set !== null) {
            return ($field->set)($model);
        }

        $value = $model->getAttribute((string) $field->column);

        if ($value === null && $field->hasDefault) {
            $value = $field->default;
        }

        if ($value === null) {
            return null;
        }

        if ($field->codec !== null) {
            return $this->codec($field)->set($value);
        }

        return $this->fromEnum($value);
    }

    protected function codec(Field $field): RecordCodec
    {
        return app((string) $field->codec);
    }

    /**
     * @param  class-string  $enum
     */
    protected function toEnum(string $enum, mixed $raw): mixed
    {
        $value = $this->plain($raw);

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return method_exists($enum, 'tryFrom') ? $enum::tryFrom($value) : $value;
    }

    protected function fromEnum(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * A DTO value becomes an array, so a field with no codec still fits a JSON column.
     */
    protected function plain(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value;
    }


    protected function read(object $record, string $path): mixed
    {
        $current = $record;

        foreach (explode('.', $path) as $segment) {
            if ($current === null) {
                return null;
            }

            if (is_array($current)) {
                $current = $current[$segment] ?? null;

                continue;
            }

            if (! is_object($current)) {
                return null;
            }

            $current = $current->{$segment} ?? null;
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function write(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $cursor = &$data;

        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor[$last] = $value;
    }

    protected function normalise(Field|string $field): Field
    {
        return is_string($field) ? Field::for($field) : $field;
    }
}
