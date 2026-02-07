<?php

namespace SocialDept\AtpParity\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trait for Eloquent models with AT Protocol relationships.
 *
 * Provides helpers for defining relationships based on AT Protocol URI references.
 * Common relationship patterns:
 *
 * - reply.parent -> parent_uri column
 * - reply.root -> root_uri column
 * - embed.record (quote) -> quoted_uri column
 * - like.subject -> subject_uri column
 * - follow.subject -> subject_did column
 * - repost.subject -> subject_uri column
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasAtpRelationships
{
    /**
     * Define an AT Protocol relationship via URI reference.
     *
     * This creates a BelongsTo relationship where the foreign key is an AT Protocol URI
     * stored in the specified column, matched against the related model's atp_uri column.
     *
     * Example:
     * ```php
     * public function parent(): BelongsTo
     * {
     *     return $this->atpBelongsTo(Post::class, 'parent_uri');
     * }
     * ```
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $related
     */
    public function atpBelongsTo(string $related, string $uriColumn, ?string $ownerKey = null): BelongsTo
    {
        $ownerKey = $ownerKey ?? config('atp-parity.columns.uri', 'atp_uri');

        // Create a custom BelongsTo that uses URI matching
        return $this->belongsTo($related, $uriColumn, $ownerKey);
    }

    /**
     * Define an inverse AT Protocol relationship via URI reference.
     *
     * This creates a HasMany relationship where related models have a column
     * containing this model's AT Protocol URI.
     *
     * Example:
     * ```php
     * public function replies(): HasMany
     * {
     *     return $this->atpHasMany(Post::class, 'parent_uri');
     * }
     * ```
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $related
     */
    public function atpHasMany(string $related, string $foreignKey, ?string $localKey = null): HasMany
    {
        $localKey = $localKey ?? config('atp-parity.columns.uri', 'atp_uri');

        return $this->hasMany($related, $foreignKey, $localKey);
    }

    /**
     * Define an AT Protocol relationship via DID reference.
     *
     * This creates a BelongsTo relationship where the foreign key is a DID
     * stored in the specified column, matched against a did column on the related model.
     *
     * Example:
     * ```php
     * public function subject(): BelongsTo
     * {
     *     return $this->atpBelongsToByDid(User::class, 'subject_did');
     * }
     * ```
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $related
     */
    public function atpBelongsToByDid(string $related, string $didColumn, string $ownerKey = 'did'): BelongsTo
    {
        return $this->belongsTo($related, $didColumn, $ownerKey);
    }

    /**
     * Define an inverse AT Protocol relationship via DID reference.
     *
     * Example:
     * ```php
     * public function followers(): HasMany
     * {
     *     return $this->atpHasManyByDid(Follow::class, 'subject_did');
     * }
     * ```
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $related
     */
    public function atpHasManyByDid(string $related, string $foreignKey, string $localKey = 'did'): HasMany
    {
        return $this->hasMany($related, $foreignKey, $localKey);
    }

    /**
     * Get a related model by AT Protocol URI.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function findByAtpUri(string $modelClass, ?string $uri)
    {
        if (! $uri) {
            return null;
        }

        $column = config('atp-parity.columns.uri', 'atp_uri');

        return $modelClass::where($column, $uri)->first();
    }
}
