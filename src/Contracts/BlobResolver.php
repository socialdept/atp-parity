<?php

namespace SocialDept\AtpParity\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Turns a model's attached file into a blob reference, uploading it if needed.
 *
 * A separate step from building the record because where the bytes live is the host
 * app's business, and because resolution is the only part of writing a record that
 * is allowed to perform I/O.
 *
 * Keeping it out of construction is what makes "what would we write" a cheap and
 * side-effect-free question. While an upload happened inside the build, deciding
 * whether a write was necessary meant possibly uploading first, so the check cost
 * more than the write it was trying to avoid.
 */
interface BlobResolver
{
    /**
     * The blob reference for a field, or null when the model has no such file.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(Model $model, string $field): ?array;
}
