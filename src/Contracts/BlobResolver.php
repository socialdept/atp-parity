<?php

namespace SocialDept\AtpParity\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Turns a model's attached file into a blob reference, uploading it if needed.
 *
 * INFO: the only part of writing a record allowed to perform I/O, and it runs before
 * construction so that asking what we would write stays free of side effects.
 */
interface BlobResolver
{
    /**
     * Null when the model has no such file.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(Model $model, string $field): ?array;
}
